<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('downloads and installs the matching stagehand binary', function () {
    $directory = sys_get_temp_dir().'/canio-install-'.bin2hex(random_bytes(6));
    $binaryPath = $directory.'/bin/stagehand';
    $contents = "fake-stagehand-binary\n";
    $checksum = hash('sha256', $contents);

    config()->set('canio.runtime.release.repository', 'oxhq/canio');
    config()->set('canio.runtime.release.base_url', 'https://github.com');

    Http::fake([
        'https://github.com/oxhq/canio/releases/download/v1.0.0/checksums.txt' => Http::response(
            "{$checksum}  stagehand_v1.0.0_linux_amd64\n",
        ),
        'https://github.com/oxhq/canio/releases/download/v1.0.0/stagehand_v1.0.0_linux_amd64' => Http::response(
            $contents,
            200,
            ['Content-Type' => 'application/octet-stream'],
        ),
    ]);

    $this->artisan('canio:runtime:install', [
        'version' => 'v1.0.0',
        '--path' => $binaryPath,
        '--os' => 'linux',
        '--arch' => 'amd64',
    ])
        ->expectsOutput('Downloading v1.0.0 for linux/amd64…')
        ->expectsOutput(sprintf('Installed Stagehand v1.0.0 to %s', $binaryPath))
        ->assertSuccessful();

    expect(File::exists($binaryPath))->toBeTrue()
        ->and(File::get($binaryPath))->toBe($contents);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'checksums.txt'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'stagehand_v1.0.0_linux_amd64'));

    File::deleteDirectory($directory);
});
