<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CanioCloudSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('canio.runtime.mode', 'remote');
        config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');
        config()->set('canio.cloud.base_url', 'http://127.0.0.1:9081');
        config()->set('canio.cloud.token', 'cloud-token');
        config()->set('canio.cloud.project', 'project-key');
        config()->set('canio.cloud.environment', 'environment-key');
        config()->set('canio.cloud.sync.enabled', true);
        config()->set('canio.cloud.sync.include_artifacts', true);
    }

    public function test_sync_mode_renders_locally_and_syncs_to_canio_cloud(): void
    {
        $artifactDirectory = storage_path('app/canio-smoke-sync');
        File::ensureDirectoryExists($artifactDirectory);
        File::put($artifactDirectory.'/invoice.pdf', '%PDF-1.4 sync');
        File::put($artifactDirectory.'/metadata.json', json_encode(['mode' => 'sync'], JSON_THROW_ON_ERROR));

        Http::fake([
            'http://127.0.0.1:9514/v1/renders' => Http::response([
                'contractVersion' => 'canio.stagehand.render-result.v1',
                'requestId' => 'req-sync',
                'jobId' => 'job-sync',
                'status' => 'completed',
                'pdf' => [
                    'base64' => base64_encode('%PDF-1.4 sync'),
                    'contentType' => 'application/pdf',
                    'fileName' => 'sync.pdf',
                    'bytes' => 13,
                ],
                'artifacts' => [
                    'id' => 'art-sync',
                    'directory' => $artifactDirectory,
                    'files' => [
                        'pdf' => $artifactDirectory.'/invoice.pdf',
                        'metadata' => $artifactDirectory.'/metadata.json',
                    ],
                ],
            ]),
            'http://127.0.0.1:9081/api/sync/v1/job-events' => Http::response(['ok' => true]),
            'http://127.0.0.1:9081/api/sync/v1/artifacts' => Http::response(['ok' => true]),
        ]);

        $response = $this->get('/canio/cloud/sync/preview');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9514/v1/renders');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9081/api/sync/v1/job-events');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9081/api/sync/v1/artifacts');
    }

    public function test_managed_mode_uses_canio_cloud_runtime(): void
    {
        Http::fake([
            'http://127.0.0.1:9081/api/runtime/v1/renders' => Http::response([
                'contractVersion' => 'canio.stagehand.render-result.v1',
                'requestId' => 'req-managed',
                'jobId' => 'job-managed',
                'status' => 'completed',
                'pdf' => [
                    'base64' => base64_encode('%PDF-1.4 managed'),
                    'contentType' => 'application/pdf',
                    'fileName' => 'managed.pdf',
                    'bytes' => 16,
                ],
            ]),
        ]);

        $response = $this->get('/canio/cloud/managed/preview');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9081/api/runtime/v1/renders');
    }
}
