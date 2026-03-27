<?php

declare(strict_types=1);

use Oxhq\Canio\CanioManager;

it('builds a stagehand render spec from the fluent api', function () {
    /** @var CanioManager $canio */
    $canio = app(CanioManager::class);

    $spec = $canio->view('pdf.invoice', ['invoice' => 123])
        ->profile('invoice')
        ->format('letter')
        ->landscape()
        ->margins(10, 10, 14, 10)
        ->background()
        ->title('Invoice #123')
        ->locale('es_MX')
        ->pageNumbers()
        ->watermark('DRAFT')
        ->timeout(45)
        ->retries(2)
        ->retryBackoff(3, 12)
        ->debug()
        ->watch()
        ->queue('redis', 'pdfs')
        ->toRenderSpec()
        ->toArray();

    expect($spec['contractVersion'])->toBe('canio.stagehand.render-spec.v1')
        ->and($spec['source']['type'])->toBe('view')
        ->and($spec['source']['payload']['view'])->toBe('pdf.invoice')
        ->and($spec['profile'])->toBe('invoice')
        ->and($spec['presentation']['format'])->toBe('letter')
        ->and($spec['presentation']['landscape'])->toBeTrue()
        ->and($spec['document']['title'])->toBe('Invoice #123')
        ->and($spec['document']['locale'])->toBe('es_MX')
        ->and($spec['postprocess']['watermark'])->toBe('DRAFT')
        ->and($spec['execution']['timeout'])->toBe(45)
        ->and($spec['execution']['retries'])->toBe(2)
        ->and($spec['execution']['retryBackoff'])->toBe(3)
        ->and($spec['execution']['retryBackoffMax'])->toBe(12)
        ->and($spec['debug']['enabled'])->toBeTrue()
        ->and($spec['debug']['watch'])->toBeTrue()
        ->and($spec['queue']['enabled'])->toBeTrue()
        ->and($spec['queue']['connection'])->toBe('redis')
        ->and($spec['queue']['queue'])->toBe('pdfs');
});
