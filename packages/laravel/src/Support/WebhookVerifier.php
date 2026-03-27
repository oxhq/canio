<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class WebhookVerifier
{
    public function verify(string $body, ?string $timestamp, ?string $signature, ?string $secret): bool
    {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return true;
        }

        $timestamp = trim((string) $timestamp);
        $signature = trim((string) $signature);

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, $signature);
    }
}
