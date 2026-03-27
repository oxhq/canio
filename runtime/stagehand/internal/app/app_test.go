package app

import (
	"context"
	"encoding/base64"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

func TestRenderReturnsInlinePDFPayload(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	runtime := New(testRuntimeConfig())
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
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}
	cfg.StateDir = t.TempDir()

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

func TestDispatchQueuesJobAndReturnsCompletedResult(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := config.Default()
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}
	cfg.StateDir = t.TempDir()

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

func testRuntimeConfig() config.RuntimeConfig {
	cfg := config.Default()
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}

	return cfg
}
