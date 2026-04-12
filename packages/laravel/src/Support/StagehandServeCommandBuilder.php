<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\File;

final class StagehandServeCommandBuilder
{
    public function __construct(
        private readonly StagehandBinaryResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<string>
     */
    public function build(array $runtime, ?string $host = null, ?int $port = null): array
    {
        $workingDirectory = (string) ($runtime['working_directory'] ?? base_path());
        $host = (string) ($host ?: ($runtime['host'] ?? '127.0.0.1'));
        $port = (int) ($port ?: ($runtime['port'] ?? 9514));
        $statePath = (string) ($runtime['state_path'] ?? storage_path('app/canio/runtime'));
        $logPath = (string) ($runtime['log_path'] ?? storage_path('logs/canio-runtime.log'));
        $chromiumPath = trim((string) data_get($runtime, 'chromium.path', ''));
        $userDataDir = trim((string) data_get($runtime, 'chromium.user_data_dir', ''));
        $headless = (bool) data_get($runtime, 'chromium.headless', true);
        $noSandbox = (bool) data_get($runtime, 'chromium.no_sandbox', false);
        $ignoreHttpsErrors = (bool) data_get($runtime, 'chromium.ignore_https_errors', false);
        $allowedTargetHosts = trim((string) data_get($runtime, 'navigation.allowed_hosts', ''));
        $allowPrivateTargets = (bool) data_get($runtime, 'navigation.allow_private_targets', $this->defaultAllowPrivateTargets());
        $browserPoolSize = (int) data_get($runtime, 'pool.size', 2);
        $browserPoolWarm = (int) data_get($runtime, 'pool.warm', 1);
        $browserQueueDepth = (int) data_get($runtime, 'pool.queue_depth', 16);
        $browserAcquireTimeout = (int) data_get($runtime, 'pool.acquire_timeout', 15);
        $readyPollIntervalMs = (int) data_get($runtime, 'wait.poll_interval_ms', 50);
        $readySettleFrames = (int) data_get($runtime, 'wait.settle_frames', 2);
        $jobBackend = (string) data_get($runtime, 'jobs.backend', $this->defaultJobBackend());
        $jobWorkers = (int) data_get($runtime, 'jobs.workers', 2);
        $jobQueueDepth = (int) data_get($runtime, 'jobs.queue_depth', 64);
        $jobLeaseTimeout = (int) data_get($runtime, 'jobs.lease_timeout', 45);
        $jobHeartbeatInterval = (int) data_get($runtime, 'jobs.heartbeat_interval', 10);
        $jobTtlDays = (int) data_get($runtime, 'jobs.ttl_days', 14);
        $deadLetterTtlDays = (int) data_get($runtime, 'jobs.dead_letter_ttl_days', 30);
        $artifactTtlDays = (int) data_get($runtime, 'artifacts.ttl_days', 14);
        $logFormat = (string) data_get($runtime, 'observability.log_format', 'json');
        $requestLogging = (bool) data_get($runtime, 'observability.request_logging', true);
        $authSharedSecret = $this->resolveAuthSharedSecret($runtime);
        $authAlgorithm = (string) data_get($runtime, 'auth.algorithm', data_get($runtime, 'jobs.auth.algorithm', 'canio-v1'));
        $authTimestampHeader = (string) data_get($runtime, 'auth.timestamp_header', data_get($runtime, 'jobs.auth.timestamp_header', 'X-Canio-Timestamp'));
        $authSignatureHeader = (string) data_get($runtime, 'auth.signature_header', data_get($runtime, 'jobs.auth.signature_header', 'X-Canio-Signature'));
        $authMaxSkew = (int) data_get($runtime, 'auth.max_skew_seconds', data_get($runtime, 'jobs.auth.max_skew_seconds', 300));
        $pushWebhookUrl = $this->resolvePushWebhookUrl($runtime);
        $pushWebhookSecret = trim((string) data_get($runtime, 'push.webhook.secret', ''));
        $redisHost = (string) data_get($runtime, 'jobs.redis.host', '127.0.0.1');
        $redisPort = (int) data_get($runtime, 'jobs.redis.port', 6379);
        $redisPassword = (string) data_get($runtime, 'jobs.redis.password', '');
        $redisDb = (int) data_get($runtime, 'jobs.redis.db', 0);
        $redisQueueKey = (string) data_get($runtime, 'jobs.redis.queue_key', 'canio:jobs:queue');
        $redisBlockTimeout = (int) data_get($runtime, 'jobs.redis.block_timeout', 1);
        $binary = $this->resolver->resolve($runtime, $workingDirectory);

        File::ensureDirectoryExists(dirname($logPath));
        File::ensureDirectoryExists($statePath);

        if ($userDataDir === '') {
            $userDataDir = $statePath.DIRECTORY_SEPARATOR.'chromium-profile';
        }

        File::ensureDirectoryExists($userDataDir);

        $command = [
            $binary,
            'serve',
            '--host', $host,
            '--port', (string) $port,
            '--state-dir', $statePath,
            '--log-file', $logPath,
            '--log-format', $logFormat,
            '--request-logging='.($requestLogging ? 'true' : 'false'),
            '--user-data-dir', $userDataDir,
            '--headless='.($headless ? 'true' : 'false'),
            '--no-sandbox='.($noSandbox ? 'true' : 'false'),
            '--ignore-https-errors='.($ignoreHttpsErrors ? 'true' : 'false'),
            '--allow-private-targets='.($allowPrivateTargets ? 'true' : 'false'),
            '--browser-pool-size', (string) $browserPoolSize,
            '--browser-pool-warm', (string) $browserPoolWarm,
            '--browser-queue-depth', (string) $browserQueueDepth,
            '--browser-acquire-timeout', (string) $browserAcquireTimeout,
            '--ready-poll-interval-ms', (string) $readyPollIntervalMs,
            '--ready-settle-frames', (string) $readySettleFrames,
            '--job-backend', $jobBackend,
            '--job-workers', (string) $jobWorkers,
            '--job-queue-depth', (string) $jobQueueDepth,
            '--job-lease-timeout', (string) $jobLeaseTimeout,
            '--job-heartbeat-interval', (string) $jobHeartbeatInterval,
            '--job-ttl-days', (string) $jobTtlDays,
            '--job-dead-letter-ttl-days', (string) $deadLetterTtlDays,
            '--artifact-ttl-days', (string) $artifactTtlDays,
            '--job-redis-host', $redisHost,
            '--job-redis-port', (string) $redisPort,
            '--job-redis-password', $redisPassword,
            '--job-redis-db', (string) $redisDb,
            '--job-redis-queue-key', $redisQueueKey,
            '--job-redis-block-timeout', (string) $redisBlockTimeout,
        ];

        if ($allowedTargetHosts !== '') {
            $command[] = '--allowed-target-hosts';
            $command[] = $allowedTargetHosts;
        }

        if ($authSharedSecret !== '') {
            $command[] = '--auth-shared-secret';
            $command[] = $authSharedSecret;
            $command[] = '--auth-algorithm';
            $command[] = $authAlgorithm;
            $command[] = '--auth-timestamp-header';
            $command[] = $authTimestampHeader;
            $command[] = '--auth-signature-header';
            $command[] = $authSignatureHeader;
            $command[] = '--auth-max-skew';
            $command[] = (string) $authMaxSkew;
        }

        if ($pushWebhookUrl !== '') {
            $command[] = '--event-webhook-url';
            $command[] = $pushWebhookUrl;
        }

        if ($pushWebhookSecret !== '') {
            $command[] = '--event-webhook-secret';
            $command[] = $pushWebhookSecret;
        }

        if ($chromiumPath !== '') {
            $command[] = '--chromium-path';
            $command[] = $chromiumPath;
        }

        return $command;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function resolveAuthSharedSecret(array $runtime): string
    {
        $configured = trim((string) data_get($runtime, 'auth.shared_secret', data_get($runtime, 'jobs.auth.shared_secret', '')));
        if ($configured !== '') {
            return $configured;
        }

        $appKey = trim((string) config('app.key', ''));

        return $appKey !== ''
            ? hash('sha256', $appKey.':canio-runtime')
            : '';
    }

    private function defaultJobBackend(): string
    {
        return in_array((string) config('app.env', 'production'), ['production', 'staging'], true)
            ? 'redis'
            : 'memory';
    }

    private function defaultAllowPrivateTargets(): bool
    {
        return in_array((string) config('app.env', 'production'), ['local', 'testing'], true);
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function resolvePushWebhookUrl(array $runtime): string
    {
        if (! (bool) data_get($runtime, 'push.webhook.enabled', false)) {
            return '';
        }

        $configured = trim((string) data_get($runtime, 'push.webhook.url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        $path = '/'.ltrim((string) data_get($runtime, 'push.webhook.path', '/canio/webhooks/stagehand/jobs'), '/');

        if ($appUrl === '') {
            return '';
        }

        return $appUrl.$path;
    }
}
