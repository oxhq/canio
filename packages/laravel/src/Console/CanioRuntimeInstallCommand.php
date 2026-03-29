<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\Support\StagehandReleaseInstaller;

final class CanioRuntimeInstallCommand extends Command
{
    protected $signature = 'canio:runtime:install
        {version? : Release version or tag to install}
        {--path= : Install destination relative to the app base path}
        {--os= : Override operating system (linux, darwin, windows)}
        {--arch= : Override CPU architecture (amd64, arm64)}
        {--force : Replace an existing binary at the destination}';

    protected $description = 'Download and install the matching Stagehand binary for this machine';

    public function handle(StagehandReleaseInstaller $installer): int
    {
        try {
            $config = (array) config('canio.runtime', []);
            $release = (array) ($config['release'] ?? []);
            $tag = $installer->resolveTag($this->argument('version'), $release);
            $os = $installer->resolveOperatingSystem($this->option('os'));
            $arch = $installer->resolveArchitecture($this->option('arch'));

            $this->line(sprintf('Downloading %s for %s/%s…', $tag, $os, $arch));

            $result = $installer->install(
                config: $config,
                version: $this->argument('version'),
                path: $this->option('path'),
                os: $this->option('os'),
                arch: $this->option('arch'),
                force: (bool) $this->option('force'),
            );

            $this->info(sprintf('Installed Stagehand %s to %s', $result->tag, $result->path));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
