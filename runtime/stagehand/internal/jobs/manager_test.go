package jobs

import (
	"context"
	"errors"
	"net"
	"os"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
)

func TestManagerSubmitAndCompleteJob(t *testing.T) {
	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "render-" + spec.RequestID,
			Status:          "completed",
			PDF: contracts.RenderedPDF{
				Base64:      "ZmFrZQ==",
				ContentType: "application/pdf",
				FileName:    "document.pdf",
				Bytes:       4,
			},
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-submit-1",
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	if job.Status != "queued" {
		t.Fatalf("initial status = %q, want queued", job.Status)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Result == nil || completed.Result.RequestID != "job-submit-1" {
		t.Fatalf("expected completed job result, got %#v", completed.Result)
	}
}

func TestManagerReturnsQueueFullWhenBufferedJobsOverflow(t *testing.T) {
	blocker := make(chan struct{})
	manager, err := NewManager(Config{
		Workers: 1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   1,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		select {
		case <-blocker:
		case <-ctx.Done():
			return contracts.RenderResult{}, ctx.Err()
		}

		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "render-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()
	defer close(blocker)

	if _, err := manager.Submit(context.Background(), contracts.RenderSpec{RequestID: "job-1"}); err != nil {
		t.Fatalf("first Submit returned error: %v", err)
	}

	waitForStat(t, manager, func(stats Stats) bool {
		return stats.BusyWorkers == 1
	})

	if _, err := manager.Submit(context.Background(), contracts.RenderSpec{RequestID: "job-2"}); err != nil {
		t.Fatalf("second Submit returned error: %v", err)
	}

	if _, err := manager.Submit(context.Background(), contracts.RenderSpec{RequestID: "job-3"}); !errors.Is(err, ErrQueueFull) {
		t.Fatalf("expected ErrQueueFull, got %v", err)
	}
}

func TestManagerMarksStaleJobsAsFailedOnRestart(t *testing.T) {
	stateDir := t.TempDir()

	store := NewStore(stateDir)
	record := Record{
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-stale-1",
			RequestID:       "req-stale-1",
			Status:          "running",
			Attempts:        1,
			SubmittedAt:     time.Now().UTC().Add(-time.Minute).Format(time.RFC3339Nano),
			StartedAt:       time.Now().UTC().Add(-time.Second).Format(time.RFC3339Nano),
		},
		Spec: contracts.RenderSpec{
			ContractVersion: contracts.RenderSpecContractVersion,
			RequestID:       "req-stale-1",
		},
	}

	if err := store.Save(record); err != nil {
		t.Fatalf("Save returned error: %v", err)
	}

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Get("job-stale-1")
	if err != nil {
		t.Fatalf("Get returned error: %v", err)
	}

	if job.Status != "failed" {
		t.Fatalf("status = %q, want failed", job.Status)
	}

	if job.Error == "" {
		t.Fatal("expected stale job to include interruption error")
	}
}

func TestManagerRedisBackendSubmitAndCompleteJob(t *testing.T) {
	if !redisAvailable("127.0.0.1:6379") {
		t.Skip("Redis is not available on 127.0.0.1:6379")
	}

	cfg := config.Default()
	cfg.StateDir = t.TempDir()
	cfg.JobBackend = "redis"
	cfg.JobWorkerCount = 1
	cfg.JobQueueDepth = 8
	cfg.RedisQueueKey = "canio:test:jobs:" + time.Now().UTC().Format("150405.000000000")

	manager, err := NewManager(ConfigFromRuntime(cfg), func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "redis-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-redis-1",
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Result == nil || completed.Result.JobID != "redis-job-redis-1" {
		t.Fatalf("expected completed redis-backed result, got %#v", completed.Result)
	}
}

func TestManagerRedisBackendReclaimsInterruptedDelivery(t *testing.T) {
	if !redisAvailable("127.0.0.1:6379") {
		t.Skip("Redis is not available on 127.0.0.1:6379")
	}

	cfg := config.Default()
	cfg.StateDir = t.TempDir()
	cfg.JobBackend = "redis"
	cfg.JobWorkerCount = 1
	cfg.JobQueueDepth = 8
	cfg.JobLeaseTimeoutSec = 1
	cfg.JobHeartbeatSec = 1
	cfg.RedisQueueKey = "canio:test:jobs:reclaim:" + time.Now().UTC().Format("150405.000000000")

	record := Record{
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-redis-reclaim-1",
			RequestID:       "req-redis-reclaim-1",
			Status:          "running",
			Attempts:        1,
			SubmittedAt:     time.Now().UTC().Add(-2 * time.Second).Format(time.RFC3339Nano),
			StartedAt:       time.Now().UTC().Add(-2 * time.Second).Format(time.RFC3339Nano),
		},
		Spec: contracts.RenderSpec{
			ContractVersion: contracts.RenderSpecContractVersion,
			RequestID:       "req-redis-reclaim-1",
			Queue: map[string]any{
				"connection": "redis",
				"queue":      "pdfs",
			},
		},
	}

	store := NewStore(cfg.StateDir)
	if err := store.Save(record); err != nil {
		t.Fatalf("Save returned error: %v", err)
	}

	backend, err := newRedisBackend(ConfigFromRuntime(cfg).Queue)
	if err != nil {
		t.Fatalf("newRedisBackend returned error: %v", err)
	}

	if err := backend.Enqueue(context.Background(), "pdfs", record.Job.ID); err != nil {
		_ = backend.Close()
		t.Fatalf("Enqueue returned error: %v", err)
	}

	delivery, err := backend.Dequeue(context.Background(), []string{"pdfs"})
	if err != nil {
		_ = backend.Close()
		t.Fatalf("Dequeue returned error: %v", err)
	}

	if delivery.JobID != record.Job.ID {
		_ = backend.Close()
		t.Fatalf("delivery jobID = %q, want %q", delivery.JobID, record.Job.ID)
	}

	_ = backend.Close()
	time.Sleep(1100 * time.Millisecond)

	manager, err := NewManager(ConfigFromRuntime(cfg), func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "reclaimed-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	completed := waitForJob(t, manager, record.Job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Attempts < 2 {
		t.Fatalf("expected reclaimed job attempts >= 2, got %d", completed.Attempts)
	}

	if completed.Result == nil || completed.Result.JobID != "reclaimed-req-redis-reclaim-1" {
		t.Fatalf("expected reclaimed result, got %#v", completed.Result)
	}
}

func TestManagerRetriesThenCompletes(t *testing.T) {
	var attempts atomic.Int32

	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		if attempts.Add(1) == 1 {
			return contracts.RenderResult{}, errors.New("temporary failure")
		}

		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "retry-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-retry-success-1",
		Execution: map[string]any{
			"retries":      1,
			"retryBackoff": 1,
		},
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Attempts != 2 {
		t.Fatalf("attempts = %d, want 2", completed.Attempts)
	}

	if completed.NextRetryAt != "" {
		t.Fatalf("expected nextRetryAt to be cleared, got %q", completed.NextRetryAt)
	}
}

func TestManagerDeadLettersAfterRetriesExhausted(t *testing.T) {
	stateDir := t.TempDir()

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{}, errors.New("permanent failure")
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-dead-letter-1",
		Execution: map[string]any{
			"retries":      1,
			"retryBackoff": 1,
		},
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	failed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "failed" && current.DeadLetter != nil
	})

	if failed.Attempts != 2 {
		t.Fatalf("attempts = %d, want 2", failed.Attempts)
	}

	if failed.DeadLetter == nil {
		t.Fatal("expected dead-letter bundle to be attached to failed job")
	}

	for _, path := range failed.DeadLetter.Files {
		if _, err := os.Stat(path); err != nil {
			t.Fatalf("expected dead-letter file %s to exist: %v", path, err)
		}
	}
}

func TestManagerSchedulesPersistedQueuedRetryOnBoot(t *testing.T) {
	stateDir := t.TempDir()
	store := NewStore(stateDir)

	record := Record{
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-persisted-retry-1",
			RequestID:       "req-persisted-retry-1",
			Status:          "queued",
			Attempts:        1,
			MaxRetries:      2,
			SubmittedAt:     time.Now().UTC().Add(-2 * time.Second).Format(time.RFC3339Nano),
			NextRetryAt:     time.Now().UTC().Add(100 * time.Millisecond).Format(time.RFC3339Nano),
		},
		Spec: contracts.RenderSpec{
			ContractVersion: contracts.RenderSpecContractVersion,
			RequestID:       "req-persisted-retry-1",
			Execution: map[string]any{
				"retries": 2,
			},
		},
	}

	if err := store.Save(record); err != nil {
		t.Fatalf("Save returned error: %v", err)
	}

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "boot-retry-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	completed := waitForJob(t, manager, record.Job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Attempts != 2 {
		t.Fatalf("attempts = %d, want 2", completed.Attempts)
	}
}

func TestManagerListsDeadLetters(t *testing.T) {
	stateDir := t.TempDir()
	store := NewStore(stateDir)

	newer := deadLetterRecord("job-dead-letter-list-new", "req-dead-letter-list-new", time.Now().UTC().Add(-time.Hour))
	older := deadLetterRecord("job-dead-letter-list-old", "req-dead-letter-list-old", time.Now().UTC().Add(-2*time.Hour))

	if _, err := store.SaveDeadLetter(newer, newer.Job.Error); err != nil {
		t.Fatalf("SaveDeadLetter newer returned error: %v", err)
	}

	if _, err := store.SaveDeadLetter(older, older.Job.Error); err != nil {
		t.Fatalf("SaveDeadLetter older returned error: %v", err)
	}

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	list, err := manager.DeadLetters()
	if err != nil {
		t.Fatalf("DeadLetters returned error: %v", err)
	}

	if list.Count != 2 {
		t.Fatalf("count = %d, want 2", list.Count)
	}

	if len(list.Items) != 2 {
		t.Fatalf("items length = %d, want 2", len(list.Items))
	}

	if list.Items[0].JobID != newer.Job.ID {
		t.Fatalf("first dead-letter jobID = %q, want %q", list.Items[0].JobID, newer.Job.ID)
	}
}

func TestManagerRequeuesDeadLetter(t *testing.T) {
	stateDir := t.TempDir()
	store := NewStore(stateDir)
	record := deadLetterRecord("job-dead-letter-requeue", "req-dead-letter-requeue", time.Now().UTC().Add(-time.Minute))

	if _, err := store.SaveDeadLetter(record, record.Job.Error); err != nil {
		t.Fatalf("SaveDeadLetter returned error: %v", err)
	}

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "requeued-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.RequeueDeadLetter(context.Background(), "dlq-"+record.Job.ID)
	if err != nil {
		t.Fatalf("RequeueDeadLetter returned error: %v", err)
	}

	if job.RequestID != record.Job.RequestID {
		t.Fatalf("requestID = %q, want %q", job.RequestID, record.Job.RequestID)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})

	if completed.Result == nil || completed.Result.JobID != "requeued-"+record.Job.RequestID {
		t.Fatalf("expected requeued result, got %#v", completed.Result)
	}
}

func TestManagerCleansUpDeadLetters(t *testing.T) {
	stateDir := t.TempDir()
	store := NewStore(stateDir)

	oldRecord := deadLetterRecord("job-dead-letter-clean-old", "req-dead-letter-clean-old", time.Now().UTC().Add(-72*time.Hour))
	newRecord := deadLetterRecord("job-dead-letter-clean-new", "req-dead-letter-clean-new", time.Now().UTC().Add(-2*time.Hour))

	if _, err := store.SaveDeadLetter(oldRecord, oldRecord.Job.Error); err != nil {
		t.Fatalf("SaveDeadLetter old returned error: %v", err)
	}

	if _, err := store.SaveDeadLetter(newRecord, newRecord.Job.Error); err != nil {
		t.Fatalf("SaveDeadLetter new returned error: %v", err)
	}

	manager, err := NewManager(Config{
		StateDir: stateDir,
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	cleanup, err := manager.CleanupDeadLetters(24 * time.Hour)
	if err != nil {
		t.Fatalf("CleanupDeadLetters returned error: %v", err)
	}

	if cleanup.Count != 1 {
		t.Fatalf("cleanup count = %d, want 1", cleanup.Count)
	}

	if len(cleanup.Removed) != 1 || cleanup.Removed[0].JobID != oldRecord.Job.ID {
		t.Fatalf("cleanup removed = %#v, want job %q", cleanup.Removed, oldRecord.Job.ID)
	}

	list, err := manager.DeadLetters()
	if err != nil {
		t.Fatalf("DeadLetters returned error: %v", err)
	}

	if list.Count != 1 || len(list.Items) != 1 || list.Items[0].JobID != newRecord.Job.ID {
		t.Fatalf("remaining dead-letters = %#v, want job %q", list.Items, newRecord.Job.ID)
	}
}

func TestManagerRejectsQueueConnectionMismatch(t *testing.T) {
	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		return contracts.RenderResult{}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	_, err = manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-mismatch-1",
		Queue: map[string]any{
			"connection": "redis",
			"queue":      "pdfs",
		},
	})
	if err == nil {
		t.Fatal("expected Submit to reject incompatible queue connection")
	}

	if !strings.Contains(err.Error(), "incompatible") {
		t.Fatalf("expected compatibility error, got %v", err)
	}
}

func TestManagerPublishesQueuedRunningAndCompletedEvents(t *testing.T) {
	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		time.Sleep(25 * time.Millisecond)

		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "event-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-event-1",
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})
	if completed.Result == nil {
		t.Fatal("expected completed job result")
	}

	assertEventKinds(t, manager.EventHistorySince(0), []events.Kind{
		events.JobQueued,
		events.JobRunning,
		events.JobCompleted,
	})
}

func TestManagerPublishesRetryLifecycleEvents(t *testing.T) {
	var attempts atomic.Int32

	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		if attempts.Add(1) == 1 {
			return contracts.RenderResult{}, errors.New("temporary failure")
		}

		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "retry-event-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-retry-event-1",
		Execution: map[string]any{
			"retries":      1,
			"retryBackoff": 1,
		},
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	completed := waitForJob(t, manager, job.ID, func(current contracts.RenderJob) bool {
		return current.Status == "completed"
	})
	if completed.Result == nil {
		t.Fatal("expected completed retry result")
	}

	assertEventKinds(t, manager.EventHistorySince(0), []events.Kind{
		events.JobQueued,
		events.JobRunning,
		events.JobRetried,
		events.JobRunning,
		events.JobCompleted,
	})
}

func TestManagerPublishesCancelledEvents(t *testing.T) {
	blocker := make(chan struct{})
	defer close(blocker)

	manager, err := NewManager(Config{
		StateDir: t.TempDir(),
		Workers:  1,
		Queue: QueueConfig{
			Backend: "memory",
			Depth:   8,
		},
	}, func(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
		select {
		case <-blocker:
		case <-ctx.Done():
		}

		return contracts.RenderResult{
			ContractVersion: contracts.RenderResultContractVersion,
			RequestID:       spec.RequestID,
			JobID:           "cancel-event-" + spec.RequestID,
			Status:          "completed",
		}, nil
	})
	if err != nil {
		t.Fatalf("NewManager returned error: %v", err)
	}
	defer manager.Close()

	job, err := manager.Submit(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "job-cancel-event-1",
	})
	if err != nil {
		t.Fatalf("Submit returned error: %v", err)
	}

	waitForStat(t, manager, func(stats Stats) bool {
		return stats.BusyWorkers == 1
	})

	cancelled, err := manager.Cancel(job.ID)
	if err != nil {
		t.Fatalf("Cancel returned error: %v", err)
	}

	final := waitForJob(t, manager, cancelled.ID, func(current contracts.RenderJob) bool {
		return current.Status == "cancelled"
	})

	if final.Status != "cancelled" {
		t.Fatalf("status = %q, want cancelled", final.Status)
	}

	assertEventKinds(t, manager.EventHistorySince(0), []events.Kind{
		events.JobQueued,
		events.JobRunning,
		events.JobCancelled,
	})
}

func waitForJob(t *testing.T, manager *Manager, jobID string, predicate func(contracts.RenderJob) bool) contracts.RenderJob {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		job, err := manager.Get(jobID)
		if err != nil {
			t.Fatalf("Get returned error: %v", err)
		}

		if predicate(job) {
			return job
		}

		time.Sleep(20 * time.Millisecond)
	}

	t.Fatalf("job %s did not reach expected state before timeout", jobID)
	return contracts.RenderJob{}
}

func waitForStat(t *testing.T, manager *Manager, predicate func(Stats) bool) {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if predicate(manager.Stats()) {
			return
		}

		time.Sleep(20 * time.Millisecond)
	}

	t.Fatalf("manager stats did not reach expected state before timeout: %+v", manager.Stats())
}

func assertEventKinds(t *testing.T, history []events.JobEvent, want []events.Kind) {
	t.Helper()

	if len(history) != len(want) {
		t.Fatalf("event history length = %d, want %d (%#v)", len(history), len(want), history)
	}

	for idx, kind := range want {
		if history[idx].Kind != kind {
			t.Fatalf("event kind at %d = %q, want %q (history=%#v)", idx, history[idx].Kind, kind, history)
		}
	}
}

func deadLetterRecord(jobID string, requestID string, failedAt time.Time) Record {
	failedAt = failedAt.UTC()

	return Record{
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              jobID,
			RequestID:       requestID,
			Status:          "failed",
			Error:           "permanent failure",
			Attempts:        2,
			MaxRetries:      1,
			SubmittedAt:     failedAt.Add(-30 * time.Second).Format(time.RFC3339Nano),
			StartedAt:       failedAt.Add(-20 * time.Second).Format(time.RFC3339Nano),
			CompletedAt:     failedAt.Format(time.RFC3339Nano),
		},
		Spec: contracts.RenderSpec{
			ContractVersion: contracts.RenderSpecContractVersion,
			RequestID:       requestID,
			Execution: map[string]any{
				"retries": 1,
			},
		},
	}
}

func redisAvailable(address string) bool {
	conn, err := net.DialTimeout("tcp", address, 250*time.Millisecond)
	if err != nil {
		return false
	}
	_ = conn.Close()

	return true
}
