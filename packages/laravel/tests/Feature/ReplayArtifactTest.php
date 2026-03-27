<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\CanioManager;

it('replays artifacts through stagehand', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nreplay\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/replays' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-replay',
            'jobId' => 'job-replay',
            'status' => 'completed',
            'warnings' => [],
            'timings' => ['totalMs' => 20],
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'replayed.pdf',
                'bytes' => strlen($pdfBytes),
            ],
            'artifacts' => [
                'id' => 'art-replay-new',
                'replayOf' => 'art-123',
                'directory' => '/tmp/canio/artifacts/art-replay-new',
                'files' => [
                    'renderSpec' => '/tmp/canio/artifacts/art-replay-new/render-spec.json',
                    'pdf' => '/tmp/canio/artifacts/art-replay-new/replayed.pdf',
                ],
            ],
        ]),
    ]);

    /** @var CanioManager $canio */
    $canio = app(CanioManager::class);
    $result = $canio->replay('art-123');

    expect($result->successful())->toBeTrue()
        ->and($result->artifactId())->toBe('art-replay-new')
        ->and($result->artifacts()['replayOf'])->toBe('art-123');

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'http://127.0.0.1:9514/v1/replays'
            && is_array($payload)
            && ($payload['artifactId'] ?? null) === 'art-123';
    });
});
