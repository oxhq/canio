package artifacts

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

func TestStoreArtifactReturnsManifest(t *testing.T) {
	t.Parallel()

	root := t.TempDir()
	store := New(root)
	spec := contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-artifact-1",
		Profile:         "invoice",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<html><body>Hello</body></html>",
			},
		},
		Debug: map[string]any{
			"enabled": true,
		},
	}
	result := contracts.RenderResult{
		ContractVersion: contracts.RenderResultContractVersion,
		RequestID:       spec.RequestID,
		JobID:           "job-artifact-1",
		Status:          "completed",
		Warnings:        []string{"font fallback"},
		Timings: map[string]int64{
			"totalMs": 321,
		},
		PDF: contracts.RenderedPDF{
			Base64:      "cGRm",
			ContentType: "application/pdf",
			FileName:    "artifact.pdf",
			Bytes:       3,
		},
	}
	debugArtifacts := &contracts.DebugArtifacts{
		ScreenshotPNG: []byte("png"),
		DOMSnapshot:   "<html><body>snapshot</body></html>",
		Console: []contracts.ConsoleEvent{
			{Type: "log", Message: "ready"},
		},
		Network: []contracts.NetworkEvent{
			{Stage: "response", RequestID: "req-1", Status: 200},
		},
	}

	bundle, err := store.Save(spec, result, []byte("pdf"), debugArtifacts, "")
	if err != nil {
		t.Fatalf("Save() error = %v", err)
	}

	artifact, err := store.Artifact(bundle.ID)
	if err != nil {
		t.Fatalf("Artifact() error = %v", err)
	}

	if artifact.ID != bundle.ID {
		t.Fatalf("artifact.ID = %q, want %q", artifact.ID, bundle.ID)
	}

	if artifact.Profile != "invoice" {
		t.Fatalf("artifact.Profile = %q, want invoice", artifact.Profile)
	}

	if artifact.Output.FileName != "artifact.pdf" {
		t.Fatalf("artifact.Output.FileName = %q, want artifact.pdf", artifact.Output.FileName)
	}

	for _, key := range []string{"renderSpec", "metadata", "sourceHtml", "pdf", "pageScreenshot", "domSnapshot", "consoleLog", "networkLog"} {
		if _, ok := artifact.Files[key]; !ok {
			t.Fatalf("artifact.Files[%q] missing", key)
		}
	}

	if _, err := os.Stat(filepath.Join(root, "artifacts", bundle.ID, "metadata.json")); err != nil {
		t.Fatalf("metadata.json should exist: %v", err)
	}
}

func TestStoreArtifactMissing(t *testing.T) {
	t.Parallel()

	store := New(t.TempDir())

	_, err := store.Artifact("art-missing")
	if !errors.Is(err, ErrArtifactNotFound) {
		t.Fatalf("Artifact() error = %v, want ErrArtifactNotFound", err)
	}
}

func TestStoreArtifactCanReplaySpecAfterInspect(t *testing.T) {
	t.Parallel()

	store := New(t.TempDir())
	spec := contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-replay-1",
		Source: contracts.RenderSource{
			Type:    "url",
			Payload: map[string]any{"url": "https://example.test"},
		},
		Debug: map[string]any{
			"watch": true,
		},
	}
	result := contracts.RenderResult{
		ContractVersion: contracts.RenderResultContractVersion,
		RequestID:       spec.RequestID,
		JobID:           "job-replay-1",
		Status:          "completed",
		PDF: contracts.RenderedPDF{
			Base64:      "cGRm",
			ContentType: "application/pdf",
			FileName:    "replay.pdf",
			Bytes:       3,
		},
	}

	bundle, err := store.Save(spec, result, []byte("pdf"), nil, "art-original")
	if err != nil {
		t.Fatalf("Save() error = %v", err)
	}

	artifact, err := store.Artifact(bundle.ID)
	if err != nil {
		t.Fatalf("Artifact() error = %v", err)
	}

	loaded, err := store.LoadSpec(artifact.ID)
	if err != nil {
		t.Fatalf("LoadSpec() error = %v", err)
	}

	if loaded.RequestID != spec.RequestID {
		t.Fatalf("loaded.RequestID = %q, want %q", loaded.RequestID, spec.RequestID)
	}
}

func TestStoreRenderCacheRoundTripIgnoresRequestID(t *testing.T) {
	t.Parallel()

	store := New(t.TempDir())
	spec := contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-cache-1",
		Profile:         "invoice",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<html><body>Cached</body></html>",
			},
		},
		Debug: map[string]any{
			"enabled": true,
		},
	}
	result := contracts.RenderResult{
		ContractVersion: contracts.RenderResultContractVersion,
		RequestID:       spec.RequestID,
		JobID:           "job-cache-1",
		Status:          "completed",
		Warnings:        []string{"font fallback"},
		Timings: map[string]int64{
			"renderMs": 123,
		},
		PDF: contracts.RenderedPDF{
			Base64:      "cGRm",
			ContentType: "application/pdf",
			FileName:    "cached.pdf",
			Bytes:       3,
		},
	}
	debugArtifacts := &contracts.DebugArtifacts{
		ScreenshotPNG: []byte("png"),
		DOMSnapshot:   "<html><body>snapshot</body></html>",
		Console: []contracts.ConsoleEvent{
			{Type: "log", Message: "ready"},
		},
		Network: []contracts.NetworkEvent{
			{Stage: "response", RequestID: "req-1", Status: 200},
		},
	}

	if err := store.SaveRenderCache(spec, result, []byte("pdf"), debugArtifacts); err != nil {
		t.Fatalf("SaveRenderCache() error = %v", err)
	}

	loaded, err := store.LoadRenderCache(contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-cache-2",
		Profile:         spec.Profile,
		Source:          spec.Source,
		Debug:           spec.Debug,
	})
	if err != nil {
		t.Fatalf("LoadRenderCache() error = %v", err)
	}

	if loaded.Hash == "" {
		t.Fatal("expected cache hash to be populated")
	}

	if string(loaded.PDFBytes) != "pdf" {
		t.Fatalf("loaded.PDFBytes = %q, want %q", string(loaded.PDFBytes), "pdf")
	}

	if len(loaded.Warnings) != 1 || loaded.Warnings[0] != "font fallback" {
		t.Fatalf("loaded.Warnings = %#v, want font fallback", loaded.Warnings)
	}

	if got := loaded.DebugArtifacts; got == nil || string(got.ScreenshotPNG) != "png" || got.DOMSnapshot != "<html><body>snapshot</body></html>" {
		t.Fatalf("loaded.DebugArtifacts = %#v, want screenshot + dom snapshot", got)
	}

	if len(loaded.DebugArtifacts.Console) != 1 || len(loaded.DebugArtifacts.Network) != 1 {
		t.Fatalf("loaded.DebugArtifacts events = %#v, want 1 console + 1 network", loaded.DebugArtifacts)
	}
}

func TestStoreRenderCacheMissingReturnsNotFound(t *testing.T) {
	t.Parallel()

	store := New(t.TempDir())

	if _, err := store.LoadRenderCache(contracts.RenderSpec{RequestID: "req-missing"}); !errors.Is(err, ErrRenderCacheNotFound) {
		t.Fatalf("LoadRenderCache() error = %v, want ErrRenderCacheNotFound", err)
	}
}

func TestStoreCleanupRenderCacheRemovesExpiredEntries(t *testing.T) {
	t.Parallel()

	store := New(t.TempDir())
	spec := contracts.RenderSpec{
		ContractVersion: contracts.RenderSpecContractVersion,
		RequestID:       "req-cache-expired",
		Profile:         "invoice",
		Source: contracts.RenderSource{
			Type: "html",
			Payload: map[string]any{
				"html": "<html><body>Expired cache</body></html>",
			},
		},
	}
	result := contracts.RenderResult{
		ContractVersion: contracts.RenderResultContractVersion,
		RequestID:       spec.RequestID,
		Status:          "completed",
		PDF: contracts.RenderedPDF{
			Base64:      "cGRm",
			ContentType: "application/pdf",
			FileName:    "expired.pdf",
			Bytes:       3,
		},
	}

	if err := store.SaveRenderCache(spec, result, []byte("pdf"), nil); err != nil {
		t.Fatalf("SaveRenderCache() error = %v", err)
	}

	cached, err := store.LoadRenderCache(spec)
	if err != nil {
		t.Fatalf("LoadRenderCache() error = %v", err)
	}

	cachePath := filepath.Join(cached.Directory, "cache.json")
	data, err := os.ReadFile(cachePath)
	if err != nil {
		t.Fatalf("ReadFile() error = %v", err)
	}

	var metadata renderCacheMetadata
	if err := json.Unmarshal(data, &metadata); err != nil {
		t.Fatalf("Unmarshal() error = %v", err)
	}

	metadata.CreatedAt = time.Now().UTC().Add(-72 * time.Hour).Format(time.RFC3339)
	if err := writeJSON(cachePath, metadata); err != nil {
		t.Fatalf("writeJSON() error = %v", err)
	}

	removed, err := store.CleanupRenderCache(24 * time.Hour)
	if err != nil {
		t.Fatalf("CleanupRenderCache() error = %v", err)
	}

	if len(removed) != 1 {
		t.Fatalf("CleanupRenderCache() removed %d entries, want 1", len(removed))
	}

	if _, err := store.LoadRenderCache(spec); !errors.Is(err, ErrRenderCacheNotFound) {
		t.Fatalf("LoadRenderCache() after cleanup error = %v, want ErrRenderCacheNotFound", err)
	}
}
