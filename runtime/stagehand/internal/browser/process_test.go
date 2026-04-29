package browser

import (
	"context"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
)

func TestSlotUserDataDirIsNamespacedByStagehandProcess(t *testing.T) {
	base := filepath.Join("runtime", "chromium-profile")

	got := slotUserDataDir(base, 7)
	want := filepath.Join(base, "stagehand-"+strconv.Itoa(os.Getpid()), "browser-007")

	if got != want {
		t.Fatalf("slotUserDataDir() = %q, want %q", got, want)
	}
}

func TestNormalizeRendererDriverDefaultsToRodCDP(t *testing.T) {
	cfg := config.Default()

	got, err := normalizeRendererDriver(cfg)
	if err != nil {
		t.Fatalf("normalizeRendererDriver returned error: %v", err)
	}

	if got != rendererDriverRodCDP {
		t.Fatalf("driver = %q, want %q", got, rendererDriverRodCDP)
	}
}

func TestNormalizeRendererDriverInfersRemoteCDPFromEndpoint(t *testing.T) {
	cfg := config.Default()
	cfg.RendererDriver = ""
	cfg.RemoteCDPEndpoint = "ws://127.0.0.1:9222/devtools/browser/test"

	got, err := normalizeRendererDriver(cfg)
	if err != nil {
		t.Fatalf("normalizeRendererDriver returned error: %v", err)
	}

	if got != rendererDriverRemoteCDP {
		t.Fatalf("driver = %q, want %q", got, rendererDriverRemoteCDP)
	}
}

func TestNormalizeRendererDriverRequiresRemoteEndpoint(t *testing.T) {
	cfg := config.Default()
	cfg.RendererDriver = "remote-cdp"

	_, err := normalizeRendererDriver(cfg)
	if err == nil || !strings.Contains(err.Error(), "remote CDP endpoint") {
		t.Fatalf("expected missing remote endpoint error, got %v", err)
	}
}

func TestNormalizeRendererDriverAcceptsRodCDP(t *testing.T) {
	cfg := config.Default()
	cfg.RendererDriver = "rod-cdp"

	got, err := normalizeRendererDriver(cfg)
	if err != nil {
		t.Fatalf("normalizeRendererDriver returned error: %v", err)
	}

	if got != rendererDriverRodCDP {
		t.Fatalf("driver = %q, want %q", got, rendererDriverRodCDP)
	}
}

func TestNormalizeRendererDriverRejectsUnknownDriver(t *testing.T) {
	cfg := config.Default()
	cfg.RendererDriver = "playwright"

	_, err := normalizeRendererDriver(cfg)
	if err == nil || !strings.Contains(err.Error(), "unsupported renderer driver") {
		t.Fatalf("expected unsupported driver error, got %v", err)
	}
}

func TestProcessFactoryStartWithRodCDPInitializesAndCloses(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	cfg := testRuntimeConfig()
	cfg.RendererDriver = rendererDriverRodCDP

	proc, err := processFactory{config: cfg}.Start(context.Background(), 1)
	if err != nil {
		t.Fatalf("Start returned error: %v", err)
	}

	select {
	case <-proc.Context().Done():
		t.Fatal("expected browser context to be alive immediately after startup")
	default:
	}

	proc.Close()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if proc.Context().Err() != nil {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}

	t.Fatal("expected browser context to be cancelled after Close")
}

func TestProcessFactoryStartWithRodCDPCreatesSlotScopedUserDataDir(t *testing.T) {
	if !browserAvailable() {
		t.Skip("Chrome/Chromium is not available on this machine")
	}

	base := t.TempDir()
	cfg := testRuntimeConfig()
	cfg.RendererDriver = rendererDriverRodCDP
	cfg.UserDataDir = base

	proc, err := processFactory{config: cfg}.Start(context.Background(), 7)
	if err != nil {
		t.Fatalf("Start returned error: %v", err)
	}
	defer proc.Close()

	slotDir := slotUserDataDir(base, 7)
	if stat, err := os.Stat(slotDir); err != nil || !stat.IsDir() {
		t.Fatalf("expected slot user-data directory %q to exist: %v", slotDir, err)
	}
}
