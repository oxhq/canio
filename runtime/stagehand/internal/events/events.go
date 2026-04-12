package events

import (
	"context"
	"crypto/sha1"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

const JobEventContractVersion = "canio.stagehand.job-event.v1"

type Kind string

const (
	JobQueued    Kind = "job.queued"
	JobRunning   Kind = "job.running"
	JobRetried   Kind = "job.retried"
	JobCompleted Kind = "job.completed"
	JobFailed    Kind = "job.failed"
	JobCancelled Kind = "job.cancelled"
)

type JobEvent struct {
	ContractVersion string              `json:"contractVersion"`
	Sequence        uint64              `json:"sequence"`
	ID              string              `json:"id"`
	Kind            Kind                `json:"kind"`
	EmittedAt       string              `json:"emittedAt"`
	Queue           string              `json:"queue,omitempty"`
	Reason          string              `json:"reason,omitempty"`
	RetryAt         string              `json:"retryAt,omitempty"`
	Job             contracts.RenderJob `json:"job"`
}

type JobEventOption func(*JobEvent)

func NewJobEvent(kind Kind, job contracts.RenderJob, options ...JobEventOption) JobEvent {
	event := JobEvent{
		ContractVersion: JobEventContractVersion,
		Kind:            kind,
		Job:             job,
	}

	for _, option := range options {
		if option != nil {
			option(&event)
		}
	}

	if strings.TrimSpace(event.EmittedAt) == "" {
		event.EmittedAt = time.Now().UTC().Format(time.RFC3339Nano)
	}

	return event
}

func WithQueue(queue string) JobEventOption {
	return func(event *JobEvent) {
		event.Queue = strings.TrimSpace(queue)
	}
}

func WithReason(reason string) JobEventOption {
	return func(event *JobEvent) {
		event.Reason = strings.TrimSpace(reason)
	}
}

func WithRetryAt(retryAt string) JobEventOption {
	return func(event *JobEvent) {
		event.RetryAt = strings.TrimSpace(retryAt)
	}
}

func WithEmittedAt(emittedAt string) JobEventOption {
	return func(event *JobEvent) {
		event.EmittedAt = strings.TrimSpace(emittedAt)
	}
}

type JobEventFilter func(JobEvent) bool

type Subscription struct {
	Events <-chan JobEvent
	Done   <-chan struct{}

	close func()
}

func (s *Subscription) Close() {
	if s == nil || s.close == nil {
		return
	}

	s.close()
}

type Bus struct {
	mu          sync.RWMutex
	closed      bool
	sequence    uint64
	history     []JobEvent
	historyCap  int
	nextSubID   uint64
	subscribers map[uint64]*subscriber
}

type subscriber struct {
	events chan JobEvent
	done   chan struct{}
}

func NewBus(historyCapacity int) *Bus {
	if historyCapacity <= 0 {
		historyCapacity = 128
	}

	return &Bus{
		historyCap:  historyCapacity,
		history:     make([]JobEvent, 0, historyCapacity),
		subscribers: map[uint64]*subscriber{},
	}
}

func (b *Bus) Publish(ctx context.Context, event JobEvent) error {
	if ctx != nil {
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}
	}

	b.mu.Lock()

	if b.closed {
		b.mu.Unlock()
		return context.Canceled
	}

	b.sequence++
	event.Sequence = b.sequence
	if strings.TrimSpace(event.ID) == "" {
		event.ID = eventID(event)
	}
	if strings.TrimSpace(event.EmittedAt) == "" {
		event.EmittedAt = time.Now().UTC().Format(time.RFC3339Nano)
	}
	if strings.TrimSpace(event.ContractVersion) == "" {
		event.ContractVersion = JobEventContractVersion
	}

	b.history = append(b.history, event)
	if len(b.history) > b.historyCap {
		start := len(b.history) - b.historyCap
		b.history = append([]JobEvent(nil), b.history[start:]...)
	}

	dropped := make([]uint64, 0)
	for id, sub := range b.subscribers {
		select {
		case sub.events <- event:
		default:
			dropped = append(dropped, id)
		}
	}
	b.mu.Unlock()
	for _, id := range dropped {
		b.removeSubscriber(id)
	}

	return nil
}

func (b *Bus) Subscribe(ctx context.Context, buffer int) *Subscription {
	if buffer <= 0 {
		buffer = 16
	}

	sub := &subscriber{
		events: make(chan JobEvent, buffer),
		done:   make(chan struct{}),
	}

	b.mu.Lock()
	if b.closed {
		b.mu.Unlock()
		close(sub.done)
		return &Subscription{Events: sub.events, Done: sub.done}
	}

	b.nextSubID++
	id := b.nextSubID
	b.subscribers[id] = sub
	b.mu.Unlock()

	s := &Subscription{
		Events: sub.events,
		Done:   sub.done,
		close: func() {
			b.removeSubscriber(id)
		},
	}

	if ctx != nil {
		go func() {
			select {
			case <-ctx.Done():
				b.removeSubscriber(id)
			case <-sub.done:
			}
		}()
	}

	return s
}

func (b *Bus) History() []JobEvent {
	return b.Since(0)
}

func (b *Bus) Since(sequence uint64) []JobEvent {
	b.mu.RLock()
	defer b.mu.RUnlock()

	if len(b.history) == 0 {
		return []JobEvent{}
	}

	items := make([]JobEvent, 0, len(b.history))
	for _, event := range b.history {
		if event.Sequence > sequence {
			items = append(items, event)
		}
	}

	return items
}

func (b *Bus) Close() {
	b.mu.Lock()
	if b.closed {
		b.mu.Unlock()
		return
	}

	b.closed = true
	ids := make([]uint64, 0, len(b.subscribers))
	for id := range b.subscribers {
		ids = append(ids, id)
	}
	b.mu.Unlock()

	for _, id := range ids {
		b.removeSubscriber(id)
	}
}

func (b *Bus) removeSubscriber(id uint64) {
	b.mu.Lock()
	sub, ok := b.subscribers[id]
	if !ok {
		b.mu.Unlock()
		return
	}

	delete(b.subscribers, id)
	b.mu.Unlock()
	close(sub.events)
	close(sub.done)
}

func eventID(event JobEvent) string {
	sum := sha1.Sum([]byte(fmt.Sprintf("%s:%d:%s:%s", event.Kind, event.Sequence, event.Job.ID, event.Job.RequestID)))
	return fmt.Sprintf("evt-%d-%x", event.Sequence, sum[:4])
}
