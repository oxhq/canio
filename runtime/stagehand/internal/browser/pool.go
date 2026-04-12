package browser

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"time"
)

var (
	ErrPoolClosed     = errors.New("browser pool closed")
	ErrQueueFull      = errors.New("browser pool queue is full")
	ErrLeaseReleased  = errors.New("browser lease already released")
	ErrInvalidMaxSize = errors.New("browser pool max size must be greater than zero")
)

// BrowserProcess is the minimal contract a browser slot needs to expose to the pool.
// Renderer code can later bind this to a chromedp-backed browser process.
type BrowserProcess interface {
	ID() int
	Context() context.Context
	Close()
}

// Factory creates browser slots for the pool.
type Factory interface {
	Start(ctx context.Context, id int) (BrowserProcess, error)
}

// Options configures pool size and queue behavior.
type Options struct {
	MaxBrowsers int
	QueueDepth  int
}

// Stats is a snapshot of the pool state.
type Stats struct {
	MaxBrowsers      int
	ReadyBrowsers    int
	IdleBrowsers     int
	BusyBrowsers     int
	StartingBrowsers int
	Waiting          int
	QueueLimit       int
	Closed           bool
}

type Pool struct {
	factory Factory
	opts    Options

	mu       sync.Mutex
	notify   chan struct{}
	done     chan struct{}
	closed   bool
	nextID   int
	starting int

	slots   map[int]*slot
	idle    []*slot
	waiting int
}

type slot struct {
	id      int
	process BrowserProcess
	leased  bool
}

// Lease represents a checked-out browser slot.
type Lease struct {
	pool     *Pool
	slot     *slot
	released atomic.Bool
}

func NewPool(factory Factory, opts Options) (*Pool, error) {
	if factory == nil {
		return nil, errors.New("browser pool factory is required")
	}

	if opts.MaxBrowsers <= 0 {
		return nil, ErrInvalidMaxSize
	}

	if opts.QueueDepth < 0 {
		opts.QueueDepth = 0
	}

	return &Pool{
		factory: factory,
		opts:    opts,
		notify:  make(chan struct{}),
		done:    make(chan struct{}),
		slots:   make(map[int]*slot),
	}, nil
}

// Warm preloads up to count browser slots and leaves them idle for later acquires.
func (p *Pool) Warm(ctx context.Context, count int) error {
	if count <= 0 {
		return nil
	}

	for i := 0; i < count; i++ {
		started, _, err := p.startSlot(ctx, false)
		if err != nil {
			return err
		}

		if !started {
			return nil
		}
	}

	return nil
}

// Acquire returns a browser lease, respecting the configured max size and queue depth.
func (p *Pool) Acquire(ctx context.Context) (*Lease, error) {
	for {
		if err := ctx.Err(); err != nil {
			return nil, err
		}

		var staleProcess BrowserProcess
		p.mu.Lock()
		if p.closed {
			p.mu.Unlock()
			return nil, ErrPoolClosed
		}

		if len(p.idle) > 0 {
			slot := p.idle[0]
			p.idle = p.idle[1:]
			if !slotUsable(slot) {
				delete(p.slots, slot.id)
				staleProcess = slot.process
				p.signalLocked()
				p.mu.Unlock()
				if staleProcess != nil {
					staleProcess.Close()
				}
				continue
			}
			slot.leased = true
			p.mu.Unlock()
			return &Lease{pool: p, slot: slot}, nil
		}

		canStart := len(p.slots)+p.starting < p.opts.MaxBrowsers
		p.mu.Unlock()

		if canStart {
			if started, slot, err := p.startSlot(ctx, true); err != nil {
				return nil, err
			} else if started {
				return &Lease{pool: p, slot: slot}, nil
			} else {
				continue
			}
		}

		p.mu.Lock()
		if p.closed {
			p.mu.Unlock()
			return nil, ErrPoolClosed
		}

		if len(p.idle) > 0 {
			slot := p.idle[0]
			p.idle = p.idle[1:]
			if !slotUsable(slot) {
				delete(p.slots, slot.id)
				staleProcess = slot.process
				p.signalLocked()
				p.mu.Unlock()
				if staleProcess != nil {
					staleProcess.Close()
				}
				continue
			}
			slot.leased = true
			p.mu.Unlock()
			return &Lease{pool: p, slot: slot}, nil
		}

		if len(p.slots)+p.starting < p.opts.MaxBrowsers {
			p.mu.Unlock()
			continue
		}

		if p.waiting >= p.opts.QueueDepth {
			p.mu.Unlock()
			return nil, ErrQueueFull
		}

		waitOn := p.notify
		p.waiting++
		p.mu.Unlock()

		select {
		case <-waitOn:
			p.mu.Lock()
			p.waiting--
			p.mu.Unlock()
			continue
		case <-p.done:
			p.mu.Lock()
			p.waiting--
			p.mu.Unlock()
			return nil, ErrPoolClosed
		case <-ctx.Done():
			p.mu.Lock()
			p.waiting--
			p.mu.Unlock()
			return nil, ctx.Err()
		}
	}
}

// AcquireWithTimeout wraps Acquire with a timeout on the provided context.
func (p *Pool) AcquireWithTimeout(ctx context.Context, timeout time.Duration) (*Lease, error) {
	if timeout <= 0 {
		return p.Acquire(ctx)
	}

	timeoutCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	return p.Acquire(timeoutCtx)
}

// Stats returns a point-in-time view of the pool.
func (p *Pool) Stats() Stats {
	p.mu.Lock()
	defer p.mu.Unlock()

	ready := len(p.slots)
	idle := len(p.idle)

	return Stats{
		MaxBrowsers:      p.opts.MaxBrowsers,
		ReadyBrowsers:    ready,
		IdleBrowsers:     idle,
		BusyBrowsers:     ready - idle,
		StartingBrowsers: p.starting,
		Waiting:          p.waiting,
		QueueLimit:       p.opts.QueueDepth,
		Closed:           p.closed,
	}
}

// Close closes idle browsers immediately and unblocks any waiting acquires.
// Busy leases keep their browser process alive until they are released.
func (p *Pool) Close() error {
	p.mu.Lock()
	if p.closed {
		p.mu.Unlock()
		return nil
	}

	p.closed = true
	idle := append([]*slot(nil), p.idle...)
	p.idle = nil
	for _, s := range idle {
		delete(p.slots, s.id)
	}
	p.signalLocked()
	close(p.done)
	p.mu.Unlock()

	for _, s := range idle {
		s.process.Close()
	}

	return nil
}

func (p *Pool) startSlot(ctx context.Context, leased bool) (bool, *slot, error) {
	p.mu.Lock()
	started, id := p.reserveStartLocked()
	p.mu.Unlock()

	if !started {
		return false, nil, nil
	}

	process, err := p.factory.Start(ctx, id)
	p.mu.Lock()
	p.starting--

	if err != nil {
		p.signalLocked()
		p.mu.Unlock()
		return true, nil, err
	}

	if p.closed {
		p.signalLocked()
		p.mu.Unlock()
		process.Close()
		return true, nil, ErrPoolClosed
	}

	s := &slot{
		id:      id,
		process: process,
		leased:  leased,
	}
	p.slots[id] = s
	if !leased {
		p.idle = append(p.idle, s)
	}
	p.signalLocked()
	p.mu.Unlock()
	return true, s, nil
}

func (p *Pool) reserveStartLocked() (bool, int) {
	if p.closed {
		return false, 0
	}

	if len(p.slots)+p.starting >= p.opts.MaxBrowsers {
		return false, 0
	}

	id := p.nextID
	p.nextID++
	p.starting++
	return true, id
}

func (p *Pool) signalLocked() {
	next := make(chan struct{})
	close(p.notify)
	p.notify = next
}

func (p *Pool) release(slot *slot) error {
	var staleProcess BrowserProcess

	p.mu.Lock()
	current, ok := p.slots[slot.id]
	if !ok {
		p.mu.Unlock()
		return nil
	}

	if current.leased {
		current.leased = false
	}

	if p.closed {
		delete(p.slots, slot.id)
		staleProcess = current.process
		p.signalLocked()
		p.mu.Unlock()
		if staleProcess != nil {
			staleProcess.Close()
		}
		return nil
	}

	if !slotUsable(current) {
		delete(p.slots, slot.id)
		staleProcess = current.process
		p.signalLocked()
		p.mu.Unlock()
		if staleProcess != nil {
			staleProcess.Close()
		}
		return nil
	}

	p.idle = append(p.idle, current)
	p.signalLocked()
	p.mu.Unlock()
	return nil
}

func slotUsable(slot *slot) bool {
	if slot == nil || slot.process == nil {
		return false
	}

	processCtx := slot.process.Context()
	return processCtx != nil && processCtx.Err() == nil
}

// Browser returns the underlying process for the lease.
func (l *Lease) Browser() BrowserProcess {
	if l == nil {
		return nil
	}

	return l.slot.process
}

// ID returns the browser slot identifier.
func (l *Lease) ID() int {
	if l == nil || l.slot == nil {
		return 0
	}

	return l.slot.id
}

// Release returns the slot back to the pool.
func (l *Lease) Release() error {
	if l == nil || l.pool == nil || l.slot == nil {
		return nil
	}

	if !l.released.CompareAndSwap(false, true) {
		return ErrLeaseReleased
	}

	return l.pool.release(l.slot)
}
