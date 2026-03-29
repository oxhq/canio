<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StagehandReleaseInstaller
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function install(
        array $config,
        ?string $version = null,
        ?string $path = null,
        ?string $os = null,
        ?string $arch = null,
        bool $force = false,
    ): StagehandInstallResult {
        $release = (array) ($config['release'] ?? []);
        $tag = $this->resolveTag($version, $release);
        $resolvedOs = $this->resolveOperatingSystem($os);
        $resolvedArch = $this->resolveArchitecture($arch);
        $repository = trim((string) ($release['repository'] ?? ''));
        $baseUrl = rtrim(trim((string) ($release['base_url'] ?? 'https://github.com')), '/');

        if ($repository === '') {
            throw new RuntimeException('Missing canio.runtime.release.repository / CANIO_RUNTIME_RELEASE_REPOSITORY.');
        }

        $asset = $this->assetName($tag, $resolvedOs, $resolvedArch);
        $checksumsUrl = sprintf('%s/%s/releases/download/%s/checksums.txt', $baseUrl, $repository, $tag);
        $assetUrl = sprintf('%s/%s/releases/download/%s/%s', $baseUrl, $repository, $tag, $asset);
        $installPath = $this->resolveInstallPath((string) ($path ?: ($config['install_path'] ?? 'bin/stagehand')), $resolvedOs);

        if (is_file($installPath) && ! $force) {
            throw new RuntimeException(sprintf(
                'A binary already exists at "%s". Re-run with force=true to replace it.',
                $installPath,
            ));
        }

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

        if ($resolvedOs !== 'windows') {
            @chmod($installPath, 0755);
        }

        return new StagehandInstallResult(
            tag: $tag,
            os: $resolvedOs,
            arch: $resolvedArch,
            path: $installPath,
        );
    }

    /**
     * @param  array<string, mixed>  $release
     */
    public function resolveTag(?string $value, array $release): string
    {
        $resolved = is_string($value) && trim($value) !== ''
            ? trim($value)
            : trim((string) ($release['version'] ?? ''));

        if ($resolved === '') {
            $resolved = PackageVersion::TAG;
        }

        return str_starts_with($resolved, 'v') ? $resolved : 'v'.$resolved;
    }

    public function resolveOperatingSystem(?string $value): string
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

    public function resolveArchitecture(?string $value): string
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

    public function resolveInstallPath(string $path, string $os): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            throw new RuntimeException('Install path is empty. Set CANIO_RUNTIME_INSTALL_PATH or pass a path.');
        }

        $resolved = str_starts_with($trimmed, DIRECTORY_SEPARATOR)
            ? $trimmed
            : base_path($trimmed);

        if ($os === 'windows' && ! str_ends_with(strtolower($resolved), '.exe')) {
            return $resolved.'.exe';
        }

        return $resolved;
    }

    public function assetName(string $tag, string $os, string $arch): string
    {
        return sprintf(
            'stagehand_%s_%s_%s%s',
            $tag,
            $os,
            $arch,
            $os === 'windows' ? '.exe' : '',
        );
    }

    public function resolveChecksum(string $body, string $asset): string
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
