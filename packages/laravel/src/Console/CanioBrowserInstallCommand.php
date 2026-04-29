<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\Support\BrowserBundleInstaller;

final class CanioBrowserInstallCommand extends Command
{
    protected $signature = 'canio:browser:install
        {version? : Chrome for Testing version to install. Defaults to the configured channel}
        {--product= : Browser product to install (chrome or chrome-headless-shell)}
        {--channel= : Chrome for Testing channel when version is omitted}
        {--platform= : Override platform (linux64, mac-arm64, mac-x64, win32, win64)}
        {--path= : Install destination relative to the app base path}
        {--force : Replace an existing browser bundle}';

    protected $description = 'Download and install the Chrome for Testing browser bundle used by Stagehand';

    public function handle(BrowserBundleInstaller $installer): int
    {
        try {
            $runtime = (array) config('canio.runtime', []);
            $version = $this->argument('version');
            $product = $installer->resolveProduct($runtime, is_string($this->option('product')) ? $this->option('product') : null);
            $channel = $installer->resolveChannel($runtime, $this->option('channel'));
            $platform = $installer->resolvePlatform($runtime, $this->option('platform'));

            $this->line(sprintf(
                'Resolving Chrome for Testing %s %s for %s...',
                $product,
                trim((string) $version) !== '' ? $version : $channel,
                $platform,
            ));

            $result = $installer->install(
                runtime: $runtime,
                version: is_string($version) ? $version : null,
                channel: is_string($this->option('channel')) ? $this->option('channel') : null,
                platform: is_string($this->option('platform')) ? $this->option('platform') : null,
                path: is_string($this->option('path')) ? $this->option('path') : null,
                product: is_string($this->option('product')) ? $this->option('product') : null,
                force: (bool) $this->option('force'),
            );

            $this->line(sprintf('Installing Chrome for Testing %s %s...', $result->product, $result->version));
            $this->info(sprintf('Installed Chrome for Testing %s %s to %s', $result->product, $result->version, $result->installPath));
            $this->line(sprintf('Browser executable: %s', $result->executablePath));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
