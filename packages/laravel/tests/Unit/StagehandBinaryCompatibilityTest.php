<?php

declare(strict_types=1);

use Oxhq\Canio\Support\StagehandBinaryCompatibility;

it('accepts a stagehand help surface with every required serve flag', function () {
    $compatibility = new StagehandBinaryCompatibility;

    $compatibility->assertCompatibleHelp(implode(PHP_EOL, [
        'Usage of serve:',
        '  -allowed-target-hosts string',
        '  -allow-private-targets',
        '  -request-body-limit-bytes int',
        '  -renderer-driver string',
        '  -remote-cdp-endpoint string',
    ]), 'stagehand');

    expect(true)->toBeTrue();
});

it('rejects stale stagehand binaries that do not expose required serve flags', function () {
    $compatibility = new StagehandBinaryCompatibility;

    $compatibility->assertCompatibleHelp(implode(PHP_EOL, [
        'Usage of serve:',
        '  -allowed-target-hosts string',
        '  -request-logging',
    ]), 'stagehand_v1.0.1_windows_amd64.exe');
})->throws(RuntimeException::class, 'Stagehand binary stagehand_v1.0.1_windows_amd64.exe is incompatible with this Canio package. Missing serve flags: --allow-private-targets, --request-body-limit-bytes, --renderer-driver, --remote-cdp-endpoint.');
