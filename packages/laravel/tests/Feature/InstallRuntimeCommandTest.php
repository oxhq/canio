<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('downloads and installs the matching stagehand binary', function () {
    $directory = sys_get_temp_dir().'/canio-install-'.bin2hex(random_bytes(6));
    $binaryPath = $directory.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $contents = PHP_OS_FAMILY === 'Windows'
        ? "@echo off\r\necho Usage of serve:\r\necho   -allow-private-targets\r\necho   -request-body-limit-bytes int\r\necho   -renderer-driver string\r\necho   -remote-cdp-endpoint string\r\nexit /b 1\r\n"
        : "#!/usr/bin/env sh\necho 'Usage of serve:'\necho '  -allow-private-targets'\necho '  -request-body-limit-bytes int'\necho '  -renderer-driver string'\necho '  -remote-cdp-endpoint string'\nexit 1\n";
    $checksum = hash('sha256', $contents);

    config()->set('canio.runtime.release.repository', 'oxhq/canio');
    config()->set('canio.runtime.release.base_url', 'https://github.com');

    Http::fake([
        'https://github.com/oxhq/canio/releases/download/v1.0.4/checksums.txt' => Http::response(
            "{$checksum}  stagehand_v1.0.4_linux_amd64\n",
        ),
        'https://github.com/oxhq/canio/releases/download/v1.0.4/stagehand_v1.0.4_linux_amd64' => Http::response(
            $contents,
            200,
            ['Content-Type' => 'application/octet-stream'],
        ),
    ]);

    $this->artisan('canio:runtime:install', [
        'version' => 'v1.0.4',
        '--path' => $binaryPath,
        '--os' => 'linux',
        '--arch' => 'amd64',
    ])
        ->expectsOutput('Downloading v1.0.4 for linux/amd64...')
        ->expectsOutput(sprintf('Installed Stagehand v1.0.4 to %s', $binaryPath))
        ->assertSuccessful();

    expect(File::exists($binaryPath))->toBeTrue()
        ->and(File::get($binaryPath))->toBe($contents);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'checksums.txt'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'stagehand_v1.0.4_linux_amd64'));

    File::deleteDirectory($directory);
});
