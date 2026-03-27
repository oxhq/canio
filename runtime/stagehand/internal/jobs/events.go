package jobs

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
)

func (m *Manager) SubscribeEvents(ctx context.Context, buffer int) *events.Subscription {
	if m == nil || m.events == nil {
		return nil
	}

	return m.events.Subscribe(ctx, buffer)
}

func (m *Manager) EventHistorySince(sequence uint64) []events.JobEvent {
	if m == nil || m.events == nil {
		return []events.JobEvent{}
	}

	return m.events.Since(sequence)
}

func (m *Manager) Cancel(jobID string) (contracts.RenderJob, error) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return contracts.RenderJob{}, ErrJobNotFound
	}

	m.mu.Lock()
	record, ok := m.records[jobID]
	if !ok {
		m.mu.Unlock()
		return contracts.RenderJob{}, ErrJobNotFound
	}

	switch record.Job.Status {
	case "completed", "failed", "cancelled":
		m.mu.Unlock()
		return cloneJob(record.Job), fmt.Errorf("job %s is already %s", jobID, record.Job.Status)
	}

	record.Job.Status = "cancelled"
	record.Job.Error = "job was cancelled"
	record.Job.CompletedAt = time.Now().UTC().Format(time.RFC3339Nano)
	record.Job.NextRetryAt = ""
	record.Job.Result = nil
	record.Job.DeadLetter = nil
	cancelRun := m.runningCancels[jobID]
	m.records[jobID] = record
	m.mu.Unlock()

	if cancelRun != nil {
		cancelRun()
	}

	_ = m.store.SaveJob(record.Job)
	m.publishJobEvent(events.JobCancelled, record, queueNameForRecord(record), record.Job.Error, "")

	return cloneJob(record.Job), nil
}

func (m *Manager) publishJobEvent(kind events.Kind, record Record, queueName string, reason string, retryAt string) {
	if m == nil || m.events == nil {
		return
	}

	event := events.NewJobEvent(
		kind,
		cloneJob(record.Job),
		events.WithQueue(queueName),
		events.WithReason(reason),
		events.WithRetryAt(retryAt),
	)

	_ = m.events.Publish(context.Background(), event)
}

func queueNameForRecord(record Record) string {
	_, queueName := queueSettings(record.Spec)
	return queueName
}
