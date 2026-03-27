package browser

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type testProcess struct {
	id     int
	ctx    context.Context
	cancel context.CancelFunc
	closed atomic.Bool
}

func (p *testProcess) ID() int {
	return p.id
}

func (p *testProcess) Context() context.Context {
	return p.ctx
}

func (p *testProcess) Close() {
	if p.closed.CompareAndSwap(false, true) {
		p.cancel()
	}
}

type testFactory struct {
	mu         sync.Mutex
	starts     []int
	delayStart time.Duration
}

func (f *testFactory) Start(ctx context.Context, id int) (BrowserProcess, error) {
	f.mu.Lock()
	f.starts = append(f.starts, id)
	delay := f.delayStart
	f.mu.Unlock()

	if delay > 0 {
		timer := time.NewTimer(delay)
		defer timer.Stop()

		select {
		case <-timer.C:
		case <-ctx.Done():
			return nil, ctx.Err()
		}
	}

	procCtx, cancel := context.WithCancel(context.Background())
	return &testProcess{id: id, ctx: procCtx, cancel: cancel}, nil
}

func TestPoolWarmAcquireAndRelease(t *testing.T) {
	factory := &testFactory{}
	pool, err := NewPool(factory, Options{MaxBrowsers: 3, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	if err := pool.Warm(context.Background(), 2); err != nil {
		t.Fatalf("Warm returned error: %v", err)
	}

	stats := pool.Stats()
	if stats.ReadyBrowsers != 2 || stats.IdleBrowsers != 2 || stats.BusyBrowsers != 0 || stats.Waiting != 0 {
		t.Fatalf("unexpected stats after warm: %+v", stats)
	}

	lease1, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	if got := lease1.ID(); got != 0 {
		t.Fatalf("expected first lease to use slot 0, got %d", got)
	}

	lease2, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	if got := lease2.ID(); got != 1 {
		t.Fatalf("expected second lease to use slot 1, got %d", got)
	}

	stats = pool.Stats()
	if stats.BusyBrowsers != 2 || stats.IdleBrowsers != 0 {
		t.Fatalf("unexpected stats while leased: %+v", stats)
	}

	if err := lease1.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}
	if err := lease2.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	stats = pool.Stats()
	if stats.IdleBrowsers != 2 || stats.BusyBrowsers != 0 {
		t.Fatalf("unexpected stats after release: %+v", stats)
	}
}

func TestPoolBackpressureAndQueueDepth(t *testing.T) {
	factory := &testFactory{}
	pool, err := NewPool(factory, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	if err := pool.Warm(context.Background(), 1); err != nil {
		t.Fatalf("Warm returned error: %v", err)
	}

	first, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}

	waitCh := make(chan *Lease, 1)
	errCh := make(chan error, 1)
	go func() {
		lease, acquireErr := pool.Acquire(context.Background())
		if acquireErr != nil {
			errCh <- acquireErr
			return
		}
		waitCh <- lease
	}()

	waitForPoolStat(t, pool, func(stats Stats) bool {
		return stats.Waiting == 1
	})

	if _, err := pool.Acquire(context.Background()); !errors.Is(err, ErrQueueFull) {
		t.Fatalf("expected ErrQueueFull, got %v", err)
	}

	if err := first.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	var second *Lease
	select {
	case second = <-waitCh:
	case err := <-errCh:
		t.Fatalf("queued Acquire returned error: %v", err)
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for queued Acquire")
	}

	if second == nil {
		t.Fatal("expected queued lease")
	}

	if err := second.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	stats := pool.Stats()
	if stats.Waiting != 0 || stats.BusyBrowsers != 0 || stats.IdleBrowsers != 1 {
		t.Fatalf("unexpected stats after queue drain: %+v", stats)
	}
}

func TestPoolAcquireHonorsTimeout(t *testing.T) {
	factory := &testFactory{}
	pool, err := NewPool(factory, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	if err := pool.Warm(context.Background(), 1); err != nil {
		t.Fatalf("Warm returned error: %v", err)
	}

	lease, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	defer lease.Release()

	timeoutCtx, cancel := context.WithTimeout(context.Background(), 75*time.Millisecond)
	defer cancel()

	_, err = pool.Acquire(timeoutCtx)
	if err == nil {
		t.Fatal("expected timeout error")
	}
	if !errors.Is(err, context.DeadlineExceeded) && !errors.Is(err, context.Canceled) {
		t.Fatalf("expected deadline-related error, got %v", err)
	}

	waitForPoolStat(t, pool, func(stats Stats) bool {
		return stats.Waiting == 0
	})
}

func TestPoolCloseUnblocksWaitersAndClosesIdleSlots(t *testing.T) {
	factory := &testFactory{}
	pool, err := NewPool(factory, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}

	if err := pool.Warm(context.Background(), 1); err != nil {
		t.Fatalf("Warm returned error: %v", err)
	}

	first, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	process := first.Browser().(*testProcess)

	waitErrCh := make(chan error, 1)
	go func() {
		_, acquireErr := pool.Acquire(context.Background())
		waitErrCh <- acquireErr
	}()

	waitForPoolStat(t, pool, func(stats Stats) bool {
		return stats.Waiting == 1
	})

	if err := pool.Close(); err != nil {
		t.Fatalf("Close returned error: %v", err)
	}

	select {
	case err := <-waitErrCh:
		if !errors.Is(err, ErrPoolClosed) {
			t.Fatalf("expected ErrPoolClosed for waiter, got %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for waiter to unblock on close")
	}

	if err := first.Release(); err != nil {
		t.Fatalf("Release after close returned error: %v", err)
	}

	waitForPoolStat(t, pool, func(stats Stats) bool {
		return stats.ReadyBrowsers == 0 && stats.IdleBrowsers == 0
	})

	stats := pool.Stats()
	if !stats.Closed {
		t.Fatalf("expected pool to be closed, got %+v", stats)
	}
	if !process.closed.Load() {
		t.Fatal("expected busy browser to be closed on release after pool close")
	}
}

func waitForPoolStat(t *testing.T, pool *Pool, predicate func(Stats) bool) {
	t.Helper()

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if predicate(pool.Stats()) {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}

	t.Fatalf("condition not reached before timeout; stats=%+v", pool.Stats())
}
