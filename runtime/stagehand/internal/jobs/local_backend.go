package jobs

import (
	"context"
	"sync"
	"time"
)

type localBackend struct {
	mu         sync.RWMutex
	queues     map[string][]string
	queueLimit int
	nextQueue  int
	closed     bool
}

func newLocalBackend(depth int) *localBackend {
	return &localBackend{
		queues:     map[string][]string{},
		queueLimit: depth,
	}
}

func (b *localBackend) Enqueue(ctx context.Context, queueName string, jobID string) error {
	if err := ctx.Err(); err != nil {
		return err
	}

	queueName = normalizeLogicalQueueName(queueName)

	b.mu.Lock()
	defer b.mu.Unlock()

	if b.closed {
		return context.Canceled
	}

	if len(b.queues[queueName]) >= b.queueLimit {
		return ErrQueueFull
	}

	b.queues[queueName] = append(b.queues[queueName], jobID)
	return nil
}

func (b *localBackend) Dequeue(ctx context.Context, queueNames []string) (Delivery, error) {
	queueNames = collectQueueNames(queueNames)

	for {
		if err := ctx.Err(); err != nil {
			return Delivery{}, err
		}

		b.mu.Lock()
		if b.closed {
			b.mu.Unlock()
			return Delivery{}, context.Canceled
		}

		jobID, queueName, ok := b.dequeueLocked(queueNames)
		b.mu.Unlock()
		if ok {
			return Delivery{
				JobID:     jobID,
				QueueName: queueName,
			}, nil
		}

		timer := time.NewTimer(50 * time.Millisecond)
		select {
		case <-ctx.Done():
			timer.Stop()
			return Delivery{}, ctx.Err()
		case <-timer.C:
		}
	}
}

func (b *localBackend) Ack(context.Context, Delivery) error {
	return nil
}

func (b *localBackend) Heartbeat(context.Context, Delivery) error {
	return nil
}

func (b *localBackend) HeartbeatInterval() time.Duration {
	return 0
}

func (b *localBackend) Depth(_ context.Context, queueNames []string) int {
	b.mu.RLock()
	defer b.mu.RUnlock()

	if len(queueNames) == 0 {
		depth := 0
		for _, jobs := range b.queues {
			depth += len(jobs)
		}

		return depth
	}

	depth := 0
	for _, queueName := range collectQueueNames(queueNames) {
		depth += len(b.queues[queueName])
	}

	return depth
}

func (b *localBackend) Limit() int {
	return b.queueLimit
}

func (b *localBackend) Close() error {
	b.mu.Lock()
	defer b.mu.Unlock()

	b.closed = true
	return nil
}

func (b *localBackend) dequeueLocked(queueNames []string) (string, string, bool) {
	if len(queueNames) == 0 {
		return "", "", false
	}

	start := 0
	if b.nextQueue > 0 {
		start = b.nextQueue % len(queueNames)
	}

	for offset := 0; offset < len(queueNames); offset++ {
		index := (start + offset) % len(queueNames)
		queueName := queueNames[index]
		jobs := b.queues[queueName]
		if len(jobs) == 0 {
			continue
		}

		jobID := jobs[0]
		if len(jobs) == 1 {
			delete(b.queues, queueName)
		} else {
			b.queues[queueName] = jobs[1:]
		}

		b.nextQueue = (index + 1) % len(queueNames)
		return jobID, queueName, true
	}

	return "", "", false
}
