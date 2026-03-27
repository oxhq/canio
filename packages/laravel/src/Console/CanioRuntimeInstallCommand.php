<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Support\PackageVersion;
use RuntimeException;

final class CanioRuntimeInstallCommand extends Command
{
    protected $signature = 'canio:runtime:install
        {version? : Release version or tag to install}
        {--path= : Install destination relative to the app base path}
        {--os= : Override operating system (linux, darwin, windows)}
        {--arch= : Override CPU architecture (amd64, arm64)}
        {--force : Replace an existing binary at the destination}';

    protected $description = 'Download and install the matching Stagehand binary for this machine';

    public function handle(): int
    {
        try {
            $config = (array) config('canio.runtime', []);
            $release = (array) ($config['release'] ?? []);
            $tag = $this->resolveTag($this->argument('version'), $release);
            $os = $this->resolveOperatingSystem($this->option('os'));
            $arch = $this->resolveArchitecture($this->option('arch'));
            $repository = trim((string) ($release['repository'] ?? ''));
            $baseUrl = rtrim(trim((string) ($release['base_url'] ?? 'https://github.com')), '/');

            if ($repository === '') {
                throw new RuntimeException('Missing canio.runtime.release.repository / CANIO_RUNTIME_RELEASE_REPOSITORY.');
            }

            $asset = $this->assetName($tag, $os, $arch);
            $checksumsUrl = sprintf('%s/%s/releases/download/%s/checksums.txt', $baseUrl, $repository, $tag);
            $assetUrl = sprintf('%s/%s/releases/download/%s/%s', $baseUrl, $repository, $tag, $asset);
            $installPath = $this->resolveInstallPath((string) ($this->option('path') ?: ($config['install_path'] ?? 'bin/stagehand')), $os);

            if (is_file($installPath) && ! $this->option('force')) {
                throw new RuntimeException(sprintf(
                    'A binary already exists at "%s". Re-run with --force to replace it.',
                    $installPath,
                ));
            }

            $this->line(sprintf('Downloading %s for %s/%s…', $tag, $os, $arch));

            $checksumsResponse = Http::accept('text/plain')
                ->timeout(30)
                ->get($checksumsUrl);
            if ($checksumsResponse->failed()) {
                throw new RuntimeException(sprintf(
                    'Unable to download release checksums from %s (status %d).',
                    $checksumsUrl,
                    $checksumsResponse->status(),
                ));
            }

            $expectedChecksum = $this->resolveChecksum($checksumsResponse->body(), $asset);
            $binaryResponse = Http::timeout(120)->get($assetUrl);
            if ($binaryResponse->failed()) {
                throw new RuntimeException(sprintf(
                    'Unable to download %s from %s (status %d).',
                    $asset,
                    $assetUrl,
                    $binaryResponse->status(),
                ));
            }

            $binaryContents = $binaryResponse->body();
            $actualChecksum = hash('sha256', $binaryContents);

            if (! hash_equals($expectedChecksum, $actualChecksum)) {
                throw new RuntimeException(sprintf(
                    'Checksum verification failed for %s. Expected %s, received %s.',
                    $asset,
                    $expectedChecksum,
                    $actualChecksum,
                ));
            }

            File::ensureDirectoryExists(dirname($installPath));
            File::put($installPath, $binaryContents);

            if ($os !== 'windows') {
                @chmod($installPath, 0755);
            }

            $this->info(sprintf('Installed Stagehand %s to %s', $tag, $installPath));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function resolveTag(mixed $value, array $release): string
    {
        $resolved = is_string($value) && trim($value) !== ''
            ? trim($value)
            : trim((string) ($release['version'] ?? ''));

        if ($resolved === '') {
            $resolved = PackageVersion::TAG;
        }

        return str_starts_with($resolved, 'v') ? $resolved : 'v'.$resolved;
    }

    private function resolveOperatingSystem(mixed $value): string
    {
        $resolved = strtolower(trim((string) $value));

        if ($resolved !== '') {
            return match ($resolved) {
                'mac', 'macos', 'darwin' => 'darwin',
                'win', 'windows' => 'windows',
                'linux' => 'linux',
                default => throw new RuntimeException(sprintf('Unsupported operating system "%s".', $resolved)),
            };
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            'Linux' => 'linux',
            default => throw new RuntimeException(sprintf('Unsupported operating system family "%s".', PHP_OS_FAMILY)),
        };
    }

    private function resolveArchitecture(mixed $value): string
    {
        $resolved = strtolower(trim((string) $value));

        if ($resolved === '') {
            $resolved = strtolower((string) php_uname('m'));
        }

        return match ($resolved) {
            'x86_64', 'amd64' => 'amd64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new RuntimeException(sprintf('Unsupported architecture "%s".', $resolved)),
        };
    }

    private function resolveInstallPath(string $path, string $os): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new RuntimeException('Install path is empty. Set CANIO_RUNTIME_INSTALL_PATH or pass --path.');
        }

        $resolved = str_starts_with($trimmed, DIRECTORY_SEPARATOR)
            ? $trimmed
            : base_path($trimmed);

        if ($os === 'windows' && ! str_ends_with(strtolower($resolved), '.exe')) {
            return $resolved.'.exe';
        }

        return $resolved;
    }

    private function assetName(string $tag, string $os, string $arch): string
    {
        return sprintf(
            'stagehand_%s_%s_%s%s',
            $tag,
            $os,
            $arch,
            $os === 'windows' ? '.exe' : '',
        );
    }

    private function resolveChecksum(string $body, string $asset): string
    {
        foreach (preg_split("/(\r?\n)/", trim($body)) ?: [] as $line) {
            if (! preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $matches)) {
                continue;
            }

            if (trim($matches[2]) === $asset) {
                return strtolower($matches[1]);
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to find checksum entry for %s in checksums.txt.',
            $asset,
        ));
    }
}
