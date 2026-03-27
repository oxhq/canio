<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use DateTimeInterface;

final class RequestSigner
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function headers(string $method, string $path, string $body = '', ?DateTimeInterface $timestamp = null): array
    {
        $secret = trim((string) ($this->config['shared_secret'] ?? ''));

        if ($secret === '') {
            return [];
        }

        $algorithm = trim((string) ($this->config['algorithm'] ?? 'canio-v1')) ?: 'canio-v1';
        $timestampHeader = trim((string) ($this->config['timestamp_header'] ?? 'X-Canio-Timestamp')) ?: 'X-Canio-Timestamp';
        $signatureHeader = trim((string) ($this->config['signature_header'] ?? 'X-Canio-Signature')) ?: 'X-Canio-Signature';
        $timestampValue = ($timestamp ?? now('UTC'))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339);
        $canonical = $this->canonicalRequest($method, $path, $body, $timestampValue);
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            $timestampHeader => $timestampValue,
            $signatureHeader => $algorithm.'='.$signature,
        ];
    }

    private function canonicalRequest(string $method, string $path, string $body, string $timestamp): string
    {
        return implode("\n", [
            strtoupper(trim($method)),
            trim($path),
            $timestamp,
            hash('sha256', $body),
        ]);
    }
}
