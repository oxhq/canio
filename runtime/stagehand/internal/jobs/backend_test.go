package jobs

import (
	"context"
	"testing"
	"time"
)

func TestLocalBackendRoutesNamedQueues(t *testing.T) {
	backend := newLocalBackend(4)

	if err := backend.Enqueue(context.Background(), "invoices", "job-invoices-1"); err != nil {
		t.Fatalf("Enqueue returned error: %v", err)
	}

	if err := backend.Enqueue(context.Background(), "letters", "job-letters-1"); err != nil {
		t.Fatalf("Enqueue returned error: %v", err)
	}

	delivery, err := backend.Dequeue(context.Background(), []string{"letters"})
	if err != nil {
		t.Fatalf("Dequeue returned error: %v", err)
	}

	if delivery.JobID != "job-letters-1" {
		t.Fatalf("jobID = %q, want job-letters-1", delivery.JobID)
	}

	if depth := backend.Depth(context.Background(), []string{"invoices"}); depth != 1 {
		t.Fatalf("depth = %d, want 1", depth)
	}
}

func TestRedisBackendRoutesNamedQueues(t *testing.T) {
	if !redisAvailable("127.0.0.1:6379") {
		t.Skip("Redis is not available on 127.0.0.1:6379")
	}

	backend, err := newRedisBackend(QueueConfig{
		Backend:      "redis",
		Depth:        8,
		LeaseTimeout: time.Second,
		Redis: RedisConfig{
			Host:         "127.0.0.1",
			Port:         6379,
			QueueKey:     "canio:test:jobs:named:" + time.Now().UTC().Format("150405.000000000"),
			BlockTimeout: time.Second,
		},
	})
	if err != nil {
		t.Fatalf("newRedisBackend returned error: %v", err)
	}
	defer backend.Close()

	if err := backend.Enqueue(context.Background(), "invoices", "job-invoices-1"); err != nil {
		t.Fatalf("Enqueue returned error: %v", err)
	}

	if err := backend.Enqueue(context.Background(), "letters", "job-letters-1"); err != nil {
		t.Fatalf("Enqueue returned error: %v", err)
	}

	delivery, err := backend.Dequeue(context.Background(), []string{"letters"})
	if err != nil {
		t.Fatalf("Dequeue returned error: %v", err)
	}

	if delivery.JobID != "job-letters-1" {
		t.Fatalf("jobID = %q, want job-letters-1", delivery.JobID)
	}

	if depth := backend.Depth(context.Background(), nil); depth != 1 {
		t.Fatalf("depth = %d, want 1", depth)
	}
}
