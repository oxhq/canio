<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;

final class CanioInstallCommand extends Command
{
    protected $signature = 'canio:install
        {version? : Release version or tag to install}
        {--force : Replace an existing Stagehand binary}
        {--without-runtime : Skip Stagehand download and only publish config}';

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

        $this->call('canio:doctor');
        $this->info('Canio install completed.');

        return self::SUCCESS;
    }
}
