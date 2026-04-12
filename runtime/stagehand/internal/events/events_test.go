package events

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

func TestBusStoresHistoryAndSupportsLiveSubscriptions(t *testing.T) {
	bus := NewBus(4)

	queued := JobEvent{
		Kind: JobQueued,
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-queued-1",
			RequestID:       "req-queued-1",
			Status:          "queued",
		},
	}

	if err := bus.Publish(context.Background(), queued); err != nil {
		t.Fatalf("Publish returned error: %v", err)
	}

	history := bus.History()
	if len(history) != 1 {
		t.Fatalf("history length = %d, want 1", len(history))
	}

	if history[0].Sequence != 1 {
		t.Fatalf("sequence = %d, want 1", history[0].Sequence)
	}

	if history[0].ID == "" || history[0].EmittedAt == "" {
		t.Fatalf("expected event ID and emittedAt to be populated: %#v", history[0])
	}

	sub := bus.Subscribe(context.Background(), 2)
	defer sub.Close()

	completed := JobEvent{
		Kind: JobCompleted,
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-completed-1",
			RequestID:       "req-completed-1",
			Status:          "completed",
		},
	}

	if err := bus.Publish(context.Background(), completed); err != nil {
		t.Fatalf("Publish returned error: %v", err)
	}

	select {
	case event := <-sub.Events:
		if event.Kind != JobCompleted {
			t.Fatalf("event kind = %q, want %q", event.Kind, JobCompleted)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for live event")
	}
}

func TestWebhookDispatcherSignsAndDeliversEvents(t *testing.T) {
	t.Helper()

	var (
		receivedSignature string
		receivedTimestamp string
		receivedBody      []byte
		receivedKind      string
	)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		receivedSignature = r.Header.Get("X-Canio-Delivery-Signature")
		receivedTimestamp = r.Header.Get("X-Canio-Delivery-Timestamp")
		receivedKind = r.Header.Get("X-Canio-Event")
		receivedBody = readAll(t, r.Body)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	dispatcher := NewWebhookDispatcher(server.Client())
	event := JobEvent{
		Kind: JobFailed,
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              "job-webhook-1",
			RequestID:       "req-webhook-1",
			Status:          "failed",
			Error:           "boom",
		},
	}

	result, err := dispatcher.Deliver(context.Background(), WebhookTarget{
		URL:    server.URL,
		Secret: "super-secret",
		Headers: map[string]string{
			"X-Canio-Custom": "custom-value",
		},
	}, event)
	if err != nil {
		t.Fatalf("Deliver returned error: %v", err)
	}
	defer result.Response.Body.Close()

	if receivedKind != string(JobFailed) {
		t.Fatalf("event kind header = %q, want %q", receivedKind, JobFailed)
	}

	expectedSignature := signWebhookPayload("super-secret", receivedTimestamp, receivedBody)
	if receivedSignature != expectedSignature {
		t.Fatalf("signature = %q, want %q", receivedSignature, expectedSignature)
	}

	if result.Signature != expectedSignature {
		t.Fatalf("delivery signature = %q, want %q", result.Signature, expectedSignature)
	}
}

func TestBusDropsSlowSubscribersByClosingTheirChannels(t *testing.T) {
	bus := NewBus(4)
	sub := bus.Subscribe(context.Background(), 1)

	if err := bus.Publish(context.Background(), JobEvent{
		Kind: JobQueued,
		Job:  contracts.RenderJob{ID: "job-1", RequestID: "req-1", Status: "queued"},
	}); err != nil {
		t.Fatalf("Publish returned error: %v", err)
	}

	if err := bus.Publish(context.Background(), JobEvent{
		Kind: JobRunning,
		Job:  contracts.RenderJob{ID: "job-1", RequestID: "req-1", Status: "running"},
	}); err != nil {
		t.Fatalf("Publish returned error: %v", err)
	}

	select {
	case <-sub.Done:
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for subscriber removal")
	}

	select {
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for closed subscriber channel")
	default:
	}

	timeout := time.After(2 * time.Second)
	for {
		select {
		case _, ok := <-sub.Events:
			if !ok {
				return
			}
		case <-timeout:
			t.Fatal("timed out waiting for closed subscriber channel")
		}
	}
}

func readAll(t *testing.T, body io.ReadCloser) []byte {
	t.Helper()

	data, err := io.ReadAll(body)
	if err != nil {
		t.Fatalf("failed to read body: %v", err)
	}

	return data
}
