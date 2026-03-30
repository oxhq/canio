<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class StagehandRuntimeUrl
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function baseUrl(array $config): string
    {
        $configured = trim((string) ($config['base_url'] ?? ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = trim((string) ($config['host'] ?? '127.0.0.1'));
        $host = $host !== '' ? $host : '127.0.0.1';
        $port = (int) ($config['port'] ?? 9514);
        $port = $port > 0 ? $port : 9514;

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        return sprintf('http://%s:%d', $host, $port);
    }
}
