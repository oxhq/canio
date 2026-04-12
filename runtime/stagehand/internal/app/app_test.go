package app

import (
	"context"
	"encoding/base64"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/artifacts"
	"github.com/oxhq/canio/runtime/stagehand/internal/browser"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
	"github.com/oxhq/canio/runtime/stagehand/internal/observability"
)

func TestRenderReturnsInlinePDFPayload(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	runtime := New(testRuntimeConfig(t))
	defer runtime.Close()

	result, err := runtime.Render(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-123",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<h1>Invoice</h1>",
			},
		},
		Profile: "invoice",
		Document: contracts.DocumentOptions{
			Title: "Invoice #123",
		},
		Output: map[string]any{
			"fileName": "invoice.pdf",
		},
	})
	if err != nil {
		t.Fatalf("Render returned error: %v", err)
	}

	if result.ContractVersion != contracts.RenderResultContractVersion {
		t.Fatalf("contractVersion = %q, want %q", result.ContractVersion, contracts.RenderResultContractVersion)
	}

	decoded, err := base64.StdEncoding.DecodeString(result.PDF.Base64)
	if err != nil {
		t.Fatalf("base64 decode failed: %v", err)
	}

	if !strings.HasPrefix(string(decoded), "%PDF-1.4") {
		t.Fatalf("decoded payload is not a PDF: %q", string(decoded))
	}

	if result.PDF.FileName != "invoice.pdf" {
		t.Fatalf("fileName = %q, want invoice.pdf", result.PDF.FileName)
	}
}

func TestDebugRenderStoresArtifactsAndReplayWorks(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := config.Default()
	cfg = testRuntimeConfig(t)

	runtime := New(cfg)
	defer runtime.Close()

	result, err := runtime.Render(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-artifacts",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": `<!doctype html><html><body><h1>Artifacts</h1><script>console.log("canio-debug-artifact");</script></body></html>`,
			},
		},
		Profile: "invoice",
		Debug: map[string]any{
			"enabled": true,
		},
		Output: map[string]any{
			"fileName": "artifacts.pdf",
		},
	})
	if err != nil {
		t.Fatalf("Render returned error: %v", err)
	}

	if result.Artifacts == nil || result.Artifacts.ID == "" {
		t.Fatalf("expected artifacts bundle, got %#v", result.Artifacts)
	}

	if _, err := os.Stat(result.Artifacts.Files["renderSpec"]); err != nil {
		t.Fatalf("renderSpec artifact missing: %v", err)
	}

	if _, err := os.Stat(result.Artifacts.Files["pdf"]); err != nil {
		t.Fatalf("pdf artifact missing: %v", err)
	}

	if _, err := os.Stat(result.Artifacts.Files["consoleLog"]); err != nil {
		t.Fatalf("console log artifact missing: %v", err)
	}

	if _, err := os.Stat(result.Artifacts.Files["networkLog"]); err != nil {
		t.Fatalf("network log artifact missing: %v", err)
	}

	if _, err := os.Stat(result.Artifacts.Files["pageScreenshot"]); err != nil {
		t.Fatalf("page screenshot artifact missing: %v", err)
	}

	if _, err := os.Stat(result.Artifacts.Files["domSnapshot"]); err != nil {
		t.Fatalf("DOM snapshot artifact missing: %v", err)
	}

	replayed, err := runtime.Replay(context.Background(), result.Artifacts.ID)
	if err != nil {
		t.Fatalf("Replay returned error: %v", err)
	}

	if replayed.Artifacts == nil || replayed.Artifacts.ReplayOf != result.Artifacts.ID {
		t.Fatalf("expected replay artifacts to reference %q, got %#v", result.Artifacts.ID, replayed.Artifacts)
	}
}

func TestRenderReusesExactRenderCacheWithoutCallingRendererAgain(t *testing.T) {
	store := artifacts.New(t.TempDir())
	renderer := &fakeRenderer{
		pdf: []byte("%PDF-1.4\ncached"),
		warnings: []string{
			"font fallback",
		},
		timings: map[string]int64{
			"renderMs": 77,
		},
		debugArtifacts: &contracts.DebugArtifacts{
			ScreenshotPNG: []byte("png"),
			DOMSnapshot:   "<html><body>cached</body></html>",
			Console: []contracts.ConsoleEvent{
				{Type: "log", Message: "cached"},
			},
			Network: []contracts.NetworkEvent{
				{Stage: "response", RequestID: "req-1", Status: 200},
			},
		},
	}

	runtime := &App{
		config:    config.Default(),
		startedAt: time.Now().UTC(),
		state:     "ready",
		renderer:  renderer,
		store:     store,
		telemetry: observability.NewRuntime(time.Now().UTC()),
	}

	spec := contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-cache-a",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<!doctype html><html><body><h1>Cached</h1></body></html>",
			},
		},
		Profile: "invoice",
		Debug: map[string]any{
			"enabled": true,
		},
		Output: map[string]any{
			"fileName": "cached.pdf",
		},
	}

	first, err := runtime.Render(context.Background(), spec)
	if err != nil {
		t.Fatalf("first Render returned error: %v", err)
	}

	if renderer.calls != 1 {
		t.Fatalf("renderer.calls after first render = %d, want 1", renderer.calls)
	}

	if first.Artifacts == nil || first.Artifacts.ID == "" {
		t.Fatalf("expected first render to persist artifacts, got %#v", first.Artifacts)
	}

	secondSpec := spec
	secondSpec.RequestID = "req-cache-b"

	second, err := runtime.Render(context.Background(), secondSpec)
	if err != nil {
		t.Fatalf("second Render returned error: %v", err)
	}

	if renderer.calls != 1 {
		t.Fatalf("renderer.calls after cache hit = %d, want 1", renderer.calls)
	}

	if second.RequestID != secondSpec.RequestID {
		t.Fatalf("second.RequestID = %q, want %q", second.RequestID, secondSpec.RequestID)
	}

	if second.PDF.FileName != "cached.pdf" {
		t.Fatalf("second.PDF.FileName = %q, want cached.pdf", second.PDF.FileName)
	}

	if second.PDF.Base64 != first.PDF.Base64 {
		t.Fatalf("cache hit returned different PDF payloads")
	}

	if second.Artifacts == nil || second.Artifacts.ID == "" {
		t.Fatalf("expected cache hit to persist a fresh artifact bundle, got %#v", second.Artifacts)
	}

	if second.Artifacts.ID == first.Artifacts.ID {
		t.Fatalf("expected cache hit to create a fresh artifact bundle, got reused ID %q", second.Artifacts.ID)
	}
}

func TestDispatchQueuesJobAndReturnsCompletedResult(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := config.Default()
	cfg = testRuntimeConfig(t)

	runtime := New(cfg)
	defer runtime.Close()

	job, err := runtime.Dispatch(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-job-123",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<!doctype html><html><body><h1>Queued job</h1></body></html>",
			},
		},
		Debug: map[string]any{
			"enabled": true,
		},
		Output: map[string]any{
			"fileName": "queued-job.pdf",
		},
	})
	if err != nil {
		t.Fatalf("Dispatch returned error: %v", err)
	}

	if job.Status != "queued" {
		t.Fatalf("job status = %q, want queued", job.Status)
	}

	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		current, err := runtime.Job(job.ID)
		if err != nil {
			t.Fatalf("Job returned error: %v", err)
		}

		if current.Status == "completed" {
			if current.Result == nil {
				t.Fatal("expected completed job to include render result")
			}

			if current.Result.Artifacts == nil || current.Result.Artifacts.ID == "" {
				t.Fatalf("expected job result artifacts, got %#v", current.Result.Artifacts)
			}

			return
		}

		if current.Status == "failed" {
			t.Fatalf("expected queued job to complete successfully, got failure %q", current.Error)
		}

		time.Sleep(25 * time.Millisecond)
	}

	t.Fatal("queued job did not complete before timeout")
}

func TestDeliverWebhookWithRetryRetriesUntilSuccess(t *testing.T) {
	var attempts atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		current := attempts.Add(1)
		if current < 3 {
			w.WriteHeader(http.StatusBadGateway)
			return
		}

		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	delivery, err, usedAttempts := deliverWebhookWithRetry(
		context.Background(),
		events.NewWebhookDispatcher(server.Client()),
		events.WebhookTarget{URL: server.URL, Secret: "secret-123"},
		events.NewJobEvent(events.JobCompleted, contracts.RenderJob{
			ID:        "job-webhook-1",
			RequestID: "req-webhook-1",
			Status:    "completed",
		}),
		3,
		5*time.Millisecond,
	)
	if err != nil {
		t.Fatalf("deliverWebhookWithRetry() error = %v", err)
	}

	if usedAttempts != 3 {
		t.Fatalf("usedAttempts = %d, want 3", usedAttempts)
	}

	if attempts.Load() != 3 {
		t.Fatalf("server attempts = %d, want 3", attempts.Load())
	}

	if delivery == nil || delivery.Response == nil || delivery.Response.StatusCode != http.StatusAccepted {
		t.Fatalf("delivery response = %#v, want %d", delivery, http.StatusAccepted)
	}
}

func browserAvailable() bool {
	candidates := []string{
		"google-chrome",
		"chromium",
		"chromium-browser",
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
		"/Applications/Chromium.app/Contents/MacOS/Chromium",
	}

	for _, candidate := range candidates {
		if strings.HasPrefix(candidate, "/") {
			if _, err := os.Stat(candidate); err == nil {
				return true
			}
			continue
		}

		if _, err := exec.LookPath(candidate); err == nil {
			return true
		}
	}

	return false
}

func testRuntimeConfig(t *testing.T) config.RuntimeConfig {
	t.Helper()

	cfg := config.Default()
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}
	cfg.StateDir = makeRetriableTempDir(t, "stagehand-state-")
	cfg.UserDataDir = makeRetriableTempDir(t, "stagehand-user-data-")
	cfg.BrowserPoolSize = 1
	cfg.BrowserPoolWarm = 1
	cfg.JobWorkerCount = 1

	return cfg
}

func makeRetriableTempDir(t *testing.T, pattern string) string {
	t.Helper()

	dir, err := os.MkdirTemp("", pattern)
	if err != nil {
		t.Fatalf("MkdirTemp failed: %v", err)
	}

	t.Cleanup(func() {
		if cleanupErr := removeAllWithRetry(dir, 10, 100*time.Millisecond); cleanupErr != nil && !errors.Is(cleanupErr, os.ErrNotExist) {
			t.Logf("temp dir cleanup for %s failed: %v", dir, cleanupErr)
		}
	})

	return dir
}

func removeAllWithRetry(path string, attempts int, delay time.Duration) error {
	var lastErr error

	for attempt := 0; attempt < attempts; attempt++ {
		if err := os.RemoveAll(path); err == nil || errors.Is(err, os.ErrNotExist) {
			return nil
		} else {
			lastErr = err
		}

		time.Sleep(delay)
	}

	return lastErr
}

type fakeRenderer struct {
	calls          int
	pdf            []byte
	warnings       []string
	debugArtifacts *contracts.DebugArtifacts
	timings        map[string]int64
}

func (f *fakeRenderer) Render(context.Context, contracts.RenderSpec) ([]byte, []string, *contracts.DebugArtifacts, map[string]int64, error) {
	f.calls++
	return append([]byte(nil), f.pdf...), append([]string(nil), f.warnings...), f.debugArtifacts, cloneTimingsMap(f.timings), nil
}

func (f *fakeRenderer) Status() browser.PoolStatus {
	return browser.PoolStatus{}
}

func (f *fakeRenderer) Close() {}

func cloneTimingsMap(timings map[string]int64) map[string]int64 {
	if len(timings) == 0 {
		return nil
	}

	cloned := make(map[string]int64, len(timings))
	for key, value := range timings {
		cloned[key] = value
	}

	return cloned
}
