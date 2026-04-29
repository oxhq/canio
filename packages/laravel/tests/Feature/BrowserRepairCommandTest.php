<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('repairs a stale browser bundle by reinstalling it', function () {
    $workspace = sys_get_temp_dir().'/canio-browser-repair-'.bin2hex(random_bytes(6));
    $installPath = $workspace.'/browsers';

    File::ensureDirectoryExists($installPath);
    File::put($installPath.'/manifest.json', json_encode([
        'product' => 'chrome',
        'version' => 'old',
        'channel' => 'Stable',
        'platform' => 'linux64',
        'executablePath' => $installPath.'/missing/chrome',
    ], JSON_THROW_ON_ERROR));

    config()->set('canio.runtime.browser.install_path', $installPath);
    config()->set('canio.runtime.browser.manifest_url', 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json');

    Http::fake([
        'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json' => Http::response([
            'channels' => [
                'Stable' => [
                    'version' => '124.0.6367.91',
                    'downloads' => [
                        'chrome' => [
                            [
                                'platform' => 'linux64',
                                'url' => 'https://storage.example/chrome-linux64.zip',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        'https://storage.example/chrome-linux64.zip' => Http::response(chromeTestingZip('chrome-linux64/chrome'), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        $this->artisan('canio:browser:repair', [
            '--platform' => 'linux64',
        ])
            ->expectsOutput('Repairing Chrome for Testing browser bundle...')
            ->expectsOutput('Installed Chrome for Testing chrome 124.0.6367.91 to '.$installPath)
            ->assertSuccessful();

        $manifest = json_decode(File::get($installPath.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['product'])->toBe('chrome')
            ->and($manifest['version'])->toBe('124.0.6367.91')
            ->and(File::exists($manifest['executablePath']))->toBeTrue();
    } finally {
        File::deleteDirectory($workspace);
    }
});
