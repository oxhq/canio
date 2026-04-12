<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class StagehandHealthProbe
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function isReady(array $config): bool
    {
        $baseUrl = StagehandRuntimeUrl::baseUrl($config);
        $auth = $this->authHeaders($config);

        try {
            if ($auth !== []) {
                $response = Http::timeout(1)
                    ->acceptJson()
                    ->withHeaders($auth)
                    ->get($baseUrl.'/v1/runtime/status');

                return $response->successful()
                    && is_array($response->json())
                    && str_starts_with((string) data_get($response->json(), 'contractVersion', ''), 'canio.stagehand.runtime-status');
            }

            $response = Http::timeout(1)
                ->withHeaders(['Accept' => 'text/plain'])
                ->get($baseUrl.'/healthz');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && trim((string) $response->body()) === 'ok';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function waitUntilReady(array $config, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        do {
            if ($this->isReady($config)) {
                return true;
            }

            usleep(200000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function authHeaders(array $config): array
    {
        $resolvedAuth = StagehandAuthConfiguration::resolve($config);

        if (trim((string) ($resolvedAuth['shared_secret'] ?? '')) === '') {
            return [];
        }

        return (new RequestSigner($resolvedAuth))->headers('get', '/v1/runtime/status', '');
    }
}
