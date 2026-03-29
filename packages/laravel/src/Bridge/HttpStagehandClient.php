<?php

declare(strict_types=1);

namespace Oxhq\Canio\Bridge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;
use Oxhq\Canio\Support\RequestSigner;
use Oxhq\Canio\Support\SseDecoder;
use Oxhq\Canio\Support\NullStagehandRuntimeBootstrapper;
use RuntimeException;
use stdClass;

final class HttpStagehandClient implements StagehandClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        ?StagehandRuntimeBootstrapper $bootstrapper = null,
    ) {
        $rootAuth = is_array($this->config['auth'] ?? null) ? $this->config['auth'] : [];
        $legacyAuth = data_get($this->config, 'jobs.auth', []);
        $legacyAuth = is_array($legacyAuth) ? $legacyAuth : [];

        $resolvedAuth = array_replace($legacyAuth, array_filter(
            $rootAuth,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        $this->signer = new RequestSigner($resolvedAuth);
        $this->decoder = new SseDecoder;
        $this->bootstrapper = $bootstrapper ?? new NullStagehandRuntimeBootstrapper;
    }

    private RequestSigner $signer;

    private SseDecoder $decoder;

    private StagehandRuntimeBootstrapper $bootstrapper;

    public function render(RenderSpec $spec): RenderResult
    {
        $payload = $this->request('post', '/v1/renders', $spec->toArray());

        return RenderResult::fromArray($payload);
    }

    public function dispatch(RenderSpec $spec): RenderJob
    {
        $payload = $this->request('post', '/v1/jobs', $spec->toArray());

        return RenderJob::fromArray($payload);
    }

    public function job(string $jobId): RenderJob
    {
        $payload = $this->request('get', '/v1/jobs/'.rawurlencode($jobId));

        return RenderJob::fromArray($payload);
    }

    public function jobs(int $limit = 20): array
    {
        return $this->request('get', '/v1/jobs', query: [
            'limit' => max(1, $limit),
        ]);
    }

    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        $path = '/v1/jobs/'.rawurlencode($jobId).'/events';
        $query = $since !== null ? '?since='.$since : '';
        $stream = $this->openStream($path, $query);

        return (function () use ($stream): iterable {
            try {
                yield from $this->decoder->decode($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        })();
    }

    public function cancelJob(string $jobId): RenderJob
    {
        $payload = $this->request('post', '/v1/jobs/'.rawurlencode($jobId).'/cancel');

        return RenderJob::fromArray($payload);
    }

    public function artifact(string $artifactId): array
    {
        return $this->request('get', '/v1/artifacts/'.rawurlencode($artifactId));
    }

    public function artifacts(int $limit = 20): array
    {
        return $this->request('get', '/v1/artifacts', query: [
            'limit' => max(1, $limit),
        ]);
    }

    public function deadLetters(): array
    {
        return $this->request('get', '/v1/dead-letters');
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        $payload = $this->request('post', '/v1/dead-letters/requeues', [
            'deadLetterId' => $deadLetterId,
        ]);

        return RenderJob::fromArray($payload);
    }

    public function cleanupDeadLetters(?int $olderThanDays = null): array
    {
        $payload = [];

        if ($olderThanDays !== null) {
            $payload['olderThanDays'] = $olderThanDays;
        }

        return $this->request('post', '/v1/dead-letters/cleanup', $payload);
    }

    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
    {
        $payload = array_filter([
            'jobsOlderThanDays' => $jobsOlderThanDays,
            'artifactsOlderThanDays' => $artifactsOlderThanDays,
            'deadLettersOlderThanDays' => $deadLettersOlderThanDays,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->request('post', '/v1/runtime/cleanup', $payload);
    }

    public function replay(string $artifactId): RenderResult
    {
        $payload = $this->request('post', '/v1/replays', [
            'artifactId' => $artifactId,
        ]);

        return RenderResult::fromArray($payload);
    }

    public function status(): array
    {
        return $this->request('get', '/v1/runtime/status');
    }

    public function restart(): array
    {
        return $this->request('post', '/v1/runtime/restart');
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $this->bootstrapper->ensureAvailable();

        $body = $method === 'get' ? '' : $this->encodePayload($payload);

        try {
            $pendingRequest = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout($this->timeout())
                ->withHeaders($this->authHeaders($method, $path, $body));

            $response = $method === 'get'
                ? $pendingRequest->get($path, $query)
                : $pendingRequest
                    ->withBody($body, 'application/json')
                    ->send(strtoupper($method), $path, ['query' => $query]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException($this->runtimeUnavailableMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Stagehand request to %s failed with status %d: %s',
                $path,
                $response->status(),
                trim($response->body()),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(sprintf(
                'Stagehand request to %s did not return a JSON object.',
                $path,
            ));
        }

        return $json;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'http://127.0.0.1:9514'), '/');
    }

    private function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 30);
    }

    private function streamTimeout(): int
    {
        return max($this->timeout(), 600);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function authHeaders(string $method, string $path, string $body): array
    {
        return $this->signer->headers($method, $path, $body);
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode(
                $this->normalizePayloadForJson($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to JSON encode the Stagehand request payload.', previous: $exception);
        }
    }

    private function normalizePayloadForJson(array $payload): mixed
    {
        return $this->normalizeValueForJson($payload, '');
    }

    private function normalizeValueForJson(mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return $this->payloadPathExpectsObject($path) ? new stdClass : [];
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalizeValueForJson($item, $path),
                $value,
            );
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $normalized[$key] = $this->normalizeValueForJson($item, $childPath);
        }

        return $normalized;
    }

    private function payloadPathExpectsObject(string $path): bool
    {
        return in_array($path, [
            '',
            'source',
            'source.payload',
            'source.payload.origin',
            'presentation',
            'document',
            'document.meta',
            'execution',
            'postprocess',
            'postprocess.encrypt',
            'debug',
            'queue',
            'output',
            'correlation',
        ], true);
    }

    /**
     * @return resource
     */
    private function openStream(string $path, string $query = '')
    {
        $this->bootstrapper->ensureAvailable();

        $url = $this->baseUrl().$path.$query;
        $headers = array_merge(
            ['Accept' => 'text/event-stream'],
            $this->authHeaders('get', $path, ''),
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $this->formatStreamHeaders($headers),
                'ignore_errors' => true,
                'timeout' => $this->streamTimeout(),
            ],
        ]);

        $stream = @fopen($url, 'r', false, $context);

        if (! is_resource($stream)) {
            throw new RuntimeException($this->runtimeUnavailableMessage());
        }

        $responseHeaders = is_array($http_response_header ?? null) ? $http_response_header : [];
        $status = $this->streamStatus($responseHeaders);

        if ($status >= 400) {
            $body = trim((string) stream_get_contents($stream));
            fclose($stream);

            throw new RuntimeException(sprintf(
                'Stagehand request to %s failed with status %d: %s',
                $path,
                $status,
                $body,
            ));
        }

        return $stream;
    }

    private function runtimeUnavailableMessage(): string
    {
        $mode = strtolower(trim((string) ($this->config['mode'] ?? 'embedded')));

        if ($mode === 'embedded') {
            return sprintf(
                'Unable to reach the embedded Stagehand runtime at %s. Canio tried to start it automatically. Check canio.runtime.binary, canio.runtime.install_path, or %s.',
                $this->baseUrl(),
                (string) ($this->config['log_path'] ?? storage_path('logs/canio-runtime.log')),
            );
        }

        return sprintf(
            'Unable to reach Stagehand at %s. Start it with `php artisan canio:serve` or update canio.runtime.base_url.',
            $this->baseUrl(),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function formatStreamHeaders(array $headers): string
    {
        return implode("\r\n", array_map(
            static fn (string $name, string $value): string => $name.': '.$value,
            array_keys($headers),
            array_values($headers),
        ));
    }

    /**
     * @param  list<string>  $headers
     */
    private function streamStatus(array $headers): int
    {
        $statusLine = $headers[0] ?? '';

        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 200;
    }
}
