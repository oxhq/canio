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
                $this->warn('Embedded Stagehand is not running yet. It will be started automatically on first use.');
            } else {
                $this->warn($exception->getMessage());
            }
        }

        return $binaryOkay ? self::SUCCESS : self::FAILURE;
    }
}
