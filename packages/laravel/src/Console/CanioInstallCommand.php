<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\Support\BrowserBundleResolver;
use Oxhq\Canio\Support\StagehandBinaryResolver;
use RuntimeException;

final class CanioInstallCommand extends Command
{
    protected $signature = 'canio:install
        {version? : Release version or tag to install}
        {--force : Replace an existing Stagehand binary}
        {--without-runtime : Skip Stagehand download and only publish config}
        {--without-browser : Skip Chrome for Testing browser bundle download}';

    protected $description = 'Install Canio, publish config, and prepare the Stagehand runtime';

    public function handle(StagehandBinaryResolver $binaryResolver, BrowserBundleResolver $browserResolver): int
    {
        $exitCode = $this->call('vendor:publish', [
            '--tag' => 'canio-config',
            '--force' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $runtime = (array) config('canio.runtime', []);

        if (! $this->option('without-runtime')) {
            if ($this->option('force') || ! $this->runtimeBinaryIsInstalled($binaryResolver, $runtime)) {
                $exitCode = $this->call('canio:runtime:install', array_filter([
                    'version' => $this->argument('version'),
                    '--force' => (bool) $this->option('force'),
                ], static fn (mixed $value): bool => $value !== null && $value !== false));

                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }
            }
        }

        $rendererDriver = strtolower(trim((string) data_get($runtime, 'renderer.driver', 'rod-cdp')));
        $chromiumPath = trim((string) data_get($runtime, 'chromium.path', ''));

        if (! $this->option('without-browser') && $rendererDriver !== 'remote-cdp' && $chromiumPath === '') {
            if ($this->option('force') || ! $this->browserBundleIsInstalled($browserResolver, $runtime)) {
                $exitCode = $this->call('canio:browser:install', [
                    '--force' => (bool) $this->option('force'),
                ]);

                if ($exitCode !== self::SUCCESS) {
                    return $exitCode;
                }
            }
        }

        $exitCode = $this->call('canio:doctor');

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('Canio install completed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function runtimeBinaryIsInstalled(StagehandBinaryResolver $resolver, array $runtime): bool
    {
        $workingDirectory = (string) data_get($runtime, 'working_directory', base_path());
        $configuredBinary = trim((string) data_get($runtime, 'binary', 'stagehand'));
        $installPath = trim((string) data_get($runtime, 'install_path', ''));

        if (! $this->looksLikePath($configuredBinary) && ! $this->isRunnableInstallPath($installPath, $workingDirectory)) {
            return false;
        }

        try {
            $binary = $resolver->resolve($runtime, $workingDirectory);
        } catch (RuntimeException) {
            return false;
        }

        $this->info(sprintf('Stagehand binary already installed: %s', $binary));

        return true;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function browserBundleIsInstalled(BrowserBundleResolver $resolver, array $runtime): bool
    {
        $manifest = $resolver->installed($runtime);

        if ($manifest === null) {
            return false;
        }

        $this->info(sprintf('Browser bundle already installed: %s', (string) $manifest['executablePath']));

        return true;
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, DIRECTORY_SEPARATOR)
            || str_contains($binary, '/')
            || str_contains($binary, '\\')
            || str_starts_with($binary, '.')
            || str_starts_with($binary, '~');
    }

    private function isRunnableInstallPath(string $installPath, string $workingDirectory): bool
    {
        if ($installPath === '') {
            return false;
        }

        $path = $this->isAbsolutePath($installPath)
            ? $installPath
            : rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($installPath, DIRECTORY_SEPARATOR);

        if (! is_file($path)) {
            return false;
        }

        return is_executable($path)
            || (PHP_OS_FAMILY === 'Windows' && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['bat', 'cmd', 'exe'], true));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
