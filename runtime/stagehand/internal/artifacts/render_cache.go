package artifacts

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

var ErrRenderCacheNotFound = errors.New("render cache not found")

type RenderCacheEntry struct {
	Hash           string
	Directory      string
	Spec           contracts.RenderSpec
	CreatedAt      string
	Warnings       []string
	Timings        map[string]int64
	PDFBytes       []byte
	DebugArtifacts *contracts.DebugArtifacts
}

type renderCacheMetadata struct {
	Hash      string               `json:"hash"`
	CreatedAt string               `json:"createdAt"`
	Spec      contracts.RenderSpec `json:"spec"`
	Warnings  []string             `json:"warnings,omitempty"`
	Timings   map[string]int64     `json:"timings,omitempty"`
	Debug     debugMetadata        `json:"debug,omitempty"`
}

func (s *Store) SaveRenderCache(spec contracts.RenderSpec, result contracts.RenderResult, pdfBytes []byte, debugArtifacts *contracts.DebugArtifacts) error {
	if strings.TrimSpace(s.root) == "" {
		return nil
	}

	hash, normalized, err := renderCacheKey(spec)
	if err != nil {
		return err
	}

	directory := filepath.Join(s.root, "cache", "renders", hash)
	if err := os.MkdirAll(directory, 0o755); err != nil {
		return err
	}

	if err := writeJSON(filepath.Join(directory, "render-spec.json"), normalized); err != nil {
		return err
	}

	debugMeta, err := s.saveCacheDebugArtifacts(directory, debugArtifacts)
	if err != nil {
		return err
	}

	if err := os.WriteFile(filepath.Join(directory, "pdf.pdf"), pdfBytes, 0o644); err != nil {
		return err
	}

	metadataPath := filepath.Join(directory, "cache.json")
	return writeJSON(metadataPath, renderCacheMetadata{
		Hash:      hash,
		CreatedAt: time.Now().UTC().Format(time.RFC3339),
		Spec:      normalized,
		Warnings:  append([]string(nil), result.Warnings...),
		Timings:   cloneTimings(result.Timings),
		Debug:     debugMeta,
	})
}

func (s *Store) LoadRenderCache(spec contracts.RenderSpec) (*RenderCacheEntry, error) {
	if strings.TrimSpace(s.root) == "" {
		return nil, ErrRenderCacheNotFound
	}

	hash, _, err := renderCacheKey(spec)
	if err != nil {
		return nil, err
	}

	directory := filepath.Join(s.root, "cache", "renders", hash)
	metadataPath := filepath.Join(directory, "cache.json")
	metadataFile, err := os.Open(metadataPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, ErrRenderCacheNotFound
		}
		return nil, err
	}
	defer func() {
		_ = metadataFile.Close()
	}()

	var metadata renderCacheMetadata
	if err := json.NewDecoder(metadataFile).Decode(&metadata); err != nil {
		return nil, err
	}

	expectedHash, _, err := renderCacheKey(spec)
	if err != nil {
		return nil, err
	}
	if metadata.Hash != expectedHash {
		return nil, ErrRenderCacheNotFound
	}

	specPath := filepath.Join(directory, "render-spec.json")
	specFile, err := os.Open(specPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, ErrRenderCacheNotFound
		}
		return nil, err
	}

	cachedSpec, err := contracts.DecodeRenderSpec(specFile)
	_ = specFile.Close()
	if err != nil {
		return nil, err
	}

	cachedHash, _, err := renderCacheKey(cachedSpec)
	if err != nil {
		return nil, err
	}
	if cachedHash != expectedHash {
		return nil, ErrRenderCacheNotFound
	}

	pdfBytes, err := os.ReadFile(filepath.Join(directory, "pdf.pdf"))
	if err != nil {
		if os.IsNotExist(err) {
			return nil, ErrRenderCacheNotFound
		}
		return nil, err
	}

	debugArtifacts, err := s.loadCacheDebugArtifacts(directory, metadata.Debug)
	if err != nil {
		return nil, err
	}

	return &RenderCacheEntry{
		Hash:           metadata.Hash,
		Directory:      directory,
		Spec:           cachedSpec,
		CreatedAt:      metadata.CreatedAt,
		Warnings:       append([]string(nil), metadata.Warnings...),
		Timings:        cloneTimings(metadata.Timings),
		PDFBytes:       pdfBytes,
		DebugArtifacts: debugArtifacts,
	}, nil
}

func renderCacheKey(spec contracts.RenderSpec) (string, contracts.RenderSpec, error) {
	normalized := spec
	normalized.ContractVersion = ""
	normalized.RequestID = ""
	normalized.Queue = nil
	normalized.Correlation = nil

	data, err := json.Marshal(normalized)
	if err != nil {
		return "", contracts.RenderSpec{}, err
	}

	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), normalized, nil
}

func (s *Store) saveCacheDebugArtifacts(directory string, debugArtifacts *contracts.DebugArtifacts) (debugMetadata, error) {
	if debugArtifacts == nil {
		return debugMetadata{}, nil
	}

	debugMeta := debugMetadata{
		ConsoleEvents: len(debugArtifacts.Console),
		NetworkEvents: len(debugArtifacts.Network),
	}

	consolePath := filepath.Join(directory, "console-log.json")
	if err := writeJSON(consolePath, debugArtifacts.Console); err != nil {
		return debugMeta, err
	}
	debugMeta.ConsoleLogFile = filepath.Base(consolePath)

	networkPath := filepath.Join(directory, "network-log.json")
	if err := writeJSON(networkPath, debugArtifacts.Network); err != nil {
		return debugMeta, err
	}
	debugMeta.NetworkLogFile = filepath.Base(networkPath)

	if len(debugArtifacts.ScreenshotPNG) > 0 {
		screenshotPath := filepath.Join(directory, "page-screenshot.png")
		if err := os.WriteFile(screenshotPath, debugArtifacts.ScreenshotPNG, 0o644); err != nil {
			return debugMeta, err
		}
		debugMeta.ScreenshotFile = filepath.Base(screenshotPath)
	}

	if strings.TrimSpace(debugArtifacts.DOMSnapshot) != "" {
		domPath := filepath.Join(directory, "dom-snapshot.html")
		if err := os.WriteFile(domPath, []byte(debugArtifacts.DOMSnapshot), 0o644); err != nil {
			return debugMeta, err
		}
		debugMeta.DOMSnapshot = filepath.Base(domPath)
	}

	return debugMeta, nil
}

func (s *Store) loadCacheDebugArtifacts(directory string, meta debugMetadata) (*contracts.DebugArtifacts, error) {
	if meta == (debugMetadata{}) {
		return nil, nil
	}

	artifacts := &contracts.DebugArtifacts{}

	if meta.ConsoleLogFile != "" {
		consolePath := filepath.Join(directory, meta.ConsoleLogFile)
		consoleBytes, err := os.ReadFile(consolePath)
		if err != nil {
			if os.IsNotExist(err) {
				return nil, ErrRenderCacheNotFound
			}
			return nil, err
		}

		if err := json.Unmarshal(consoleBytes, &artifacts.Console); err != nil {
			return nil, err
		}
	}

	if meta.NetworkLogFile != "" {
		networkPath := filepath.Join(directory, meta.NetworkLogFile)
		networkBytes, err := os.ReadFile(networkPath)
		if err != nil {
			if os.IsNotExist(err) {
				return nil, ErrRenderCacheNotFound
			}
			return nil, err
		}

		if err := json.Unmarshal(networkBytes, &artifacts.Network); err != nil {
			return nil, err
		}
	}

	if meta.ScreenshotFile != "" {
		screenshotPath := filepath.Join(directory, meta.ScreenshotFile)
		screenshotBytes, err := os.ReadFile(screenshotPath)
		if err != nil {
			if os.IsNotExist(err) {
				return nil, ErrRenderCacheNotFound
			}
			return nil, err
		}

		artifacts.ScreenshotPNG = screenshotBytes
	}

	if meta.DOMSnapshot != "" {
		domPath := filepath.Join(directory, meta.DOMSnapshot)
		domBytes, err := os.ReadFile(domPath)
		if err != nil {
			if os.IsNotExist(err) {
				return nil, ErrRenderCacheNotFound
			}
			return nil, err
		}

		artifacts.DOMSnapshot = string(domBytes)
	}

	if len(artifacts.Console) == 0 && len(artifacts.Network) == 0 && len(artifacts.ScreenshotPNG) == 0 && strings.TrimSpace(artifacts.DOMSnapshot) == "" {
		return nil, nil
	}

	return artifacts, nil
}

func cloneTimings(timings map[string]int64) map[string]int64 {
	if len(timings) == 0 {
		return nil
	}

	cloned := make(map[string]int64, len(timings))
	for key, value := range timings {
		cloned[key] = value
	}

	return cloned
}
