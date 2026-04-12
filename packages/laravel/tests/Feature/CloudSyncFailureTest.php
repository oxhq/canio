<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Events\CanioCloudSyncFailed;
use Oxhq\Canio\Facades\Canio;

it('records render sync failures without breaking the render', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');
    config()->set('canio.cloud.mode', 'sync');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'sync-token-123');
    config()->set('canio.cloud.project', 'project-alpha');
    config()->set('canio.cloud.environment', 'staging');

    Event::fake([CanioCloudSyncFailed::class]);

    $pdfBytes = "%PDF-1.4\nsync-fail\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-sync-fail',
            'jobId' => 'job-sync-fail',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'sync-fail.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
        'https://cloud.canio.test/api/sync/v1/job-events' => Http::response([
            'error' => 'sync failed',
        ], 500),
    ]);

    $result = Canio::html('<h1>Sync</h1>')->render();

    expect($result->successful())->toBeTrue()
        ->and($result->requestId())->toBe('req-sync-fail')
        ->and($result->jobId())->toBe('job-sync-fail');

    Event::assertDispatched(CanioCloudSyncFailed::class, function (CanioCloudSyncFailed $event): bool {
        return $event->operation === 'render'
            && $event->context['requestId'] === 'req-sync-fail'
            && $event->context['jobId'] === 'job-sync-fail'
            && $event->context['status'] === 'completed'
            && $event->exceptionClass === RuntimeException::class
            && str_contains($event->exceptionMessage, 'Canio Cloud request to /api/sync/v1/job-events failed with status 500');
    });
});
