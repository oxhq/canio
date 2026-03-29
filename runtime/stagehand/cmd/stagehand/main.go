package main

import (
	"bytes"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	appruntime "github.com/oxhq/canio/runtime/stagehand/internal/app"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/httpserver"
	"github.com/oxhq/canio/runtime/stagehand/internal/jobs"
	"github.com/oxhq/canio/runtime/stagehand/internal/observability"
	"github.com/oxhq/canio/runtime/stagehand/internal/version"
)

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	if len(args) == 0 {
		printUsage()
		return 1
	}

	switch args[0] {
	case "serve":
		return runServe(args[1:])
	case "render":
		return runRender(args[1:])
	case "replay":
		return runReplay(args[1:])
	case "deadletters":
		return runDeadLetters(args[1:])
	case "cleanup":
		return runCleanup(args[1:])
	case "version":
		fmt.Printf("stagehand version %s\n", version.Value)
		return 0
	default:
		printUsage()
		return 1
	}
}

func runServe(args []string) int {
	cfg := config.Default()

	fs := flag.NewFlagSet("serve", flag.ContinueOnError)
	fs.StringVar(&cfg.Host, "host", cfg.Host, "Host to listen on")
	fs.IntVar(&cfg.Port, "port", cfg.Port, "Port to listen on")
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state")
	fs.StringVar(&cfg.LogFile, "log-file", "", "Optional log file path")
	fs.StringVar(&cfg.LogFormat, "log-format", cfg.LogFormat, "Structured log output format: json or text")
	fs.BoolVar(&cfg.RequestLogging, "request-logging", cfg.RequestLogging, "Emit one structured log line per HTTP request")
	fs.StringVar(&cfg.ChromiumPath, "chromium-path", "", "Optional Chromium or Chrome executable path")
	fs.StringVar(&cfg.UserDataDir, "user-data-dir", "", "Optional Chromium user data directory")
	fs.BoolVar(&cfg.Headless, "headless", cfg.Headless, "Run Chromium in headless mode")
	fs.BoolVar(&cfg.DisableSandbox, "no-sandbox", cfg.DisableSandbox, "Disable Chromium sandboxing for CI or rootless containers")
	fs.BoolVar(&cfg.IgnoreHTTPSErrors, "ignore-https-errors", cfg.IgnoreHTTPSErrors, "Ignore certificate errors for local/self-signed environments")
	fs.StringVar(&cfg.AuthSharedSecret, "auth-shared-secret", cfg.AuthSharedSecret, "Shared secret used to sign Stagehand requests")
	fs.StringVar(&cfg.AuthAlgorithm, "auth-algorithm", cfg.AuthAlgorithm, "Request signing algorithm identifier")
	fs.StringVar(&cfg.AuthTimestampHeader, "auth-timestamp-header", cfg.AuthTimestampHeader, "Header used for request timestamps")
	fs.StringVar(&cfg.AuthSignatureHeader, "auth-signature-header", cfg.AuthSignatureHeader, "Header used for request signatures")
	fs.IntVar(&cfg.AuthMaxSkewSec, "auth-max-skew", cfg.AuthMaxSkewSec, "Maximum clock skew in seconds allowed for signed requests")
	fs.StringVar(&cfg.EventWebhookURL, "event-webhook-url", cfg.EventWebhookURL, "Optional webhook URL that receives async job lifecycle callbacks")
	fs.StringVar(&cfg.EventWebhookSecret, "event-webhook-secret", cfg.EventWebhookSecret, "Optional webhook signing secret for async job callbacks")
	applyPoolFlags(fs, &cfg)

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse serve flags: %v", err)
		return 1
	}

	if err := ensureDirectories(cfg); err != nil {
		log.Printf("failed to prepare runtime directories: %v", err)
		return 1
	}

	app := appruntime.New(cfg)
	defer app.Close()
	server := &http.Server{
		Addr:    cfg.Address(),
		Handler: httpserver.New(app),
	}

	observability.Info("stagehand_server_listening", map[string]any{
		"address":         cfg.Address(),
		"state_dir":       cfg.StateDir,
		"job_backend":     cfg.JobBackend,
		"browser_pool":    cfg.BrowserPoolSize,
		"worker_count":    cfg.JobWorkerCount,
		"log_format":      cfg.LogFormat,
		"request_logging": cfg.RequestLogging,
	})

	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		observability.Error("stagehand_server_failed", err, map[string]any{
			"address": cfg.Address(),
		})
		return 1
	}

	return 0
}

func runRender(args []string) int {
	cfg := config.Default()
	fs := flag.NewFlagSet("render", flag.ContinueOnError)
	requestPath := fs.String("request", "-", "Path to the render request JSON file, or - for stdin")
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state and artifacts")
	fs.StringVar(&cfg.LogFile, "log-file", "", "Optional log file path")
	fs.StringVar(&cfg.LogFormat, "log-format", cfg.LogFormat, "Structured log output format: json or text")
	fs.StringVar(&cfg.ChromiumPath, "chromium-path", "", "Optional Chromium or Chrome executable path")
	fs.StringVar(&cfg.UserDataDir, "user-data-dir", "", "Optional Chromium user data directory")
	fs.BoolVar(&cfg.Headless, "headless", cfg.Headless, "Run Chromium in headless mode")
	fs.BoolVar(&cfg.DisableSandbox, "no-sandbox", cfg.DisableSandbox, "Disable Chromium sandboxing for CI or rootless containers")
	fs.BoolVar(&cfg.IgnoreHTTPSErrors, "ignore-https-errors", cfg.IgnoreHTTPSErrors, "Ignore certificate errors for local/self-signed environments")
	fs.StringVar(&cfg.AuthSharedSecret, "auth-shared-secret", cfg.AuthSharedSecret, "Shared secret used to sign Stagehand requests")
	fs.StringVar(&cfg.AuthAlgorithm, "auth-algorithm", cfg.AuthAlgorithm, "Request signing algorithm identifier")
	fs.StringVar(&cfg.AuthTimestampHeader, "auth-timestamp-header", cfg.AuthTimestampHeader, "Header used for request timestamps")
	fs.StringVar(&cfg.AuthSignatureHeader, "auth-signature-header", cfg.AuthSignatureHeader, "Header used for request signatures")
	fs.IntVar(&cfg.AuthMaxSkewSec, "auth-max-skew", cfg.AuthMaxSkewSec, "Maximum clock skew in seconds allowed for signed requests")
	applyPoolFlags(fs, &cfg)

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse render flags: %v", err)
		return 1
	}

	reader, closeFn, err := openRequestReader(*requestPath)
	if err != nil {
		log.Printf("failed to open render request: %v", err)
		return 1
	}
	defer closeFn()

	spec, err := contracts.DecodeRenderSpec(reader)
	if err != nil {
		log.Printf("failed to decode render request: %v", err)
		return 1
	}

	app := appruntime.New(cfg)
	defer app.Close()

	result, err := app.Render(context.Background(), spec)
	if err != nil {
		log.Printf("failed to render document: %v", err)
		return 1
	}

	if err := contracts.EncodeJSON(os.Stdout, result); err != nil {
		log.Printf("failed to write render result: %v", err)
		return 1
	}

	return 0
}

func runReplay(args []string) int {
	cfg := config.Default()
	fs := flag.NewFlagSet("replay", flag.ContinueOnError)
	artifactID := fs.String("artifact-id", "", "Artifact identifier to replay")
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state and artifacts")
	fs.StringVar(&cfg.LogFile, "log-file", "", "Optional log file path")
	fs.StringVar(&cfg.LogFormat, "log-format", cfg.LogFormat, "Structured log output format: json or text")
	fs.StringVar(&cfg.ChromiumPath, "chromium-path", "", "Optional Chromium or Chrome executable path")
	fs.StringVar(&cfg.UserDataDir, "user-data-dir", "", "Optional Chromium user data directory")
	fs.BoolVar(&cfg.Headless, "headless", cfg.Headless, "Run Chromium in headless mode")
	fs.BoolVar(&cfg.DisableSandbox, "no-sandbox", cfg.DisableSandbox, "Disable Chromium sandboxing for CI or rootless containers")
	fs.BoolVar(&cfg.IgnoreHTTPSErrors, "ignore-https-errors", cfg.IgnoreHTTPSErrors, "Ignore certificate errors for local/self-signed environments")
	applyPoolFlags(fs, &cfg)

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse replay flags: %v", err)
		return 1
	}

	if strings.TrimSpace(*artifactID) == "" {
		log.Printf("replay requires --artifact-id")
		return 1
	}

	if err := ensureDirectories(cfg); err != nil {
		log.Printf("failed to prepare runtime directories: %v", err)
		return 1
	}

	app := appruntime.New(cfg)
	defer app.Close()

	result, err := app.Replay(context.Background(), *artifactID)
	if err != nil {
		log.Printf("failed to replay artifact: %v", err)
		return 1
	}

	if err := contracts.EncodeJSON(os.Stdout, result); err != nil {
		log.Printf("failed to write replay result: %v", err)
		return 1
	}

	return 0
}

func runDeadLetters(args []string) int {
	if len(args) == 0 {
		fmt.Println("stagehand deadletters <list|requeue|cleanup>")
		return 1
	}

	switch args[0] {
	case "list":
		return runDeadLettersList(args[1:])
	case "requeue":
		return runDeadLettersRequeue(args[1:])
	case "cleanup":
		return runDeadLettersCleanup(args[1:])
	default:
		fmt.Println("stagehand deadletters <list|requeue|cleanup>")
		return 1
	}
}

func runCleanup(args []string) int {
	cfg := config.Default()
	fs := flag.NewFlagSet("cleanup", flag.ContinueOnError)
	jobsOlderThanDays := fs.Int("jobs-older-than-days", cfg.JobTTLDays, "Delete completed, failed, or cancelled jobs older than this many days")
	artifactsOlderThanDays := fs.Int("artifacts-older-than-days", cfg.ArtifactTTLDays, "Delete persisted artifacts older than this many days")
	deadLettersOlderThanDays := fs.Int("dead-letters-older-than-days", cfg.DeadLetterTTLDays, "Delete dead-letters older than this many days")
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state")
	fs.StringVar(&cfg.LogFile, "log-file", "", "Optional log file path")
	fs.StringVar(&cfg.LogFormat, "log-format", cfg.LogFormat, "Structured log output format: json or text")

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse cleanup flags: %v", err)
		return 1
	}

	app := appruntime.New(cfg)
	defer app.Close()

	result, err := app.RuntimeCleanup(contracts.RuntimeCleanupRequest{
		JobsOlderThanDays:        *jobsOlderThanDays,
		ArtifactsOlderThanDays:   *artifactsOlderThanDays,
		DeadLettersOlderThanDays: *deadLettersOlderThanDays,
	})
	if err != nil {
		log.Printf("failed to cleanup runtime state: %v", err)
		return 1
	}

	if err := contracts.EncodeJSON(os.Stdout, result); err != nil {
		log.Printf("failed to write cleanup result: %v", err)
		return 1
	}

	return 0
}

func runDeadLettersList(args []string) int {
	cfg := config.Default()
	fs := flag.NewFlagSet("deadletters-list", flag.ContinueOnError)
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state")
	fs.StringVar(&cfg.LogFile, "log-file", "", "Optional log file path")
	fs.StringVar(&cfg.LogFormat, "log-format", cfg.LogFormat, "Structured log output format: json or text")

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse deadletters list flags: %v", err)
		return 1
	}

	store := jobs.NewStore(cfg.StateDir)
	items, err := store.ListDeadLetters()
	if err != nil {
		log.Printf("failed to list dead letters: %v", err)
		return 1
	}

	payload := contracts.DeadLetterList{
		ContractVersion: contracts.DeadLetterListContractVersion,
		Count:           len(items),
		Items:           items,
	}

	if err := contracts.EncodeJSON(os.Stdout, payload); err != nil {
		log.Printf("failed to write dead-letters list: %v", err)
		return 1
	}

	return 0
}

func runDeadLettersRequeue(args []string) int {
	fs := flag.NewFlagSet("deadletters-requeue", flag.ContinueOnError)
	deadLetterID := fs.String("id", "", "Dead-letter identifier to requeue")
	baseURL := fs.String("base-url", "http://127.0.0.1:9514", "Base URL for a running Stagehand daemon")

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse deadletters requeue flags: %v", err)
		return 1
	}

	if strings.TrimSpace(*deadLetterID) == "" {
		log.Printf("deadletters requeue requires --id")
		return 1
	}

	body, err := json.Marshal(contracts.DeadLetterRequeueRequest{DeadLetterID: *deadLetterID})
	if err != nil {
		log.Printf("failed to encode dead-letter requeue request: %v", err)
		return 1
	}

	response, err := http.Post(strings.TrimRight(*baseURL, "/")+"/v1/dead-letters/requeues", "application/json", bytes.NewReader(body))
	if err != nil {
		log.Printf("failed to reach Stagehand daemon: %v", err)
		return 1
	}
	defer func() {
		_ = response.Body.Close()
	}()

	if response.StatusCode >= 400 {
		payload, _ := io.ReadAll(response.Body)
		log.Printf("dead-letter requeue failed with status %d: %s", response.StatusCode, strings.TrimSpace(string(payload)))
		return 1
	}

	job, err := io.ReadAll(response.Body)
	if err != nil {
		log.Printf("failed to read dead-letter requeue response: %v", err)
		return 1
	}

	if _, err := os.Stdout.Write(job); err != nil {
		log.Printf("failed to write dead-letter requeue response: %v", err)
		return 1
	}

	return 0
}

func runDeadLettersCleanup(args []string) int {
	cfg := config.Default()
	fs := flag.NewFlagSet("deadletters-cleanup", flag.ContinueOnError)
	fs.StringVar(&cfg.StateDir, "state-dir", "", "Directory for runtime state")
	olderThanDays := fs.Int("older-than-days", cfg.DeadLetterTTLDays, "Delete dead-letters older than this many days")

	if err := fs.Parse(args); err != nil {
		log.Printf("failed to parse deadletters cleanup flags: %v", err)
		return 1
	}

	store := jobs.NewStore(cfg.StateDir)
	removed, err := store.CleanupDeadLetters(time.Duration(*olderThanDays) * 24 * time.Hour)
	if err != nil {
		log.Printf("failed to cleanup dead letters: %v", err)
		return 1
	}

	payload := contracts.DeadLetterCleanup{
		ContractVersion: contracts.DeadLetterCleanupContractVersion,
		Count:           len(removed),
		Removed:         removed,
	}

	if err := contracts.EncodeJSON(os.Stdout, payload); err != nil {
		log.Printf("failed to write dead-letter cleanup result: %v", err)
		return 1
	}

	return 0
}

func openRequestReader(path string) (io.ReadCloser, func(), error) {
	if path == "-" {
		return io.NopCloser(os.Stdin), func() {}, nil
	}

	file, err := os.Open(path)
	if err != nil {
		return nil, func() {}, err
	}

	return file, func() {
		_ = file.Close()
	}, nil
}

func ensureDirectories(cfg config.RuntimeConfig) error {
	if cfg.StateDir != "" {
		if err := os.MkdirAll(cfg.StateDir, 0o755); err != nil {
			return err
		}
	}

	if cfg.LogFile != "" {
		if err := os.MkdirAll(filepath.Dir(cfg.LogFile), 0o755); err != nil {
			return err
		}

		file, err := os.OpenFile(cfg.LogFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
		if err != nil {
			return err
		}

		log.SetOutput(io.MultiWriter(os.Stdout, file))
	} else {
		log.SetOutput(os.Stdout)
	}
	log.SetFlags(0)
	observability.SetLogFormat(cfg.LogFormat)

	if cfg.UserDataDir != "" {
		if err := os.MkdirAll(cfg.UserDataDir, 0o755); err != nil {
			return err
		}
	}

	return nil
}

func applyPoolFlags(fs *flag.FlagSet, cfg *config.RuntimeConfig) {
	fs.IntVar(&cfg.BrowserPoolSize, "browser-pool-size", cfg.BrowserPoolSize, "Maximum number of warm browser processes kept by the runtime")
	fs.IntVar(&cfg.BrowserPoolWarm, "browser-pool-warm", cfg.BrowserPoolWarm, "How many browser processes to prewarm on startup")
	fs.IntVar(&cfg.BrowserQueueDepth, "browser-queue-depth", cfg.BrowserQueueDepth, "Maximum number of renders allowed to wait for a browser slot")
	fs.IntVar(&cfg.AcquireTimeoutSec, "browser-acquire-timeout", cfg.AcquireTimeoutSec, "Seconds a render can wait for a browser slot before timing out")
	fs.IntVar(&cfg.ReadyPollIntervalMs, "ready-poll-interval-ms", cfg.ReadyPollIntervalMs, "Polling interval in milliseconds while waiting for page readiness")
	fs.IntVar(&cfg.ReadySettleFrames, "ready-settle-frames", cfg.ReadySettleFrames, "Animation frames to wait after readiness before printing")
	fs.StringVar(&cfg.JobBackend, "job-backend", cfg.JobBackend, "Async jobs backend: memory or redis")
	fs.IntVar(&cfg.JobWorkerCount, "job-workers", cfg.JobWorkerCount, "Number of background job workers available for async renders")
	fs.IntVar(&cfg.JobQueueDepth, "job-queue-depth", cfg.JobQueueDepth, "Maximum number of async jobs buffered by the runtime queue")
	fs.IntVar(&cfg.JobLeaseTimeoutSec, "job-lease-timeout", cfg.JobLeaseTimeoutSec, "Seconds a Redis-backed job lease stays valid without a heartbeat before another worker can reclaim it")
	fs.IntVar(&cfg.JobHeartbeatSec, "job-heartbeat-interval", cfg.JobHeartbeatSec, "Seconds between Redis job heartbeats while a worker is actively rendering")
	fs.IntVar(&cfg.JobTTLDays, "job-ttl-days", cfg.JobTTLDays, "Default number of days completed, failed, or cancelled jobs are kept before cleanup")
	fs.IntVar(&cfg.DeadLetterTTLDays, "job-dead-letter-ttl-days", cfg.DeadLetterTTLDays, "Default number of days dead-letters are kept before cleanup")
	fs.IntVar(&cfg.ArtifactTTLDays, "artifact-ttl-days", cfg.ArtifactTTLDays, "Default number of days persisted artifacts are kept before cleanup")
	fs.StringVar(&cfg.RedisHost, "job-redis-host", cfg.RedisHost, "Redis host for the jobs backend")
	fs.IntVar(&cfg.RedisPort, "job-redis-port", cfg.RedisPort, "Redis port for the jobs backend")
	fs.StringVar(&cfg.RedisPassword, "job-redis-password", cfg.RedisPassword, "Redis password for the jobs backend")
	fs.IntVar(&cfg.RedisDB, "job-redis-db", cfg.RedisDB, "Redis database for the jobs backend")
	fs.StringVar(&cfg.RedisQueueKey, "job-redis-queue-key", cfg.RedisQueueKey, "Redis list key used by the jobs backend")
	fs.IntVar(&cfg.RedisBlockTimeout, "job-redis-block-timeout", cfg.RedisBlockTimeout, "Seconds each Redis worker blocks while waiting for a queued job")
}

func printUsage() {
	fmt.Println("stagehand <serve|render|replay|deadletters|cleanup|version>")
}
