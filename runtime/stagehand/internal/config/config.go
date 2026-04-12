package config

import "fmt"

type RuntimeConfig struct {
	Host                    string
	Port                    int
	StateDir                string
	LogFile                 string
	LogFormat               string
	RequestLogging          bool
	RequestBodyLimitBytes   int
	AllowedTargetHosts      string
	AllowPrivateTargets     bool
	ChromiumPath            string
	UserDataDir             string
	Headless                bool
	DisableSandbox          bool
	IgnoreHTTPSErrors       bool
	BrowserPoolSize         int
	BrowserPoolWarm         int
	BrowserQueueDepth       int
	AcquireTimeoutSec       int
	ReadyPollIntervalMs     int
	ReadySettleFrames       int
	JobBackend              string
	JobWorkerCount          int
	JobQueueDepth           int
	JobLeaseTimeoutSec      int
	JobHeartbeatSec         int
	JobTTLDays              int
	DeadLetterTTLDays       int
	ArtifactTTLDays         int
	AuthSharedSecret        string
	AuthAlgorithm           string
	AuthTimestampHeader     string
	AuthSignatureHeader     string
	AuthMaxSkewSec          int
	EventWebhookURL         string
	EventWebhookSecret      string
	EventWebhookMaxAttempts int
	EventWebhookBackoffMs   int
	RedisHost               string
	RedisPort               int
	RedisPassword           string
	RedisDB                 int
	RedisQueueKey           string
	RedisBlockTimeout       int
}

func Default() RuntimeConfig {
	return RuntimeConfig{
		Host:                    "127.0.0.1",
		Port:                    9514,
		LogFormat:               "json",
		RequestLogging:          true,
		RequestBodyLimitBytes:   5 * 1024 * 1024,
		AllowedTargetHosts:      "",
		AllowPrivateTargets:     false,
		Headless:                true,
		DisableSandbox:          false,
		IgnoreHTTPSErrors:       false,
		BrowserPoolSize:         2,
		BrowserPoolWarm:         1,
		BrowserQueueDepth:       16,
		AcquireTimeoutSec:       15,
		ReadyPollIntervalMs:     50,
		ReadySettleFrames:       2,
		JobBackend:              "memory",
		JobWorkerCount:          2,
		JobQueueDepth:           64,
		JobLeaseTimeoutSec:      45,
		JobHeartbeatSec:         10,
		JobTTLDays:              14,
		DeadLetterTTLDays:       30,
		ArtifactTTLDays:         14,
		AuthAlgorithm:           "canio-v1",
		AuthTimestampHeader:     "X-Canio-Timestamp",
		AuthSignatureHeader:     "X-Canio-Signature",
		AuthMaxSkewSec:          300,
		EventWebhookMaxAttempts: 3,
		EventWebhookBackoffMs:   500,
		RedisHost:               "127.0.0.1",
		RedisPort:               6379,
		RedisQueueKey:           "canio:jobs:queue",
		RedisBlockTimeout:       1,
	}
}

func (c RuntimeConfig) Address() string {
	return fmt.Sprintf("%s:%d", c.Host, c.Port)
}
