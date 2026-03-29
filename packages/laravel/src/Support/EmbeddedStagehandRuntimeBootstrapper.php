<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;
use RuntimeException;

final class EmbeddedStagehandRuntimeBootstrapper implements StagehandRuntimeBootstrapper
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly StagehandBinaryResolver $resolver,
        private readonly StagehandReleaseInstaller $installer,
        private readonly StagehandServeCommandBuilder $commandBuilder,
        private readonly StagehandProcessLauncher $launcher,
        private readonly StagehandHealthProbe $healthProbe,
    ) {}

    public function ensureAvailable(): void
    {
        if (! $this->shouldBootstrap()) {
            return;
        }

        $baseUrl = $this->baseUrl();
        if ($this->healthProbe->isReady($this->config)) {
            return;
        }

        $handle = $this->acquireLock();

        try {
            if ($this->healthProbe->isReady($this->config)) {
                return;
            }

            $this->ensureBinaryAvailable();
            $this->cleanupStaleChromiumLocks();

            $runtime = $this->config;
            $command = $this->commandBuilder->build($runtime);
            $workingDirectory = (string) ($runtime['working_directory'] ?? base_path());

            $this->launcher->start($command, $workingDirectory);

            if (! $this->healthProbe->waitUntilReady($this->config, $this->startupTimeout())) {
                throw new RuntimeException(sprintf(
                    'Canio started Stagehand in embedded mode, but it did not become healthy at %s within %d seconds. Check %s.',
                    $baseUrl,
                    $this->startupTimeout(),
                    (string) ($runtime['log_path'] ?? storage_path('logs/canio-runtime.log')),
                ));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function shouldBootstrap(): bool
    {
        return $this->runtimeMode() === 'embedded'
            && (bool) ($this->config['auto_start'] ?? true);
    }

    private function runtimeMode(): string
    {
        return strtolower(trim((string) ($this->config['mode'] ?? 'embedded')));
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'http://127.0.0.1:9514'), '/');
    }

    private function startupTimeout(): int
    {
        return max(3, (int) ($this->config['startup_timeout'] ?? 15));
    }

    /**
     * @return resource
     */
    private function acquireLock()
    {
        $statePath = (string) ($this->config['state_path'] ?? storage_path('app/canio/runtime'));
        File::ensureDirectoryExists($statePath);
        $lockPath = rtrim($statePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'embedded-runtime.lock';
        $handle = fopen($lockPath, 'c+');

        if (! is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to create the Canio embedded runtime lock at %s.', $lockPath));
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException(sprintf('Unable to acquire the Canio embedded runtime lock at %s.', $lockPath));
        }

        return $handle;
    }

    private function ensureBinaryAvailable(): void
    {
        $workingDirectory = (string) ($this->config['working_directory'] ?? base_path());

        try {
            $this->resolver->resolve($this->config, $workingDirectory);
        } catch (RuntimeException $exception) {
            if (! (bool) ($this->config['auto_install'] ?? true)) {
                throw new RuntimeException(
                    'Canio is running in embedded mode, but the Stagehand binary is missing. Enable canio.runtime.auto_install or run php artisan canio:runtime:install.',
                    previous: $exception,
                );
            }

            $this->installer->install($this->config);
        }
    }

    private function cleanupStaleChromiumLocks(): void
    {
        $statePath = (string) ($this->config['state_path'] ?? storage_path('app/canio/runtime'));
        $userDataDir = trim((string) data_get($this->config, 'chromium.user_data_dir', ''));

        if ($userDataDir === '') {
            $userDataDir = rtrim($statePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'chromium-profile';
        }

        if (! is_dir($userDataDir)) {
            return;
        }

        foreach (glob($userDataDir.DIRECTORY_SEPARATOR.'browser-*'.DIRECTORY_SEPARATOR.'Singleton*') ?: [] as $lockFile) {
            if (is_file($lockFile) || is_link($lockFile)) {
                @unlink($lockFile);
            }
        }
    }
}
