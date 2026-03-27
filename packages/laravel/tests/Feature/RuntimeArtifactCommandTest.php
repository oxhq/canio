<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('inspects an artifact through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/artifacts/art-123' => Http::response([
            'contractVersion' => 'canio.stagehand.artifact.v1',
            'id' => 'art-123',
            'requestId' => 'req-123',
            'status' => 'completed',
            'createdAt' => now()->subMinute()->toIso8601String(),
            'sourceType' => 'html',
            'profile' => 'invoice',
            'directory' => '/tmp/canio/artifacts/art-123',
            'output' => [
                'fileName' => 'invoice.pdf',
                'bytes' => 12048,
            ],
            'files' => [
                'metadata' => '/tmp/canio/artifacts/art-123/metadata.json',
                'pdf' => '/tmp/canio/artifacts/art-123/invoice.pdf',
            ],
        ]),
    ]);

    $this->artisan('canio:runtime:artifact', [
        'id' => 'art-123',
    ])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:9514/v1/artifacts/art-123');
});
