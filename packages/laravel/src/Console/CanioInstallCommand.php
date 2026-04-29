<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;

final class CanioInstallCommand extends Command
{
    protected $signature = 'canio:install
        {version? : Release version or tag to install}
        {--force : Replace an existing Stagehand binary}
        {--without-runtime : Skip Stagehand download and only publish config}
        {--without-browser : Skip Chrome for Testing browser bundle download}';

    protected $description = 'Install Canio, publish config, and prepare the Stagehand runtime';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'canio-config',
            '--force' => true,
        ]);

        if (! $this->option('without-runtime')) {
            $this->call('canio:runtime:install', array_filter([
                'version' => $this->argument('version'),
                '--force' => (bool) $this->option('force'),
            ], static fn (mixed $value): bool => $value !== null && $value !== false));
        }

        $runtime = (array) config('canio.runtime', []);
        $rendererDriver = strtolower(trim((string) data_get($runtime, 'renderer.driver', 'rod-cdp')));
        $chromiumPath = trim((string) data_get($runtime, 'chromium.path', ''));

        if (! $this->option('without-browser') && $rendererDriver !== 'remote-cdp' && $chromiumPath === '') {
            $this->call('canio:browser:install', [
                '--force' => (bool) $this->option('force'),
            ]);
        }

        $this->call('canio:doctor');
        $this->info('Canio install completed.');

        return self::SUCCESS;
    }
}
