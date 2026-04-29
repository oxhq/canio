<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\File;

final class BrowserBundleResolver
{
    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>|null
     */
    public function installed(array $runtime): ?array
    {
        $manifestPath = $this->manifestPath($runtime);

        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) File::get($manifestPath), true);

        if (! is_array($manifest)) {
            return null;
        }

        $executablePath = trim((string) ($manifest['executablePath'] ?? ''));

        if ($executablePath === '' || ! is_file($executablePath)) {
            return null;
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function executablePath(array $runtime): ?string
    {
        $installed = $this->installed($runtime);

        if ($installed === null) {
            return null;
        }

        return trim((string) $installed['executablePath']);
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function manifestPath(array $runtime): string
    {
        return rtrim($this->installPath($runtime), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manifest.json';
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function installPath(array $runtime): string
    {
        $configured = trim((string) data_get($runtime, 'browser.install_path', ''));

        if ($configured === '') {
            $configured = storage_path('app/canio/browsers');
        }

        return $this->isAbsolutePath($configured)
            ? $configured
            : base_path($configured);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
