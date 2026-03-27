package app

import (
	"context"
	"crypto/sha1"
	"encoding/base64"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/artifacts"
	stageauth "github.com/oxhq/canio/runtime/stagehand/internal/auth"
	"github.com/oxhq/canio/runtime/stagehand/internal/browser"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
	"github.com/oxhq/canio/runtime/stagehand/internal/jobs"
	"github.com/oxhq/canio/runtime/stagehand/internal/observability"
	"github.com/oxhq/canio/runtime/stagehand/internal/version"
)

type App struct {
	config        config.RuntimeConfig
	startedAt     time.Time
	renderCount   int
	activeRenders int
	state         string
	renderer      *browser.Renderer
	store         *artifacts.Store
	jobs          *jobs.Manager
	telemetry     *observability.Runtime
	observeStop   context.CancelFunc
	observeWG     sync.WaitGroup
	eventStop     context.CancelFunc
	eventWG       sync.WaitGroup
	mu            sync.Mutex
}

func New(cfg config.RuntimeConfig) *App {
	app := &App{
		config:    cfg,
		startedAt: time.Now().UTC(),
		state:     "ready",
		renderer:  browser.New(cfg),
		store:     artifacts.New(cfg.StateDir),
	}
	app.telemetry = observability.NewRuntime(app.startedAt)

	jobManager, err := jobs.NewManager(jobs.ConfigFromRuntime(cfg), app.executeQueuedRender)
	if err != nil {
		panic(err)
	}

	app.jobs = jobManager
	app.startEventObserver()
	app.startEventWebhookForwarder()

	return app
}

func (a *App) Status() contracts.RuntimeStatus {
	a.mu.Lock()
	defer a.mu.Unlock()

	status := contracts.NewRuntimeStatus(version.Value, a.startedAt, a.renderCount, a.state)
	pool := a.renderer.Status()
	status.BrowserPool.Size = pool.Size
	status.BrowserPool.Warm = pool.Warm
	status.BrowserPool.Busy = pool.Busy
	status.BrowserPool.Starting = pool.Starting
	status.BrowserPool.Waiting = pool.Waiting
	status.BrowserPool.QueueLimit = pool.QueueLimit
	jobStats := a.jobs.Stats()
	readyWorkers := jobStats.WorkerCount - jobStats.BusyWorkers
	if readyWorkers < 0 {
		readyWorkers = 0
	}
	status.WorkerPool.Size = jobStats.WorkerCount
	status.WorkerPool.Warm = readyWorkers
	status.WorkerPool.Busy = jobStats.BusyWorkers
	status.WorkerPool.QueueLimit = jobStats.QueueLimit
	status.Queue.Depth = jobStats.QueueDepth

	return status
}

func (a *App) Restart() contracts.RuntimeStatus {
	a.mu.Lock()
	a.startedAt = time.Now().UTC()
	a.state = "ready"
	a.renderCount = 0
	a.mu.Unlock()

	a.stopEventWebhookForwarder()
	a.stopEventObserver()
	a.jobs.Close()
	a.renderer.Close()
	a.renderer = browser.New(a.config)
	a.store = artifacts.New(a.config.StateDir)
	a.telemetry = observability.NewRuntime(a.startedAt)
	jobManager, err := jobs.NewManager(jobs.ConfigFromRuntime(a.config), a.executeQueuedRender)
	if err != nil {
		panic(err)
	}
	a.jobs = jobManager
	a.startEventObserver()
	a.startEventWebhookForwarder()

	return a.Status()
}

func (a *App) Render(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
	return a.executeRender(ctx, spec, "")
}

func (a *App) Replay(ctx context.Context, artifactID string) (contracts.RenderResult, error) {
	spec, err := a.store.LoadSpec(artifactID)
	if err != nil {
		return contracts.RenderResult{}, err
	}

	return a.executeRender(ctx, spec, artifactID)
}

func (a *App) Artifact(artifactID string) (contracts.ArtifactDetail, error) {
	return a.store.Artifact(artifactID)
}

func (a *App) Artifacts(limit int) (contracts.ArtifactList, error) {
	return a.store.ListDetailed(limit)
}

func (a *App) Dispatch(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderJob, error) {
	if spec.ContractVersion != "" && spec.ContractVersion != contracts.RenderSpecContractVersion {
		return contracts.RenderJob{}, fmt.Errorf("unsupported contractVersion %q", spec.ContractVersion)
	}

	return a.jobs.Submit(ctx, spec)
}

func (a *App) Job(jobID string) (contracts.RenderJob, error) {
	return a.jobs.Get(jobID)
}

func (a *App) Metrics() string {
	return a.telemetry.Prometheus(a.Status())
}

func (a *App) RecordHTTPRequest(method string, route string, status int, duration time.Duration) {
	a.telemetry.RecordHTTPRequest(method, route, status, duration)
}

func (a *App) RequestLoggingEnabled() bool {
	return a.config.RequestLogging
}

func (a *App) Jobs(limit int) contracts.RenderJobList {
	return a.jobs.List(limit)
}

func (a *App) CancelJob(jobID string) (contracts.RenderJob, error) {
	return a.jobs.Cancel(jobID)
}

func (a *App) DeadLetters() (contracts.DeadLetterList, error) {
	return a.jobs.DeadLetters()
}

func (a *App) RequeueDeadLetter(ctx context.Context, deadLetterID string) (contracts.RenderJob, error) {
	return a.jobs.RequeueDeadLetter(ctx, deadLetterID)
}

func (a *App) CleanupDeadLetters(olderThanDays int) (contracts.DeadLetterCleanup, error) {
	var olderThan time.Duration
	if olderThanDays > 0 {
		olderThan = time.Duration(olderThanDays) * 24 * time.Hour
	}

	return a.jobs.CleanupDeadLetters(olderThan)
}

func (a *App) JobEventHistory(jobID string, since uint64) []events.JobEvent {
	all := a.jobs.EventHistorySince(since)
	items := make([]events.JobEvent, 0, len(all))
	for _, event := range all {
		if strings.TrimSpace(event.Job.ID) == strings.TrimSpace(jobID) {
			items = append(items, event)
		}
	}

	return items
}

func (a *App) SubscribeJobEvents(ctx context.Context, buffer int) *events.Subscription {
	return a.jobs.SubscribeEvents(ctx, buffer)
}

func (a *App) RuntimeCleanup(request contracts.RuntimeCleanupRequest) (contracts.RuntimeCleanup, error) {
	var (
		jobsOlderThan      time.Duration
		artifactsOlderThan time.Duration
		deadOlderThan      time.Duration
	)

	if request.JobsOlderThanDays > 0 {
		jobsOlderThan = time.Duration(request.JobsOlderThanDays) * 24 * time.Hour
	}
	if request.ArtifactsOlderThanDays > 0 {
		artifactsOlderThan = time.Duration(request.ArtifactsOlderThanDays) * 24 * time.Hour
	}
	if request.DeadLettersOlderThanDays > 0 {
		deadOlderThan = time.Duration(request.DeadLettersOlderThanDays) * 24 * time.Hour
	}

	removedJobs, err := a.jobs.CleanupJobs(jobsOlderThan)
	if err != nil {
		return contracts.RuntimeCleanup{}, err
	}

	removedArtifacts, err := a.store.Cleanup(defaultDuration(artifactsOlderThan, time.Duration(a.config.ArtifactTTLDays)*24*time.Hour))
	if err != nil {
		return contracts.RuntimeCleanup{}, err
	}

	removedDeadLetters, err := a.jobs.CleanupDeadLetters(deadOlderThan)
	if err != nil {
		return contracts.RuntimeCleanup{}, err
	}

	return contracts.RuntimeCleanup{
		ContractVersion: contracts.RuntimeCleanupContractVersion,
		Jobs: contracts.RuntimeCleanupGroup{
			Count:   len(removedJobs),
			Removed: cleanupEntriesFromJobs(removedJobs),
		},
		Artifacts: contracts.RuntimeCleanupGroup{
			Count:   len(removedArtifacts),
			Removed: cleanupEntriesFromArtifacts(removedArtifacts),
		},
		DeadLetters: contracts.RuntimeCleanupGroup{
			Count:   removedDeadLetters.Count,
			Removed: cleanupEntriesFromDeadLetters(removedDeadLetters.Removed),
		},
	}, nil
}

func (a *App) AuthConfig() stageauth.Config {
	return stageauth.Config{
		Secret:          a.config.AuthSharedSecret,
		Algorithm:       a.config.AuthAlgorithm,
		TimestampHeader: a.config.AuthTimestampHeader,
		SignatureHeader: a.config.AuthSignatureHeader,
		MaxSkew:         time.Duration(a.config.AuthMaxSkewSec) * time.Second,
	}
}

func (a *App) executeRender(ctx context.Context, spec contracts.RenderSpec, replayOf string) (result contracts.RenderResult, err error) {
	if spec.ContractVersion != "" && spec.ContractVersion != contracts.RenderSpecContractVersion {
		return contracts.RenderResult{}, fmt.Errorf("unsupported contractVersion %q", spec.ContractVersion)
	}

	start := time.Now()
	success := false
	defer func() {
		a.telemetry.RecordRender(success, time.Since(start))
		if success {
			return
		}

		if err != nil {
			observability.Error("stagehand_render_failed", err, map[string]any{
				"request_id":  spec.RequestID,
				"profile":     spec.Profile,
				"source":      spec.Source.Type,
				"replay_of":   replayOf,
				"duration_ms": time.Since(start).Milliseconds(),
			})
		}
	}()

	a.mu.Lock()
	a.activeRenders++
	a.state = "rendering_pdf"
	a.mu.Unlock()
	defer a.finishRender()

	pdfBytes, warnings, debugArtifacts, err := a.renderer.Render(ctx, spec)
	if err != nil {
		return contracts.RenderResult{}, err
	}

	a.mu.Lock()
	a.renderCount++
	renderCount := a.renderCount
	a.mu.Unlock()

	fileName := resolveFileName(spec, renderCount)
	result = contracts.RenderResult{
		ContractVersion: contracts.RenderResultContractVersion,
		RequestID:       spec.RequestID,
		JobID:           jobID(spec, renderCount),
		Status:          "completed",
		Warnings:        warnings,
		Timings: map[string]int64{
			"totalMs": time.Since(start).Milliseconds(),
		},
		PDF: contracts.RenderedPDF{
			Base64:      base64.StdEncoding.EncodeToString(pdfBytes),
			ContentType: "application/pdf",
			FileName:    fileName,
			Bytes:       len(pdfBytes),
		},
	}

	bundle, err := a.store.Save(spec, result, pdfBytes, debugArtifacts, replayOf)
	if err != nil {
		return contracts.RenderResult{}, err
	}

	result.Artifacts = bundle
	success = true

	artifactID := ""
	if bundle != nil {
		artifactID = bundle.ID
	}

	observability.Info("stagehand_render_completed", map[string]any{
		"request_id":  spec.RequestID,
		"job_id":      result.JobID,
		"profile":     spec.Profile,
		"source":      spec.Source.Type,
		"duration_ms": time.Since(start).Milliseconds(),
		"artifact_id": artifactID,
	})

	return result, nil
}

func (a *App) Close() {
	a.stopEventWebhookForwarder()
	a.stopEventObserver()
	a.jobs.Close()
	a.renderer.Close()
}

func (a *App) executeQueuedRender(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderResult, error) {
	return a.executeRender(ctx, spec, "")
}

func (a *App) finishRender() {
	a.mu.Lock()
	defer a.mu.Unlock()

	if a.activeRenders > 0 {
		a.activeRenders--
	}

	if a.activeRenders > 0 {
		a.state = "rendering_pdf"
		return
	}

	a.state = "ready"
}

func resolveFileName(spec contracts.RenderSpec, renderCount int) string {
	if value, ok := spec.Output["fileName"].(string); ok && strings.TrimSpace(value) != "" {
		return value
	}

	base := "document"
	if strings.TrimSpace(spec.Profile) != "" {
		base = spec.Profile
	}

	return fmt.Sprintf("%s-%d.pdf", sanitizeFileName(base), renderCount)
}

func jobID(spec contracts.RenderSpec, renderCount int) string {
	seed := spec.RequestID
	if strings.TrimSpace(seed) == "" {
		seed = fmt.Sprintf("render-%d", renderCount)
	}

	sum := sha1.Sum([]byte(seed))
	return fmt.Sprintf("job-%x", sum[:6])
}

func sanitizeFileName(value string) string {
	value = strings.TrimSpace(strings.ToLower(value))
	value = strings.ReplaceAll(value, " ", "-")
	value = strings.ReplaceAll(value, "/", "-")
	value = strings.ReplaceAll(value, "\\", "-")
	value = strings.ReplaceAll(value, "_", "-")
	if value == "" {
		return "document"
	}
	return value
}

func (a *App) startEventWebhookForwarder() {
	a.stopEventWebhookForwarder()

	if strings.TrimSpace(a.config.EventWebhookURL) == "" {
		return
	}

	ctx, cancel := context.WithCancel(context.Background())
	subscription := a.jobs.SubscribeEvents(ctx, 32)
	if subscription == nil {
		cancel()
		return
	}

	dispatcher := events.NewWebhookDispatcher(&http.Client{Timeout: 15 * time.Second})
	a.eventStop = cancel
	a.eventWG.Add(1)

	go func() {
		defer a.eventWG.Done()
		defer subscription.Close()

		for {
			select {
			case <-ctx.Done():
				return
			case event, ok := <-subscription.Events:
				if !ok {
					return
				}

				if !shouldForwardEvent(event.Kind) {
					continue
				}

				delivery, err := dispatcher.Deliver(ctx, events.WebhookTarget{
					URL:    a.config.EventWebhookURL,
					Secret: a.config.EventWebhookSecret,
				}, event)
				success := err == nil && delivery != nil && delivery.Response != nil && delivery.Response.StatusCode >= 200 && delivery.Response.StatusCode < 300
				a.telemetry.RecordWebhook(success)

				fields := map[string]any{
					"event":    string(event.Kind),
					"event_id": event.ID,
					"target":   a.config.EventWebhookURL,
					"success":  success,
				}
				if delivery != nil && delivery.Response != nil {
					fields["status"] = delivery.Response.StatusCode
					_, _ = io.Copy(io.Discard, delivery.Response.Body)
					_ = delivery.Response.Body.Close()
				}

				if err != nil {
					observability.Error("stagehand_webhook_delivery_failed", err, fields)
					continue
				}

				if !success {
					observability.Error("stagehand_webhook_delivery_failed", fmt.Errorf("webhook returned non-success status"), fields)
					continue
				}

				observability.Info("stagehand_webhook_delivery_completed", fields)
			}
		}
	}()
}

func (a *App) stopEventWebhookForwarder() {
	if a.eventStop != nil {
		a.eventStop()
		a.eventStop = nil
		a.eventWG.Wait()
	}
}

func shouldForwardEvent(kind events.Kind) bool {
	switch kind {
	case events.JobCompleted, events.JobFailed, events.JobRetried, events.JobCancelled:
		return true
	default:
		return false
	}
}

func (a *App) startEventObserver() {
	a.stopEventObserver()

	ctx, cancel := context.WithCancel(context.Background())
	subscription := a.jobs.SubscribeEvents(ctx, 64)
	if subscription == nil {
		cancel()
		return
	}

	a.observeStop = cancel
	a.observeWG.Add(1)

	go func() {
		defer a.observeWG.Done()
		defer subscription.Close()

		for {
			select {
			case <-ctx.Done():
				return
			case event, ok := <-subscription.Events:
				if !ok {
					return
				}

				a.telemetry.RecordJobEvent(event.Kind)
				observability.Info("stagehand_job_event", map[string]any{
					"kind":       string(event.Kind),
					"job_id":     event.Job.ID,
					"request_id": event.Job.RequestID,
					"status":     event.Job.Status,
					"queue":      event.Queue,
					"attempts":   event.Job.Attempts,
				})
			}
		}
	}()
}

func (a *App) stopEventObserver() {
	if a.observeStop != nil {
		a.observeStop()
		a.observeStop = nil
		a.observeWG.Wait()
	}
}

func cleanupEntriesFromJobs(items []jobs.CleanupEntry) []contracts.RuntimeCleanupEntry {
	entries := make([]contracts.RuntimeCleanupEntry, 0, len(items))
	for _, item := range items {
		entries = append(entries, contracts.RuntimeCleanupEntry{
			ID:        item.ID,
			Directory: item.Directory,
		})
	}

	return entries
}

func cleanupEntriesFromArtifacts(items []artifacts.Entry) []contracts.RuntimeCleanupEntry {
	entries := make([]contracts.RuntimeCleanupEntry, 0, len(items))
	for _, item := range items {
		entries = append(entries, contracts.RuntimeCleanupEntry{
			ID:        item.ID,
			Directory: item.Directory,
		})
	}

	return entries
}

func cleanupEntriesFromDeadLetters(items []contracts.DeadLetterEntry) []contracts.RuntimeCleanupEntry {
	entries := make([]contracts.RuntimeCleanupEntry, 0, len(items))
	for _, item := range items {
		entries = append(entries, contracts.RuntimeCleanupEntry{
			ID:        item.ID,
			Directory: item.Directory,
		})
	}

	return entries
}

func defaultDuration(value time.Duration, fallback time.Duration) time.Duration {
	if value > 0 {
		return value
	}

	return fallback
}
