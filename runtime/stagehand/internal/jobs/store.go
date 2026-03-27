package jobs

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

type Store struct {
	root           string
	deadLetterRoot string
}

type Record struct {
	Job  contracts.RenderJob
	Spec contracts.RenderSpec
}

type CleanupEntry struct {
	ID        string
	Directory string
}

func NewStore(stateDir string) *Store {
	base := strings.TrimSpace(stateDir)
	root := ""
	deadLetterRoot := ""
	if base != "" {
		root = filepath.Join(base, "jobs")
		deadLetterRoot = filepath.Join(base, "deadletters")
	}

	return &Store{
		root:           root,
		deadLetterRoot: deadLetterRoot,
	}
}

func (s *Store) Enabled() bool {
	return s.root != ""
}

func (s *Store) Save(record Record) error {
	if !s.Enabled() {
		return nil
	}

	dir, err := s.jobDirectory(record.Job.ID)
	if err != nil {
		return err
	}

	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}

	if err := writeJSON(filepath.Join(dir, "job.json"), record.Job); err != nil {
		return err
	}

	return writeJSON(filepath.Join(dir, "render-spec.json"), record.Spec)
}

func (s *Store) SaveJob(job contracts.RenderJob) error {
	if !s.Enabled() {
		return nil
	}

	dir, err := s.jobDirectory(job.ID)
	if err != nil {
		return err
	}

	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}

	return writeJSON(filepath.Join(dir, "job.json"), job)
}

func (s *Store) Delete(jobID string) error {
	if !s.Enabled() {
		return nil
	}

	dir, err := s.jobDirectory(jobID)
	if err != nil {
		return err
	}

	if err := os.RemoveAll(dir); err != nil && !os.IsNotExist(err) {
		return err
	}

	return nil
}

func (s *Store) Load(jobID string) (Record, error) {
	var record Record

	dir, err := s.jobDirectory(jobID)
	if err != nil {
		return record, err
	}

	jobFile, err := os.Open(filepath.Join(dir, "job.json"))
	if err != nil {
		return record, err
	}
	defer func() {
		_ = jobFile.Close()
	}()

	if err := json.NewDecoder(jobFile).Decode(&record.Job); err != nil {
		return record, err
	}

	specFile, err := os.Open(filepath.Join(dir, "render-spec.json"))
	if err != nil {
		return record, err
	}
	defer func() {
		_ = specFile.Close()
	}()

	record.Spec, err = contracts.DecodeRenderSpec(specFile)
	return record, err
}

func (s *Store) LoadAll() ([]Record, error) {
	if !s.Enabled() {
		return nil, nil
	}

	if err := os.MkdirAll(s.root, 0o755); err != nil {
		return nil, err
	}

	entries, err := os.ReadDir(s.root)
	if err != nil {
		return nil, err
	}

	records := make([]Record, 0, len(entries))
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		record, err := s.Load(entry.Name())
		if err != nil {
			return nil, err
		}

		records = append(records, record)
	}

	slices.SortFunc(records, func(left Record, right Record) int {
		return strings.Compare(left.Job.SubmittedAt, right.Job.SubmittedAt)
	})

	return records, nil
}

func (s *Store) CleanupJobs(olderThan time.Duration) ([]CleanupEntry, error) {
	if !s.Enabled() || olderThan <= 0 {
		return []CleanupEntry{}, nil
	}

	records, err := s.LoadAll()
	if err != nil {
		return nil, err
	}

	cutoff := time.Now().UTC().Add(-olderThan)
	removed := make([]CleanupEntry, 0)

	for _, record := range records {
		if !jobEligibleForCleanup(record.Job) {
			continue
		}

		completedAt, ok := parseDeadLetterTime(record.Job.CompletedAt)
		if !ok || completedAt.After(cutoff) {
			continue
		}

		directory, err := s.jobDirectory(record.Job.ID)
		if err != nil {
			return nil, err
		}

		if err := s.Delete(record.Job.ID); err != nil {
			return nil, err
		}

		removed = append(removed, CleanupEntry{
			ID:        record.Job.ID,
			Directory: directory,
		})
	}

	return removed, nil
}

func (s *Store) SaveDeadLetter(record Record, reason string) (*contracts.DeadLetterBundle, error) {
	if !s.Enabled() || strings.TrimSpace(s.deadLetterRoot) == "" {
		return nil, nil
	}

	dir, err := s.deadLetterDirectory(record.Job.ID)
	if err != nil {
		return nil, err
	}

	if err := os.MkdirAll(dir, 0o755); err != nil {
		return nil, err
	}

	jobPath := filepath.Join(dir, "job.json")
	if err := writeJSON(jobPath, record.Job); err != nil {
		return nil, err
	}

	specPath := filepath.Join(dir, "render-spec.json")
	if err := writeJSON(specPath, record.Spec); err != nil {
		return nil, err
	}

	metadataPath := filepath.Join(dir, "dead-letter.json")
	if err := writeJSON(metadataPath, map[string]any{
		"jobId":      record.Job.ID,
		"requestId":  record.Job.RequestID,
		"failedAt":   record.Job.CompletedAt,
		"attempts":   record.Job.Attempts,
		"maxRetries": record.Job.MaxRetries,
		"error":      strings.TrimSpace(reason),
	}); err != nil {
		return nil, err
	}

	return &contracts.DeadLetterBundle{
		ID:        "dlq-" + record.Job.ID,
		Directory: dir,
		Files: map[string]string{
			"job":        jobPath,
			"renderSpec": specPath,
			"metadata":   metadataPath,
		},
	}, nil
}

func (s *Store) LoadDeadLetter(deadLetterID string) (Record, contracts.DeadLetterEntry, error) {
	var (
		record Record
		entry  contracts.DeadLetterEntry
	)

	if !s.Enabled() || strings.TrimSpace(s.deadLetterRoot) == "" {
		return record, entry, os.ErrNotExist
	}

	jobID := normalizeDeadLetterJobID(deadLetterID)
	dir, err := s.deadLetterDirectory(jobID)
	if err != nil {
		return record, entry, err
	}

	record, err = loadRecordDirectory(dir)
	if err != nil {
		return record, entry, err
	}

	metadata, err := readJSONMap(filepath.Join(dir, "dead-letter.json"))
	if err != nil && !os.IsNotExist(err) {
		return record, entry, err
	}

	entry = buildDeadLetterEntry(jobID, dir, record, metadata)
	return record, entry, nil
}

func (s *Store) ListDeadLetters() ([]contracts.DeadLetterEntry, error) {
	if !s.Enabled() || strings.TrimSpace(s.deadLetterRoot) == "" {
		return []contracts.DeadLetterEntry{}, nil
	}

	if err := os.MkdirAll(s.deadLetterRoot, 0o755); err != nil {
		return nil, err
	}

	entries, err := os.ReadDir(s.deadLetterRoot)
	if err != nil {
		return nil, err
	}

	items := make([]contracts.DeadLetterEntry, 0, len(entries))
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		_, item, err := s.LoadDeadLetter(entry.Name())
		if err != nil {
			return nil, err
		}

		items = append(items, item)
	}

	slices.SortFunc(items, func(left contracts.DeadLetterEntry, right contracts.DeadLetterEntry) int {
		return strings.Compare(right.FailedAt, left.FailedAt)
	})

	return items, nil
}

func (s *Store) DeleteDeadLetter(deadLetterID string) error {
	if !s.Enabled() || strings.TrimSpace(s.deadLetterRoot) == "" {
		return nil
	}

	dir, err := s.deadLetterDirectory(normalizeDeadLetterJobID(deadLetterID))
	if err != nil {
		return err
	}

	if err := os.RemoveAll(dir); err != nil && !os.IsNotExist(err) {
		return err
	}

	return nil
}

func (s *Store) CleanupDeadLetters(olderThan time.Duration) ([]contracts.DeadLetterEntry, error) {
	if olderThan <= 0 {
		return []contracts.DeadLetterEntry{}, nil
	}

	items, err := s.ListDeadLetters()
	if err != nil {
		return nil, err
	}

	cutoff := time.Now().UTC().Add(-olderThan)
	removed := make([]contracts.DeadLetterEntry, 0)

	for _, item := range items {
		failedAt, ok := parseDeadLetterTime(item.FailedAt)
		if !ok || failedAt.After(cutoff) {
			continue
		}

		if err := s.DeleteDeadLetter(item.ID); err != nil {
			return nil, err
		}

		removed = append(removed, item)
	}

	return removed, nil
}

func (s *Store) jobDirectory(jobID string) (string, error) {
	return childDirectory(s.root, jobID, "job")
}

func (s *Store) deadLetterDirectory(jobID string) (string, error) {
	return childDirectory(s.deadLetterRoot, jobID, "dead letter")
}

func childDirectory(root string, entryID string, label string) (string, error) {
	entryID = strings.TrimSpace(entryID)
	if entryID == "" {
		return "", fmt.Errorf("%s ID is empty", label)
	}

	if strings.Contains(entryID, "..") || strings.ContainsRune(entryID, os.PathSeparator) {
		return "", fmt.Errorf("%s ID %q is invalid", label, entryID)
	}

	return filepath.Join(root, entryID), nil
}

func writeJSON(path string, payload any) error {
	data, err := json.MarshalIndent(payload, "", "  ")
	if err != nil {
		return err
	}

	data = append(data, '\n')
	return os.WriteFile(path, data, 0o644)
}

func (s *Store) DeadLetterDirectory(jobID string) (string, error) {
	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		return "", fmt.Errorf("job ID is empty")
	}

	if strings.Contains(jobID, "..") || strings.ContainsRune(jobID, os.PathSeparator) {
		return "", fmt.Errorf("job ID %q is invalid", jobID)
	}

	return filepath.Join(s.deadLetterRoot, jobID), nil
}

func loadRecordDirectory(dir string) (Record, error) {
	var record Record

	jobFile, err := os.Open(filepath.Join(dir, "job.json"))
	if err != nil {
		return record, err
	}
	defer func() {
		_ = jobFile.Close()
	}()

	if err := json.NewDecoder(jobFile).Decode(&record.Job); err != nil {
		return record, err
	}

	specFile, err := os.Open(filepath.Join(dir, "render-spec.json"))
	if err != nil {
		return record, err
	}
	defer func() {
		_ = specFile.Close()
	}()

	record.Spec, err = contracts.DecodeRenderSpec(specFile)
	return record, err
}

func readJSONMap(path string) (map[string]any, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer func() {
		_ = file.Close()
	}()

	var payload map[string]any
	if err := json.NewDecoder(file).Decode(&payload); err != nil {
		return nil, err
	}

	return payload, nil
}

func buildDeadLetterEntry(jobID string, dir string, record Record, metadata map[string]any) contracts.DeadLetterEntry {
	files := map[string]string{
		"job":        filepath.Join(dir, "job.json"),
		"renderSpec": filepath.Join(dir, "render-spec.json"),
		"metadata":   filepath.Join(dir, "dead-letter.json"),
	}

	failedAt := stringValue(metadata["failedAt"])
	if failedAt == "" {
		failedAt = record.Job.CompletedAt
	}

	errMessage := stringValue(metadata["error"])
	if errMessage == "" {
		errMessage = record.Job.Error
	}

	return contracts.DeadLetterEntry{
		ID:         "dlq-" + jobID,
		JobID:      record.Job.ID,
		RequestID:  record.Job.RequestID,
		Attempts:   record.Job.Attempts,
		MaxRetries: record.Job.MaxRetries,
		FailedAt:   failedAt,
		Error:      errMessage,
		Directory:  dir,
		Files:      files,
	}
}

func normalizeDeadLetterJobID(value string) string {
	value = strings.TrimSpace(value)
	value = strings.TrimPrefix(value, "dlq-")
	return value
}

func stringValue(value any) string {
	switch typed := value.(type) {
	case string:
		return strings.TrimSpace(typed)
	default:
		return ""
	}
}

func parseDeadLetterTime(value string) (time.Time, bool) {
	parsed, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(value))
	if err == nil {
		return parsed, true
	}

	parsed, err = time.Parse(time.RFC3339, strings.TrimSpace(value))
	return parsed, err == nil
}

func jobEligibleForCleanup(job contracts.RenderJob) bool {
	switch strings.TrimSpace(job.Status) {
	case "completed", "failed", "cancelled":
		return true
	default:
		return false
	}
}
