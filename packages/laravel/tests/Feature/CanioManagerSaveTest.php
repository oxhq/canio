<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Oxhq\Canio\Facades\Canio;

it('renders through stagehand and stores the pdf on the configured disk', function () {
    Storage::fake('local');
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nfake\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-123',
            'jobId' => 'job-123',
            'status' => 'completed',
            'warnings' => [],
            'timings' => ['totalMs' => 12],
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'invoice.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    $result = Canio::html('<h1>Invoice</h1>')
        ->profile('invoice')
        ->title('Invoice #123')
        ->save('documents/invoice.pdf', 'local');

    Storage::disk('local')->assertExists('documents/invoice.pdf');
    expect($result->successful())->toBeTrue()
        ->and($result->toArray()['stored']['disk'])->toBe('local')
        ->and($result->toArray()['stored']['path'])->toBe('documents/invoice.pdf');
});

it('stores to an absolute filesystem path when no disk is provided', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nabsolute\n";
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'canio-save-'.uniqid();
    $path = $directory.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'invoice.pdf';

    Http::fake([
        'http://127.0.0.1:9514/v1/renders' => Http::response([
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-absolute',
            'jobId' => 'job-absolute',
            'status' => 'completed',
            'warnings' => [],
            'timings' => ['totalMs' => 12],
            'pdf' => [
                'base64' => base64_encode($pdfBytes),
                'contentType' => 'application/pdf',
                'fileName' => 'invoice.pdf',
                'bytes' => strlen($pdfBytes),
            ],
        ]),
    ]);

    try {
        $result = Canio::html('<h1>Invoice</h1>')->save($path);

        expect(File::exists($path))->toBeTrue()
            ->and(File::get($path))->toBe($pdfBytes)
            ->and($result->toArray()['stored']['path'])->toBe($path)
            ->and($result->toArray()['stored'])->not()->toHaveKey('disk');
    } finally {
        File::deleteDirectory($directory);
    }
});
