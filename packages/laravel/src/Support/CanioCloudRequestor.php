<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CanioCloudRequestor
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, array $payload = [], array $query = []): array
    {
        try {
            $pending = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout($this->timeout())
                ->withHeaders($this->headers());

            $response = $method === 'get'
                ? $pending->get($path, $query)
                : $pending->send(strtoupper($method), $path, [
                    'query' => $query,
                    'json' => $payload,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(sprintf(
                'Unable to reach Canio Cloud at %s.',
                $this->baseUrl(),
            ), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Canio Cloud request to %s failed with status %d: %s',
                $path,
                $response->status(),
                trim($response->body()),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(sprintf(
                'Canio Cloud request to %s did not return a JSON object.',
                $path,
            ));
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    public function multipart(string $path, array $manifest, array $files = []): array
    {
        try {
            $pending = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout(max(60, $this->timeout()))
                ->withHeaders($this->headers())
                ->asMultipart();

            $parts = [[
                'name' => 'manifest',
                'contents' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]];

            foreach ($files as $key => $pathOnDisk) {
                if (! is_file($pathOnDisk)) {
                    continue;
                }

                $parts[] = [
                    'name' => sprintf('files[%s]', $key),
                    'contents' => fopen($pathOnDisk, 'r'),
                    'filename' => basename($pathOnDisk),
                    'headers' => [
                        'Content-Type' => 'application/octet-stream',
                    ],
                ];
            }

            $response = $pending->send('POST', $path, [
                'multipart' => $parts,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(sprintf(
                'Unable to reach Canio Cloud at %s.',
                $this->baseUrl(),
            ), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Canio Cloud request to %s failed with status %d: %s',
                $path,
                $response->status(),
                trim($response->body()),
            ));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(sprintf(
                'Canio Cloud request to %s did not return a JSON object.',
                $path,
            ));
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return resource
     */
    public function stream(string $path, array $query = [])
    {
        $queryString = $query !== [] ? '?'.http_build_query($query) : '';
        $url = $this->baseUrl().$path.$queryString;
        $stream = @fopen($url, 'r', false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $this->headerString(array_merge(
                    ['Accept' => 'text/event-stream'],
                    $this->headers(),
                )),
                'ignore_errors' => true,
                'timeout' => max(600, $this->timeout()),
            ],
        ]));

        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf(
                'Unable to open Canio Cloud stream at %s.',
                $url,
            ));
        }

        $responseHeaders = http_get_last_response_headers();
        $responseHeaders = is_array($responseHeaders) ? $responseHeaders : [];
        $status = $this->streamStatus($responseHeaders);

        if ($status >= 400) {
            $body = trim((string) stream_get_contents($stream));
            fclose($stream);

            throw new RuntimeException(sprintf(
                'Canio Cloud request to %s failed with status %d: %s',
                $path,
                $status,
                $body,
            ));
        }

        return $stream;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $token = trim((string) ($this->config['token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('Canio Cloud token is missing. Set canio.cloud.token or CANIO_CLOUD_TOKEN.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        $project = trim((string) ($this->config['project'] ?? ''));
        if ($project !== '') {
            $headers['X-Canio-Project'] = $project;
        }

        $environment = trim((string) ($this->config['environment'] ?? ''));
        if ($environment !== '') {
            $headers['X-Canio-Environment'] = $environment;
        }

        return $headers;
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Canio Cloud base URL is missing. Set canio.cloud.base_url or CANIO_CLOUD_BASE_URL.');
        }

        return $baseUrl;
    }

    private function timeout(): int
    {
        return max(1, (int) ($this->config['timeout'] ?? 30));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headerString(array $headers): string
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
