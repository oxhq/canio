<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Support\BrowserBundleResolver;
use Oxhq\Canio\Support\StagehandBinaryResolver;
use RuntimeException;

final class CanioDoctorCommand extends Command
{
    protected $signature = 'canio:doctor';

    protected $description = 'Run basic runtime, binary, and configuration health checks for Canio';

    public function handle(StagehandBinaryResolver $resolver, BrowserBundleResolver $browserResolver, CanioManager $canio): int
    {
        $runtime = (array) config('canio.runtime', []);
        $workingDirectory = (string) ($runtime['working_directory'] ?? base_path());
        $binaryOkay = false;
        $runtimeOkay = true;
        $mode = strtolower(trim((string) ($runtime['mode'] ?? 'embedded')));
        $rendererDriver = strtolower(trim((string) data_get($runtime, 'renderer.driver', 'rod-cdp')));

        $this->info(sprintf('Runtime mode: %s', $mode));

        try {
            $binary = $resolver->resolve($runtime, $workingDirectory);
            $binaryOkay = true;
            $this->info(sprintf('Stagehand binary: %s', $binary));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        $this->info(sprintf('Renderer driver: %s', $rendererDriver !== '' ? $rendererDriver : 'rod-cdp'));

        $chromiumPath = trim((string) data_get($runtime, 'chromium.path', ''));
        if ($rendererDriver === 'remote-cdp') {
            $endpoint = trim((string) data_get($runtime, 'renderer.remote_cdp.endpoint', ''));
            if ($endpoint === '') {
                $this->warn('Remote CDP endpoint is not configured. Set CANIO_REMOTE_CDP_ENDPOINT.');
            } else {
                $this->info('Remote CDP endpoint: configured');
            }
        } elseif ($chromiumPath !== '') {
            $this->info(sprintf('Chromium path: %s', $chromiumPath));
        } elseif ($installedBrowser = $browserResolver->installed($runtime)) {
            $this->info(sprintf(
                'Browser bundle: Chrome for Testing %s %s (%s)',
                (string) ($installedBrowser['product'] ?? 'chrome'),
                (string) ($installedBrowser['version'] ?? 'unknown'),
                (string) ($installedBrowser['platform'] ?? 'unknown'),
            ));
            $this->info(sprintf('Browser executable: %s', (string) $installedBrowser['executablePath']));
        } else {
            $this->warn('Browser bundle is not installed. Run php artisan canio:browser:install or set CANIO_CHROMIUM_PATH.');
        }

        try {
            $status = $canio->runtimeStatus();
            $this->info(sprintf(
                'Stagehand status: %s (%s)',
                (string) data_get($status, 'runtime.state', 'unknown'),
                (string) data_get($status, 'version', 'unknown'),
            ));
        } catch (RuntimeException $exception) {
            if ($mode === 'embedded' && (bool) ($runtime['auto_start'] ?? true)) {
                $runtimeOkay = false;
                $this->error('Embedded Stagehand could not be reached after the auto-start readiness check.');
                $this->warn($exception->getMessage());

                if ($this->usesRedisJobBackend($runtime)) {
                    $this->warn('Runtime job backend is redis. Verify Redis is reachable or set CANIO_RUNTIME_JOB_BACKEND=memory for non-production local checks.');
                }
            } else {
                $this->warn($exception->getMessage());
            }
        }

        return $binaryOkay && $runtimeOkay ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function usesRedisJobBackend(array $runtime): bool
    {
        return strtolower(trim((string) data_get($runtime, 'jobs.backend', ''))) === 'redis';
    }
}
