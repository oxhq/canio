<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class OpsAccessConfiguration
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function resolve(array $config): array
    {
        $resolved = self::merge(self::base(), self::preset((string) ($config['preset'] ?? '')));
        $resolved = self::merge($resolved, $config);
        $resolved['guards'] = self::normalizeStringList($resolved['guards'] ?? ['web']);
        $resolved['basic'] = self::merge(self::base()['basic'], is_array($resolved['basic'] ?? null) ? $resolved['basic'] : []);

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'preset' => 'local-open',
            'require_auth' => false,
            'guards' => ['web'],
            'ability' => null,
            'authorizer' => null,
            'basic' => [
                'enabled' => false,
                'username' => null,
                'password' => null,
                'realm' => 'Canio Ops',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preset(string $preset): array
    {
        return match (trim($preset)) {
            'laravel-auth' => [
                'require_auth' => true,
                'ability' => 'viewCanioOps',
            ],
            'basic-auth' => [
                'require_auth' => true,
                'basic' => [
                    'enabled' => true,
                ],
            ],
            'hybrid-auth' => [
                'require_auth' => true,
                'ability' => 'viewCanioOps',
                'basic' => [
                    'enabled' => true,
                ],
            ],
            default => [
                'require_auth' => false,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return ['web'];
        }

        $items = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        )));

        return $items === [] ? ['web'] : $items;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
