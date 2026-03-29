package browser

import (
	"context"
	"os"
	"os/exec"
	"strings"
	"testing"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

func TestRendererRendersHTMLWithCDP(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	renderer := New(testRuntimeConfig())
	defer renderer.Close()

	pdfBytes, warnings, debugArtifacts, _, err := renderer.Render(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "renderer-req-1",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<!doctype html><html><head><title>Invoice</title></head><body><h1>Invoice #123</h1></body></html>",
			},
		},
		Presentation: map[string]any{
			"format":     "letter",
			"background": true,
		},
		Document: contracts.DocumentOptions{
			Title: "Invoice #123",
		},
	})
	if err != nil {
		t.Fatalf("Render returned error: %v", err)
	}

	if len(warnings) > 0 && strings.Contains(strings.Join(warnings, " "), "placeholder") {
		t.Fatalf("renderer returned placeholder warnings: %v", warnings)
	}

	if !strings.HasPrefix(string(pdfBytes), "%PDF") {
		t.Fatalf("renderer did not return PDF bytes")
	}

	if debugArtifacts != nil {
		t.Fatalf("expected debug artifacts to be nil when debug mode is disabled")
	}
}

func TestResolveHTMLBootstrapURLUsesAboutBlankForNormalizedViews(t *testing.T) {
	spec := contracts.RenderSpec{
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html":    "<html><body>Invoice</body></html>",
				"baseUrl": "http://127.0.0.1:8000",
				"origin": map[string]any{
					"type": "view",
					"view": "pdf.invoice",
				},
			},
		},
	}

	if got := resolveHTMLBootstrapURL(spec); got != "about:blank" {
		t.Fatalf("expected normalized Blade views to bootstrap from about:blank, got %q", got)
	}
}

func TestResolveHTMLBootstrapURLKeepsBaseURLForRawHTMLSources(t *testing.T) {
	spec := contracts.RenderSpec{
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html":    "<html><body>Invoice</body></html>",
				"baseUrl": "https://canio.test",
			},
		},
	}

	if got := resolveHTMLBootstrapURL(spec); got != "https://canio.test" {
		t.Fatalf("expected raw HTML sources to keep their base URL bootstrap, got %q", got)
	}
}

func testRuntimeConfig() config.RuntimeConfig {
	cfg := config.Default()
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}

	return cfg
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
