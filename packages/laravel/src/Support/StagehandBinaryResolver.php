<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class StagehandBinaryResolver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function resolve(array $config, string $workingDirectory): string
    {
        $configured = trim((string) ($config['binary'] ?? 'stagehand'));
        $installPath = trim((string) ($config['install_path'] ?? ''));

        if ($configured === '') {
            throw new RuntimeException(
                'Unable to find the Stagehand binary: canio.runtime.binary is empty. '.
                'Set CANIO_RUNTIME_BINARY or configure canio.runtime.binary.',
            );
        }

        if ($this->looksLikePath($configured)) {
            $candidate = $this->normalizePath($configured, $workingDirectory);

            if ($this->isRunnableFile($candidate)) {
                return $candidate;
            }

            throw new RuntimeException(sprintf(
                'Unable to find the Stagehand binary at "%s". Set CANIO_RUNTIME_BINARY or canio.runtime.binary to an executable path.',
                $candidate,
            ));
        }

        $searchPaths = [
            $workingDirectory,
            $workingDirectory.DIRECTORY_SEPARATOR.'bin',
            $workingDirectory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin',
        ];

        if ($installPath !== '') {
            $installedBinary = $this->normalizePath($installPath, $workingDirectory);

            if ($this->isRunnableFile($installedBinary)) {
                return $installedBinary;
            }

            $searchPaths[] = dirname($installedBinary);
        }

        $finder = new ExecutableFinder;
        $found = $finder->find($configured, null, array_values(array_unique($searchPaths)));

        if ($found !== null) {
            return $found;
        }

        throw new RuntimeException(sprintf(
            'Unable to find the Stagehand binary "%s". Add it to PATH, or set CANIO_RUNTIME_BINARY / canio.runtime.binary to an executable path.',
            $configured,
        ));
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, DIRECTORY_SEPARATOR)
            || str_contains($binary, '/')
            || str_contains($binary, '\\')
            || str_starts_with($binary, '.')
            || str_starts_with($binary, '~');
    }

    private function normalizePath(string $binary, string $workingDirectory): string
    {
        if (str_starts_with($binary, '~/')) {
            $home = getenv('HOME') ?: '';

            return ($home !== '' ? rtrim($home, DIRECTORY_SEPARATOR) : '').DIRECTORY_SEPARATOR.ltrim(substr($binary, 2), DIRECTORY_SEPARATOR);
        }

        if ($this->isAbsolutePath($binary)) {
            return $binary;
        }

        return rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($binary, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function isRunnableFile(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        if (is_executable($path)) {
            return true;
        }

        return PHP_OS_FAMILY === 'Windows'
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['bat', 'cmd', 'exe'], true);
    }
}
