<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class WebhookVerifier
{
    public function verify(string $body, ?string $timestamp, ?string $signature, ?string $secret, ?int $maxSkewSeconds = null): bool
    {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return false;
        }

        $timestamp = trim((string) $timestamp);
        $signature = trim((string) $signature);

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if ($maxSkewSeconds !== null) {
            $timestampValue = $this->parseTimestamp($timestamp);

            if ($timestampValue === null) {
                return false;
            }

            $skew = abs(now('UTC')->timestamp - $timestampValue);
            if ($skew > max(0, $maxSkewSeconds)) {
                return false;
            }
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, $signature);
    }

    private function parseTimestamp(string $timestamp): ?int
    {
        if (is_numeric($timestamp)) {
            return (int) $timestamp;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed;
    }
}
