<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\Support\BrowserBundleInstaller;

final class CanioBrowserRepairCommand extends Command
{
    protected $signature = 'canio:browser:repair
        {version? : Chrome for Testing version to install. Defaults to the configured channel}
        {--product= : Browser product to install (chrome or chrome-headless-shell)}
        {--channel= : Chrome for Testing channel when version is omitted}
        {--platform= : Override platform (linux64, mac-arm64, mac-x64, win32, win64)}
        {--path= : Install destination relative to the app base path}';

    protected $description = 'Repair the local Chrome for Testing browser bundle used by Stagehand';

    public function handle(BrowserBundleInstaller $installer): int
    {
        try {
            $runtime = (array) config('canio.runtime', []);

            $this->line('Repairing Chrome for Testing browser bundle...');

            $result = $installer->install(
                runtime: $runtime,
                version: is_string($this->argument('version')) ? $this->argument('version') : null,
                channel: is_string($this->option('channel')) ? $this->option('channel') : null,
                platform: is_string($this->option('platform')) ? $this->option('platform') : null,
                path: is_string($this->option('path')) ? $this->option('path') : null,
                product: is_string($this->option('product')) ? $this->option('product') : null,
                force: true,
            );

            $this->info(sprintf('Installed Chrome for Testing %s %s to %s', $result->product, $result->version, $result->installPath));
            $this->line(sprintf('Browser executable: %s', $result->executablePath));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
