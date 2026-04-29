package browser

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
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

func TestRendererRendersHTMLAcrossCDPDrivers(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	drivers := []string{rendererDriverLocalCDP, rendererDriverRodCDP}

	for _, driver := range drivers {
		driver := driver
		t.Run(driver, func(t *testing.T) {
			cfg := testRuntimeConfig()
			cfg.RendererDriver = driver

			renderer := New(cfg)
			defer renderer.Close()

			pdfBytes, warnings, debugArtifacts, _, err := renderer.Render(context.Background(), contracts.RenderSpec{
				ContractVersion: contracts.RenderSpecContractVersion,
				RequestID:       "renderer-driver-parity-" + driver,
				Source: contracts.RenderSource{
					Type: "html",
					Payload: map[string]any{
						"html": "<!doctype html><html><head><title>Driver Parity</title></head><body><h1>Driver " + driver + "</h1></body></html>",
					},
				},
				Presentation: map[string]any{
					"format":     "a4",
					"background": true,
				},
				Document: contracts.DocumentOptions{
					Title: "Parity " + driver,
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
		})
	}
}

func TestRendererCapturesDebugArtifactsWithRodNativeDriver(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := testRuntimeConfig()
	cfg.RendererDriver = rendererDriverRodCDP

	renderer := New(cfg)
	defer renderer.Close()

	pdfBytes, warnings, debugArtifacts, _, err := renderer.Render(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "renderer-rod-debug",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": `<!doctype html><html><head><title>Rod Debug</title><script>console.log("rod-console", 42);</script></head><body><h1>Rod Debug Artifact</h1></body></html>`,
			},
		},
		Presentation: map[string]any{
			"format":     "letter",
			"background": true,
		},
		Debug: map[string]any{
			"enabled": true,
		},
	})
	if err != nil {
		t.Fatalf("Render returned error: %v", err)
	}

	if !strings.HasPrefix(string(pdfBytes), "%PDF") {
		t.Fatalf("renderer did not return PDF bytes")
	}

	if debugArtifacts == nil {
		t.Fatalf("expected debug artifacts")
	}

	if !bytes.HasPrefix(debugArtifacts.ScreenshotPNG, []byte{0x89, 'P', 'N', 'G'}) {
		t.Fatalf("expected PNG screenshot, got %d bytes", len(debugArtifacts.ScreenshotPNG))
	}

	if !strings.Contains(debugArtifacts.DOMSnapshot, "Rod Debug Artifact") {
		t.Fatalf("expected DOM snapshot to include rendered body, got %q", debugArtifacts.DOMSnapshot)
	}

	if len(debugArtifacts.Console) == 0 || !strings.Contains(debugArtifacts.Console[0].Message, "rod-console") {
		t.Fatalf("expected rod console event, got %#v; warnings: %v", debugArtifacts.Console, warnings)
	}
}

func TestRendererRejectsFailedURLWithRodNativeDriver(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "broken", http.StatusInternalServerError)
	}))
	defer server.Close()

	cfg := testRuntimeConfig()
	cfg.RendererDriver = rendererDriverRodCDP
	cfg.AllowPrivateTargets = true

	renderer := New(cfg)
	defer renderer.Close()

	_, _, _, _, err := renderer.Render(context.Background(), contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "renderer-rod-url-status",
		Source: contracts.RenderSource{
			Type: "url",
			Payload: map[string]any{
				"url": server.URL,
			},
		},
		Presentation: map[string]any{
			"format": "letter",
		},
	})
	if err == nil || !strings.Contains(err.Error(), "HTTP 500") {
		t.Fatalf("expected Rod URL render to reject HTTP 500, got %v", err)
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

func TestValidateNavigationTargetRejectsUnsupportedSchemes(t *testing.T) {
	t.Parallel()

	if _, err := validateNavigationTarget("file:///etc/passwd", false, config.Default()); err == nil || !strings.Contains(err.Error(), "not allowed") {
		t.Fatalf("expected file scheme to be rejected, got %v", err)
	}
}

func TestValidateNavigationTargetRejectsEmbeddedCredentials(t *testing.T) {
	t.Parallel()

	if _, err := validateNavigationTarget("https://user:pass@example.test/report", false, config.Default()); err == nil || !strings.Contains(err.Error(), "embedded credentials") {
		t.Fatalf("expected embedded credentials to be rejected, got %v", err)
	}
}

func TestValidateNavigationTargetAllowsAboutBlankWhenRequested(t *testing.T) {
	t.Parallel()

	target, err := validateNavigationTarget("about:blank", true, config.Default())
	if err != nil {
		t.Fatalf("expected about:blank to be accepted, got %v", err)
	}

	if target != "about:blank" {
		t.Fatalf("target = %q, want about:blank", target)
	}
}

func TestValidateNavigationTargetRejectsPrivateNetworkTargetsByDefault(t *testing.T) {
	t.Parallel()

	if _, err := validateNavigationTarget("http://127.0.0.1:8080/healthz", false, config.Default()); err == nil || !strings.Contains(err.Error(), "private or loopback") {
		t.Fatalf("expected loopback target to be rejected, got %v", err)
	}
}

func TestValidateNavigationTargetAllowsPrivateTargetsWhenExplicitlyEnabled(t *testing.T) {
	t.Parallel()

	cfg := config.Default()
	cfg.AllowPrivateTargets = true

	target, err := validateNavigationTarget("http://127.0.0.1:8080/healthz", false, cfg)
	if err != nil {
		t.Fatalf("expected loopback target to be accepted when private targets are enabled, got %v", err)
	}

	if target != "http://127.0.0.1:8080/healthz" {
		t.Fatalf("target = %q, want %q", target, "http://127.0.0.1:8080/healthz")
	}
}

func TestValidateNavigationTargetAppliesHostAllowlist(t *testing.T) {
	t.Parallel()

	cfg := config.Default()
	cfg.AllowedTargetHosts = "example.com,*.billing.example.com"

	if _, err := validateNavigationTarget("https://blocked.example.com/report", false, cfg); err == nil || !strings.Contains(err.Error(), "not allowed") {
		t.Fatalf("expected host allowlist to reject unmatched host, got %v", err)
	}

	target, err := validateNavigationTarget("https://example.com/report", false, cfg)
	if err != nil {
		t.Fatalf("expected exact allowlisted host to pass, got %v", err)
	}

	if target != "https://example.com/report" {
		t.Fatalf("target = %q, want https://example.com/report", target)
	}
}

func testRuntimeConfig() config.RuntimeConfig {
	cfg := config.Default()
	if path := testBrowserPath(); path != "" {
		cfg.ChromiumPath = path
	}
	if os.Getenv("CI") != "" {
		cfg.DisableSandbox = true
	}

	return cfg
}

func browserAvailable() bool {
	return testBrowserPath() != ""
}

func testBrowserPath() string {
	candidates := []string{
		"google-chrome",
		"chromium",
		"chromium-browser",
		"chrome",
		"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
		"C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
		"/Applications/Chromium.app/Contents/MacOS/Chromium",
	}

	for _, candidate := range candidates {
		if strings.Contains(candidate, "\\") || strings.HasPrefix(candidate, "/") {
			if _, err := os.Stat(candidate); err == nil {
				return candidate
			}
			continue
		}

		if path, err := exec.LookPath(candidate); err == nil {
			return path
		}
	}

	return ""
}
