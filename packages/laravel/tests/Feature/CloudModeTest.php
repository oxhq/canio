<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Oxhq\Canio\Facades\Canio;

it('keeps local rendering untouched when cloud mode is off', function () {
    Storage::fake('local');

    config()->set('canio.cloud.mode', 'off');
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\noff\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-off',
            'jobId' => 'job-off',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'off.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    Canio::html('<h1>Off</h1>')->save('documents/off.pdf', 'local');

    Storage::disk('local')->assertExists('documents/off.pdf');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9514/v1/renders');
});

it('syncs local render snapshots and artifacts to canio cloud', function () {
    Storage::fake('local');

    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');
    config()->set('canio.cloud.mode', 'sync');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'cloud-token-123');
    config()->set('canio.cloud.project', 'project-alpha');
    config()->set('canio.cloud.environment', 'staging');
    config()->set('canio.cloud.sync.enabled', true);
    config()->set('canio.cloud.sync.include_artifacts', true);

    $pdfBytes = "%PDF-1.4\nsync\n";
    $artifactDirectory = sys_get_temp_dir().'/canio-cloud-sync-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($artifactDirectory);
    File::put($artifactDirectory.'/render.pdf', $pdfBytes);
    File::put($artifactDirectory.'/metadata.json', json_encode(['ok' => true], JSON_THROW_ON_ERROR));

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-sync',
            'jobId' => 'job-sync',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'sync.pdf',
                'bytes' => strlen($pdfBytes),
            ],
            'artifacts' => [
                'id' => 'art-sync',
                'directory' => $artifactDirectory,
                'files' => [
                    'pdf' => $artifactDirectory.'/render.pdf',
                    'metadata' => $artifactDirectory.'/metadata.json',
                ],
            ],
        ]),
        'https://cloud.canio.test/api/sync/v1/job-events' => Http::response([
            'ok' => true,
        ]),
        'https://cloud.canio.test/api/sync/v1/artifacts' => Http::response([
            'ok' => true,
        ]),
    ]);

    Canio::html('<h1>Sync</h1>')->save('documents/sync.pdf', 'local');

    Storage::disk('local')->assertExists('documents/sync.pdf');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9514/v1/renders');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://cloud.canio.test/api/sync/v1/job-events') {
            return false;
        }

        $payload = $request->data();

        return data_get($payload, 'contractVersion') === 'canio.cloud.job-event.v1'
            && data_get($payload, 'project') === 'project-alpha'
            && data_get($payload, 'environment') === 'staging'
            && data_get($payload, 'event.job.result.artifacts.id') === 'art-sync'
            && $request->hasHeader('Authorization', 'Bearer cloud-token-123')
            && $request->hasHeader('X-Canio-Project', 'project-alpha')
            && $request->hasHeader('X-Canio-Environment', 'staging');
    });

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://cloud.canio.test/api/sync/v1/artifacts'
            && $request->hasHeader('Authorization', 'Bearer cloud-token-123')
            && str_contains($request->body(), '"contractVersion":"canio.cloud.artifact.v1"')
            && str_contains($request->body(), 'render.pdf')
            && str_contains($request->body(), 'metadata.json');
    });
});

it('uses the managed cloud runtime without requiring a local stagehand base url', function () {
    Storage::fake('local');

    config()->set('canio.runtime.base_url', null);
    config()->set('canio.cloud.mode', 'managed');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'managed-token-123');
    config()->set('canio.cloud.project', 'project-beta');
    config()->set('canio.cloud.environment', 'production');

    $pdfBytes = "%PDF-1.4\nmanaged\n";

    Http::fake([
        'https://cloud.canio.test/api/runtime/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-managed',
            'jobId' => 'job-managed',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'managed.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    Canio::html('<h1>Managed</h1>')->save('documents/managed.pdf', 'local');

    Storage::disk('local')->assertExists('documents/managed.pdf');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://cloud.canio.test/api/runtime/v1/renders'
            && $request->hasHeader('Authorization', 'Bearer managed-token-123')
            && $request->hasHeader('X-Canio-Project', 'project-beta')
            && $request->hasHeader('X-Canio-Environment', 'production');
    });
});

it('sends cloud template sources to the managed cloud runtime', function () {
    Storage::fake('local');

    config()->set('canio.runtime.base_url', null);
    config()->set('canio.cloud.mode', 'managed');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'managed-token-123');
    config()->set('canio.cloud.project', 'project-beta');
    config()->set('canio.cloud.environment', 'production');

    $pdfBytes = "%PDF-1.4\ntemplate\n";

    Http::fake([
        'https://cloud.canio.test/api/runtime/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-template',
            'jobId' => 'job-template',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'template.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    Canio::template('invoice.default', ['invoice' => 123])
        ->version('v7')
        ->release('prod-live')
        ->save('documents/template.pdf', 'local');

    Storage::disk('local')->assertExists('documents/template.pdf');
    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'https://cloud.canio.test/api/runtime/v1/renders'
            && data_get($payload, 'source.type') === 'cloud_template'
            && data_get($payload, 'source.payload.template') === 'invoice.default'
            && data_get($payload, 'source.payload.version') === 'v7'
            && data_get($payload, 'source.payload.release') === 'prod-live'
            && data_get($payload, 'source.payload.data.invoice') === 123;
    });
});

it('fails clearly when using cloud templates outside managed mode', function () {
    config()->set('canio.cloud.mode', 'off');

    expect(fn () => Canio::template('invoice.default', ['invoice' => 123])->render())
        ->toThrow(RuntimeException::class, 'Canio cloud templates require cloud.mode=managed.');
});
