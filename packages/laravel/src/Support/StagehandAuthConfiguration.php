<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class StagehandAuthConfiguration
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function resolve(array $config): array
    {
        $rootAuth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $legacyAuth = data_get($config, 'jobs.auth', []);
        $legacyAuth = is_array($legacyAuth) ? $legacyAuth : [];

        $resolved = array_replace($legacyAuth, array_filter(
            $rootAuth,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        $sharedSecret = trim((string) ($resolved['shared_secret'] ?? ''));
        if ($sharedSecret === '') {
            $appKey = trim((string) config('app.key', ''));

            if ($appKey !== '') {
                $resolved['shared_secret'] = hash('sha256', $appKey.':canio-runtime');
            }
        }

        return $resolved;
    }
}
