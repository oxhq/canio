<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Oxhq\Canio\Facades\Canio;

it('renders blade views to html before sending them to stagehand', function () {
    Storage::fake('local');
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nblade\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-view',
            'jobId' => 'job-view',
            'status' => 'completed',
            'warnings' => [],
            'timings' => ['totalMs' => 18],
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'invoice.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    Canio::view('invoice', ['title' => 'Invoice #123'])
        ->save('documents/view.pdf', 'local');

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return is_array($payload)
            && data_get($payload, 'source.type') === 'html'
            && data_get($payload, 'source.payload.origin.type') === 'view'
            && data_get($payload, 'source.payload.origin.view') === 'invoice'
            && data_get($payload, 'source.payload.baseUrl') === 'https://canio.test'
            && str_contains((string) data_get($payload, 'source.payload.html'), 'Invoice #123');
    });
});
