<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

final class BrowserBundleInstaller
{
    public function __construct(
        private readonly BrowserBundleResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function install(
        array $runtime,
        ?string $version = null,
        ?string $channel = null,
        ?string $platform = null,
        ?string $path = null,
        ?string $product = null,
        bool $force = false,
    ): BrowserBundleInstallResult {
        $resolvedProduct = $this->resolveProduct($runtime, $product);
        $resolvedChannel = $this->resolveChannel($runtime, $channel);
        $resolvedPlatform = $this->resolvePlatform($runtime, $platform);
        $installPath = $this->resolveInstallPath($runtime, $path);

        if (is_file($installPath.DIRECTORY_SEPARATOR.'manifest.json') && ! $force) {
            throw new RuntimeException(sprintf(
                'A browser bundle already exists at "%s". Re-run with --force or use canio:browser:repair.',
                $installPath,
            ));
        }

        [$resolvedVersion, $downloadUrl] = $this->resolveDownload($runtime, $version, $resolvedChannel, $resolvedPlatform, $resolvedProduct);

        if ($force && is_dir($installPath)) {
            File::deleteDirectory($installPath);
        }

        File::ensureDirectoryExists($installPath);

        $extractPath = $installPath.DIRECTORY_SEPARATOR.$resolvedProduct.'-'.$resolvedVersion.'-'.$resolvedPlatform;
        File::deleteDirectory($extractPath);
        File::ensureDirectoryExists($extractPath);

        $zipPath = $this->downloadZip($downloadUrl);

        try {
            $this->extractZip($zipPath, $extractPath);
        } finally {
            File::delete($zipPath);
        }

        $executablePath = $this->findExecutable($extractPath, $resolvedPlatform, $resolvedProduct);
        @chmod($executablePath, 0755);

        $manifest = [
            'product' => $resolvedProduct,
            'version' => $resolvedVersion,
            'channel' => $resolvedChannel,
            'platform' => $resolvedPlatform,
            'downloadUrl' => $downloadUrl,
            'installPath' => $installPath,
            'executablePath' => $executablePath,
            'installedAt' => now()->utc()->toIso8601String(),
        ];

        File::put(
            $installPath.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );

        return new BrowserBundleInstallResult(
            product: $resolvedProduct,
            version: $resolvedVersion,
            channel: $resolvedChannel,
            platform: $resolvedPlatform,
            installPath: $installPath,
            executablePath: $executablePath,
            downloadUrl: $downloadUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function resolveProduct(array $runtime, ?string $product = null): string
    {
        $resolved = strtolower(trim((string) ($product ?: data_get($runtime, 'browser.product', 'chrome'))));
        if ($resolved === '') {
            return 'chrome';
        }

        if (! in_array($resolved, ['chrome', 'chrome-headless-shell'], true)) {
            throw new RuntimeException(sprintf('Unsupported Chrome for Testing browser product "%s".', $resolved));
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function resolveChannel(array $runtime, ?string $channel = null): string
    {
        $resolved = trim((string) ($channel ?: data_get($runtime, 'browser.channel', 'Stable')));

        return $resolved !== '' ? $resolved : 'Stable';
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function resolvePlatform(array $runtime, ?string $platform = null): string
    {
        $configured = trim((string) ($platform ?: data_get($runtime, 'browser.platform', '')));
        if ($configured !== '') {
            return $configured;
        }

        return match (PHP_OS_FAMILY) {
            'Windows' => php_uname('m') === 'x86' ? 'win32' : 'win64',
            'Darwin' => in_array(strtolower((string) php_uname('m')), ['arm64', 'aarch64'], true) ? 'mac-arm64' : 'mac-x64',
            'Linux' => 'linux64',
            default => throw new RuntimeException(sprintf('Unsupported operating system family "%s".', PHP_OS_FAMILY)),
        };
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function resolveInstallPath(array $runtime, ?string $path = null): string
    {
        if (is_string($path) && trim($path) !== '') {
            return $this->normalizePath(trim($path));
        }

        return $this->resolver->installPath($runtime);
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array{0: string, 1: string}
     */
    private function resolveDownload(array $runtime, ?string $version, string $channel, string $platform, string $product): array
    {
        $version = trim((string) $version);
        $manifestUrl = $version !== ''
            ? str_replace('{version}', $version, trim((string) data_get($runtime, 'browser.version_manifest_url', 'https://googlechromelabs.github.io/chrome-for-testing/{version}.json')))
            : trim((string) data_get($runtime, 'browser.manifest_url', 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json'));

        $response = Http::acceptJson()->timeout(30)->get($manifestUrl);
        if ($response->failed()) {
            throw new RuntimeException(sprintf('Unable to resolve Chrome for Testing manifest from %s (status %d).', $manifestUrl, $response->status()));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Chrome for Testing manifest response was not valid JSON.');
        }

        $entry = $version !== ''
            ? $payload
            : data_get($payload, 'channels.'.$channel);

        if (! is_array($entry)) {
            throw new RuntimeException(sprintf('Chrome for Testing channel "%s" was not found in the manifest.', $channel));
        }

        $resolvedVersion = trim((string) ($entry['version'] ?? $version));
        if ($resolvedVersion === '') {
            throw new RuntimeException('Chrome for Testing manifest did not include a version.');
        }

        $downloads = data_get($entry, 'downloads.'.$product, []);
        if (! is_array($downloads)) {
            throw new RuntimeException(sprintf('Chrome for Testing manifest did not include %s downloads.', $product));
        }

        foreach ($downloads as $download) {
            if (! is_array($download)) {
                continue;
            }

            if (($download['platform'] ?? null) !== $platform) {
                continue;
            }

            $url = trim((string) ($download['url'] ?? ''));
            if ($url === '') {
                break;
            }

            return [$resolvedVersion, $url];
        }

        throw new RuntimeException(sprintf('Chrome for Testing does not list a %s download for platform "%s".', $product, $platform));
    }

    private function downloadZip(string $downloadUrl): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'canio-browser-');
        if ($zipPath === false) {
            throw new RuntimeException('Unable to create a temporary file for the browser bundle.');
        }

        $response = Http::timeout(300)->sink($zipPath)->get($downloadUrl);
        if ($response->failed()) {
            File::delete($zipPath);

            throw new RuntimeException(sprintf(
                'Unable to download Chrome for Testing from %s (status %d).',
                $downloadUrl,
                $response->status(),
            ));
        }

        return $zipPath;
    }

    private function extractZip(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Downloaded Chrome for Testing bundle was not a valid zip archive.');
        }

        $zip->extractTo($destination);
        $zip->close();
    }

    private function findExecutable(string $extractPath, string $platform, string $product): string
    {
        if ($product === 'chrome-headless-shell') {
            $candidates = match ($platform) {
                'win32', 'win64' => ['chrome-headless-shell.exe'],
                default => ['chrome-headless-shell'],
            };
        } else {
            $candidates = match ($platform) {
                'win32', 'win64' => ['chrome.exe'],
                'mac-arm64', 'mac-x64' => ['Google Chrome for Testing'],
                default => ['chrome'],
            };
        }

        $files = File::allFiles($extractPath);

        foreach ($files as $file) {
            if (in_array($file->getFilename(), $candidates, true)) {
                return $file->getPathname();
            }
        }

        throw new RuntimeException(sprintf('Chrome for Testing %s executable was not found after extracting %s.', $product, $platform));
    }

    private function normalizePath(string $path): string
    {
        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
