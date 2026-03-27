<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Facades\Canio;

it('exposes artifact metadata returned by stagehand', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nartifacts\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-debug',
            'jobId' => 'job-debug',
            'status' => 'completed',
            'warnings' => [],
            'timings' => ['totalMs' => 15],
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'debug.pdf',
                'bytes' => strlen($pdfBytes),
            ],
            'artifacts' => [
                'id' => 'art-123',
                'directory' => '/tmp/canio/artifacts/art-123',
                'files' => [
                    'renderSpec' => '/tmp/canio/artifacts/art-123/render-spec.json',
                    'pdf' => '/tmp/canio/artifacts/art-123/debug.pdf',
                ],
            ],
        ]),
    ]);

    $result = Canio::html('<h1>Artifact render</h1>')
        ->debug()
        ->render();

    expect($result->artifactId())->toBe('art-123');

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return is_array($payload)
            && data_get($payload, 'debug.enabled') === true;
    });
});
