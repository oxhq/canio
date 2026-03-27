package artifacts

import (
	"crypto/sha1"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

type Store struct {
	root string
}

var ErrArtifactNotFound = errors.New("artifact not found")

type Entry struct {
	ID        string
	Directory string
	CreatedAt string
}

type metadata struct {
	ArtifactID string           `json:"artifactId"`
	ReplayOf   string           `json:"replayOf,omitempty"`
	CreatedAt  string           `json:"createdAt"`
	RequestID  string           `json:"requestId"`
	Profile    string           `json:"profile,omitempty"`
	SourceType string           `json:"sourceType"`
	Status     string           `json:"status"`
	Warnings   []string         `json:"warnings,omitempty"`
	Timings    map[string]int64 `json:"timings,omitempty"`
	Debug      debugMetadata    `json:"debug,omitempty"`
	Output     outputMetadata   `json:"output"`
}

type outputMetadata struct {
	Bytes    int    `json:"bytes"`
	FileName string `json:"fileName"`
}

type debugMetadata struct {
	ScreenshotFile string `json:"screenshotFile,omitempty"`
	DOMSnapshot    string `json:"domSnapshot,omitempty"`
	ConsoleLogFile string `json:"consoleLogFile,omitempty"`
	NetworkLogFile string `json:"networkLogFile,omitempty"`
	ConsoleEvents  int    `json:"consoleEvents,omitempty"`
	NetworkEvents  int    `json:"networkEvents,omitempty"`
}

func New(root string) *Store {
	return &Store{
		root: strings.TrimSpace(root),
	}
}

func (s *Store) Enabled(spec contracts.RenderSpec) bool {
	if s.root == "" {
		return false
	}

	return contracts.DebugArtifactsEnabled(spec)
}

func (s *Store) Save(spec contracts.RenderSpec, result contracts.RenderResult, pdfBytes []byte, debugArtifacts *contracts.DebugArtifacts, replayOf string) (*contracts.ArtifactBundle, error) {
	if !s.Enabled(spec) {
		return nil, nil
	}

	artifactID := generateArtifactID(spec.RequestID)
	directory := filepath.Join(s.root, "artifacts", artifactID)

	if err := os.MkdirAll(directory, 0o755); err != nil {
		return nil, err
	}

	files := map[string]string{}

	specPath := filepath.Join(directory, "render-spec.json")
	if err := writeJSON(specPath, spec); err != nil {
		return nil, err
	}
	files["renderSpec"] = specPath

	if htmlMarkup := htmlPayload(spec); htmlMarkup != "" {
		htmlPath := filepath.Join(directory, "source.html")
		if err := os.WriteFile(htmlPath, []byte(htmlMarkup), 0o644); err != nil {
			return nil, err
		}
		files["sourceHtml"] = htmlPath
	}

	pdfPath := filepath.Join(directory, result.PDF.FileName)
	if err := os.WriteFile(pdfPath, pdfBytes, 0o644); err != nil {
		return nil, err
	}
	files["pdf"] = pdfPath

	debugMeta, err := s.saveDebugArtifacts(directory, debugArtifacts, files)
	if err != nil {
		return nil, err
	}

	metaPath := filepath.Join(directory, "metadata.json")
	if err := writeJSON(metaPath, metadata{
		ArtifactID: artifactID,
		ReplayOf:   replayOf,
		CreatedAt:  time.Now().UTC().Format(time.RFC3339),
		RequestID:  spec.RequestID,
		Profile:    spec.Profile,
		SourceType: spec.Source.Type,
		Status:     result.Status,
		Warnings:   result.Warnings,
		Timings:    result.Timings,
		Debug:      debugMeta,
		Output: outputMetadata{
			Bytes:    result.PDF.Bytes,
			FileName: result.PDF.FileName,
		},
	}); err != nil {
		return nil, err
	}
	files["metadata"] = metaPath

	return &contracts.ArtifactBundle{
		ID:        artifactID,
		ReplayOf:  replayOf,
		Directory: directory,
		Files:     files,
	}, nil
}

func (s *Store) LoadSpec(artifactID string) (contracts.RenderSpec, error) {
	var spec contracts.RenderSpec

	if s.root == "" {
		return spec, fmt.Errorf("artifact store is not configured")
	}

	artifactID, err := normalizeArtifactID(artifactID)
	if err != nil {
		return spec, err
	}

	path := filepath.Join(s.root, "artifacts", artifactID, "render-spec.json")
	file, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return spec, fmt.Errorf("%w: %s", ErrArtifactNotFound, artifactID)
		}
		return spec, err
	}
	defer func() {
		_ = file.Close()
	}()

	spec, err = contracts.DecodeRenderSpec(file)
	return spec, err
}

func (s *Store) Artifact(artifactID string) (contracts.ArtifactDetail, error) {
	var detail contracts.ArtifactDetail

	if s.root == "" {
		return detail, fmt.Errorf("artifact store is not configured")
	}

	artifactID, err := normalizeArtifactID(artifactID)
	if err != nil {
		return detail, err
	}

	directory := filepath.Join(s.root, "artifacts", artifactID)
	meta, err := s.readMetadata(filepath.Join(directory, "metadata.json"))
	if err != nil {
		if os.IsNotExist(err) {
			return detail, fmt.Errorf("%w: %s", ErrArtifactNotFound, artifactID)
		}

		return detail, err
	}

	files, err := artifactFiles(directory)
	if err != nil {
		return detail, err
	}

	return contracts.ArtifactDetail{
		ContractVersion: contracts.ArtifactContractVersion,
		ID:              meta.ArtifactID,
		ReplayOf:        meta.ReplayOf,
		RequestID:       meta.RequestID,
		Profile:         meta.Profile,
		SourceType:      meta.SourceType,
		Status:          meta.Status,
		CreatedAt:       meta.CreatedAt,
		Warnings:        meta.Warnings,
		Timings:         meta.Timings,
		Output: contracts.ArtifactOutput{
			Bytes:    meta.Output.Bytes,
			FileName: meta.Output.FileName,
		},
		Debug: contracts.ArtifactDebug{
			ScreenshotFile: meta.Debug.ScreenshotFile,
			DOMSnapshot:    meta.Debug.DOMSnapshot,
			ConsoleLogFile: meta.Debug.ConsoleLogFile,
			NetworkLogFile: meta.Debug.NetworkLogFile,
			ConsoleEvents:  meta.Debug.ConsoleEvents,
			NetworkEvents:  meta.Debug.NetworkEvents,
		},
		Directory: directory,
		Files:     files,
	}, nil
}

func (s *Store) ListDetailed(limit int) (contracts.ArtifactList, error) {
	items, err := s.List()
	if err != nil {
		return contracts.ArtifactList{}, err
	}

	sort.Slice(items, func(left int, right int) bool {
		return compareArtifactCreatedAtDesc(items[left].CreatedAt, items[right].CreatedAt)
	})

	if limit > 0 && len(items) > limit {
		items = items[:limit]
	}

	details := make([]contracts.ArtifactDetail, 0, len(items))
	for _, item := range items {
		detail, err := s.Artifact(item.ID)
		if err != nil {
			return contracts.ArtifactList{}, err
		}

		details = append(details, detail)
	}

	return contracts.ArtifactList{
		ContractVersion: contracts.ArtifactListContractVersion,
		Count:           len(details),
		Items:           details,
	}, nil
}

func (s *Store) List() ([]Entry, error) {
	if strings.TrimSpace(s.root) == "" {
		return []Entry{}, nil
	}

	root := filepath.Join(s.root, "artifacts")
	if err := os.MkdirAll(root, 0o755); err != nil {
		return nil, err
	}

	entries, err := os.ReadDir(root)
	if err != nil {
		return nil, err
	}

	items := make([]Entry, 0, len(entries))
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		directory := filepath.Join(root, entry.Name())
		item := Entry{
			ID:        entry.Name(),
			Directory: directory,
		}

		meta, err := s.readMetadata(filepath.Join(directory, "metadata.json"))
		if err == nil {
			item.CreatedAt = meta.CreatedAt
		}

		items = append(items, item)
	}

	return items, nil
}

func (s *Store) Cleanup(olderThan time.Duration) ([]Entry, error) {
	if olderThan <= 0 {
		return []Entry{}, nil
	}

	items, err := s.List()
	if err != nil {
		return nil, err
	}

	cutoff := time.Now().UTC().Add(-olderThan)
	removed := make([]Entry, 0)

	for _, item := range items {
		createdAt, ok := parseMetadataTime(item.CreatedAt)
		if !ok || createdAt.After(cutoff) {
			continue
		}

		if err := os.RemoveAll(item.Directory); err != nil && !os.IsNotExist(err) {
			return nil, err
		}

		removed = append(removed, item)
	}

	return removed, nil
}

func debugEnabled(spec contracts.RenderSpec) bool {
	return contracts.DebugArtifactsEnabled(spec)
}

func htmlPayload(spec contracts.RenderSpec) string {
	if spec.Source.Type != "html" {
		return ""
	}

	value, _ := spec.Source.Payload["html"].(string)
	return strings.TrimSpace(value)
}

func generateArtifactID(requestID string) string {
	seed := strings.TrimSpace(requestID)
	if seed == "" {
		seed = time.Now().UTC().Format(time.RFC3339Nano)
	}

	sum := sha1.Sum([]byte(seed + ":" + time.Now().UTC().Format(time.RFC3339Nano)))
	return fmt.Sprintf("art-%d-%x", time.Now().UTC().Unix(), sum[:4])
}

func writeJSON(path string, payload any) error {
	data, err := json.MarshalIndent(payload, "", "  ")
	if err != nil {
		return err
	}

	data = append(data, '\n')
	return os.WriteFile(path, data, 0o644)
}

func (s *Store) readMetadata(path string) (metadata, error) {
	var payload metadata

	file, err := os.Open(path)
	if err != nil {
		return payload, err
	}
	defer func() {
		_ = file.Close()
	}()

	err = json.NewDecoder(file).Decode(&payload)
	return payload, err
}

func (s *Store) saveDebugArtifacts(directory string, debugArtifacts *contracts.DebugArtifacts, files map[string]string) (debugMetadata, error) {
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
	files["consoleLog"] = consolePath
	debugMeta.ConsoleLogFile = filepath.Base(consolePath)

	networkPath := filepath.Join(directory, "network-log.json")
	if err := writeJSON(networkPath, debugArtifacts.Network); err != nil {
		return debugMeta, err
	}
	files["networkLog"] = networkPath
	debugMeta.NetworkLogFile = filepath.Base(networkPath)

	if len(debugArtifacts.ScreenshotPNG) > 0 {
		screenshotPath := filepath.Join(directory, "page-screenshot.png")
		if err := os.WriteFile(screenshotPath, debugArtifacts.ScreenshotPNG, 0o644); err != nil {
			return debugMeta, err
		}
		files["pageScreenshot"] = screenshotPath
		debugMeta.ScreenshotFile = filepath.Base(screenshotPath)
	}

	if strings.TrimSpace(debugArtifacts.DOMSnapshot) != "" {
		domPath := filepath.Join(directory, "dom-snapshot.html")
		if err := os.WriteFile(domPath, []byte(debugArtifacts.DOMSnapshot), 0o644); err != nil {
			return debugMeta, err
		}
		files["domSnapshot"] = domPath
		debugMeta.DOMSnapshot = filepath.Base(domPath)
	}

	return debugMeta, nil
}

func parseMetadataTime(value string) (time.Time, bool) {
	parsed, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(value))
	if err == nil {
		return parsed, true
	}

	parsed, err = time.Parse(time.RFC3339, strings.TrimSpace(value))
	return parsed, err == nil
}

func normalizeArtifactID(artifactID string) (string, error) {
	artifactID = strings.TrimSpace(artifactID)
	if artifactID == "" {
		return "", fmt.Errorf("artifact ID is empty")
	}

	if strings.Contains(artifactID, "..") || strings.ContainsRune(artifactID, os.PathSeparator) {
		return "", fmt.Errorf("artifact ID %q is invalid", artifactID)
	}

	return artifactID, nil
}

func artifactFiles(directory string) (map[string]string, error) {
	files := map[string]string{}
	entries, err := os.ReadDir(directory)
	if err != nil {
		if os.IsNotExist(err) {
			return files, fmt.Errorf("%w: %s", ErrArtifactNotFound, filepath.Base(directory))
		}

		return nil, err
	}

	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}

		name := entry.Name()
		path := filepath.Join(directory, name)

		switch name {
		case "render-spec.json":
			files["renderSpec"] = path
		case "metadata.json":
			files["metadata"] = path
		case "source.html":
			files["sourceHtml"] = path
		case "page-screenshot.png":
			files["pageScreenshot"] = path
		case "dom-snapshot.html":
			files["domSnapshot"] = path
		case "console-log.json":
			files["consoleLog"] = path
		case "network-log.json":
			files["networkLog"] = path
		default:
			if strings.HasSuffix(strings.ToLower(name), ".pdf") {
				files["pdf"] = path
			}
		}
	}

	return files, nil
}

func compareArtifactCreatedAtDesc(left string, right string) bool {
	leftTime, leftOK := parseMetadataTime(left)
	rightTime, rightOK := parseMetadataTime(right)

	switch {
	case leftOK && rightOK:
		return leftTime.After(rightTime)
	case leftOK:
		return true
	case rightOK:
		return false
	default:
		return left > right
	}
}
