package artifacts

import (
	"errors"
	"os"
	"path/filepath"
	"testing"

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
