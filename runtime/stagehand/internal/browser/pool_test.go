package browser

import (
	"context"
	"errors"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
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
	bindParent bool
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

	parent := context.Background()
	if f.bindParent {
		parent = ctx
	}

	procCtx, cancel := context.WithCancel(parent)
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

func TestPoolAcquireWithTimeoutDoesNotCancelFreshLease(t *testing.T) {
	factory := &testFactory{bindParent: true}
	pool, err := NewPool(factory, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	lease, err := pool.AcquireWithTimeout(context.Background(), 100*time.Millisecond)
	if err != nil {
		t.Fatalf("AcquireWithTimeout returned error: %v", err)
	}
	defer lease.Release()

	process := lease.Browser().(*testProcess)

	select {
	case <-process.Context().Done():
		t.Fatal("expected lease browser context to remain alive after AcquireWithTimeout returns")
	case <-time.After(50 * time.Millisecond):
	}
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

func TestPoolAcquireEvictsCancelledIdleSlots(t *testing.T) {
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

	process := first.Browser().(*testProcess)
	if err := first.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	process.cancel()

	second, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	defer second.Release()

	if got := second.ID(); got != 1 {
		t.Fatalf("expected cancelled idle slot to be evicted and replaced, got slot %d", got)
	}

	stats := pool.Stats()
	if stats.ReadyBrowsers != 1 || stats.IdleBrowsers != 0 || stats.BusyBrowsers != 1 {
		t.Fatalf("unexpected stats after evicting cancelled idle slot: %+v", stats)
	}
}

func TestPoolReleaseEvictsCancelledLeases(t *testing.T) {
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

	process := first.Browser().(*testProcess)
	process.cancel()

	if err := first.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	stats := pool.Stats()
	if stats.ReadyBrowsers != 0 || stats.IdleBrowsers != 0 || stats.BusyBrowsers != 0 {
		t.Fatalf("expected cancelled lease to be evicted from pool, got %+v", stats)
	}

	second, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	defer second.Release()

	if got := second.ID(); got != 1 {
		t.Fatalf("expected cancelled lease to force a replacement slot, got %d", got)
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

func TestPoolRodCDPAcquireReleaseAndReacquire(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := testRuntimeConfig()
	cfg.RendererDriver = rendererDriverRodCDP

	pool, err := NewPool(processFactory{config: cfg}, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	first, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}

	firstID := first.ID()
	if err := first.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	second, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	defer second.Release()

	if second.ID() != firstID {
		t.Fatalf("expected pooled rod-cdp slot reuse, got first=%d second=%d", firstID, second.ID())
	}
}

func TestPoolRodCDPEvictsClosedLeaseOnRelease(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := config.Default()
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}
	cfg.RendererDriver = rendererDriverRodCDP

	pool, err := NewPool(processFactory{config: cfg}, Options{MaxBrowsers: 1, QueueDepth: 1})
	if err != nil {
		t.Fatalf("NewPool returned error: %v", err)
	}
	defer pool.Close()

	lease, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}

	firstID := lease.ID()
	lease.Browser().Close()

	if err := lease.Release(); err != nil {
		t.Fatalf("Release returned error: %v", err)
	}

	next, err := pool.Acquire(context.Background())
	if err != nil {
		t.Fatalf("Acquire returned error: %v", err)
	}
	defer next.Release()

	if next.ID() == firstID {
		t.Fatalf("expected closed rod-cdp lease to be evicted, got slot id reuse %d", firstID)
	}
}
