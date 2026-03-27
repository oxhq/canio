package jobs

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/config"
)

const defaultLogicalQueue = "default"

type queueBackend interface {
	Enqueue(context.Context, string, string) error
	Dequeue(context.Context, []string) (Delivery, error)
	Ack(context.Context, Delivery) error
	Heartbeat(context.Context, Delivery) error
	HeartbeatInterval() time.Duration
	Depth(context.Context, []string) int
	Limit() int
	Close() error
}

type Delivery struct {
	JobID     string
	QueueName string
	streamKey string
	messageID string
}

type Config struct {
	StateDir      string
	Workers       int
	JobTTL        time.Duration
	DeadLetterTTL time.Duration
	Queue         QueueConfig
}

type QueueConfig struct {
	Backend           string
	Depth             int
	LeaseTimeout      time.Duration
	HeartbeatInterval time.Duration
	Redis             RedisConfig
}

type RedisConfig struct {
	Host         string
	Port         int
	Password     string
	DB           int
	QueueKey     string
	BlockTimeout time.Duration
}

func ConfigFromRuntime(cfg config.RuntimeConfig) Config {
	return Config{
		StateDir:      cfg.StateDir,
		Workers:       cfg.JobWorkerCount,
		JobTTL:        time.Duration(cfg.JobTTLDays) * 24 * time.Hour,
		DeadLetterTTL: time.Duration(cfg.DeadLetterTTLDays) * 24 * time.Hour,
		Queue: QueueConfig{
			Backend:           cfg.JobBackend,
			Depth:             cfg.JobQueueDepth,
			LeaseTimeout:      time.Duration(cfg.JobLeaseTimeoutSec) * time.Second,
			HeartbeatInterval: time.Duration(cfg.JobHeartbeatSec) * time.Second,
			Redis: RedisConfig{
				Host:         cfg.RedisHost,
				Port:         cfg.RedisPort,
				Password:     cfg.RedisPassword,
				DB:           cfg.RedisDB,
				QueueKey:     cfg.RedisQueueKey,
				BlockTimeout: time.Duration(cfg.RedisBlockTimeout) * time.Second,
			},
		},
	}
}

func newQueueBackend(cfg QueueConfig) (queueBackend, error) {
	switch configuredBackendName(cfg.Backend) {
	case "memory":
		return newLocalBackend(normalizeQueueDepth(cfg.Depth)), nil
	case "redis":
		return newRedisBackend(cfg)
	default:
		return nil, fmt.Errorf("unsupported jobs backend %q", cfg.Backend)
	}
}

func configuredBackendName(value string) string {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "", "memory", "local":
		return "memory"
	case "redis":
		return "redis"
	default:
		return strings.ToLower(strings.TrimSpace(value))
	}
}

func normalizeLogicalQueueName(value string) string {
	value = strings.TrimSpace(value)
	if value == "" {
		return defaultLogicalQueue
	}

	return value
}

func queueConnectionMatchesBackend(connection string, backend string) bool {
	connection = strings.ToLower(strings.TrimSpace(connection))
	if connection == "" || connection == "default" {
		return true
	}

	switch configuredBackendName(backend) {
	case "memory":
		return connection == "memory" || connection == "local"
	case "redis":
		return connection == "redis"
	default:
		return false
	}
}

func collectQueueNames(queueNames []string) []string {
	if len(queueNames) == 0 {
		return []string{defaultLogicalQueue}
	}

	seen := make(map[string]struct{}, len(queueNames))
	collected := make([]string, 0, len(queueNames))

	for _, queueName := range queueNames {
		normalized := normalizeLogicalQueueName(queueName)
		if _, ok := seen[normalized]; ok {
			continue
		}

		seen[normalized] = struct{}{}
		collected = append(collected, normalized)
	}

	if len(collected) == 0 {
		return []string{defaultLogicalQueue}
	}

	return collected
}
