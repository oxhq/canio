<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('downloads and installs a Chrome for Testing browser bundle', function () {
    $workspace = sys_get_temp_dir().'/canio-browser-install-'.bin2hex(random_bytes(6));
    $installPath = $workspace.'/browsers';
    $zipContents = chromeTestingZip('chrome-linux64/chrome');

    config()->set('canio.runtime.browser.install_path', $installPath);
    config()->set('canio.runtime.browser.manifest_url', 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json');

    Http::fake([
        'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json' => Http::response([
            'channels' => [
                'Stable' => [
                    'version' => '123.0.6312.86',
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
        'https://storage.example/chrome-linux64.zip' => Http::response($zipContents, 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        $this->artisan('canio:browser:install', [
            '--platform' => 'linux64',
        ])
            ->expectsOutput('Resolving Chrome for Testing chrome Stable for linux64...')
            ->expectsOutput('Installing Chrome for Testing chrome 123.0.6312.86...')
            ->expectsOutputToContain('Installed Chrome for Testing chrome 123.0.6312.86 to ')
            ->assertSuccessful();

        $manifestPath = $installPath.'/manifest.json';
        expect(File::exists($manifestPath))->toBeTrue();

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest)
            ->toMatchArray([
                'product' => 'chrome',
                'version' => '123.0.6312.86',
                'channel' => 'Stable',
                'platform' => 'linux64',
                'downloadUrl' => 'https://storage.example/chrome-linux64.zip',
            ])
            ->and(File::exists($manifest['executablePath']))->toBeTrue();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://storage.example/chrome-linux64.zip');
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('can install a configured Chrome for Testing browser product', function () {
    $workspace = sys_get_temp_dir().'/canio-browser-install-'.bin2hex(random_bytes(6));
    $installPath = $workspace.'/browsers';
    $zipContents = chromeTestingZip('chrome-headless-shell-linux64/chrome-headless-shell');

    config()->set('canio.runtime.browser.install_path', $installPath);
    config()->set('canio.runtime.browser.product', 'chrome-headless-shell');
    config()->set('canio.runtime.browser.manifest_url', 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json');

    Http::fake([
        'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json' => Http::response([
            'channels' => [
                'Stable' => [
                    'version' => '123.0.6312.86',
                    'downloads' => [
                        'chrome-headless-shell' => [
                            [
                                'platform' => 'linux64',
                                'url' => 'https://storage.example/chrome-headless-shell-linux64.zip',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        'https://storage.example/chrome-headless-shell-linux64.zip' => Http::response($zipContents, 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        $this->artisan('canio:browser:install', [
            '--platform' => 'linux64',
        ])
            ->expectsOutput('Resolving Chrome for Testing chrome-headless-shell Stable for linux64...')
            ->expectsOutput('Installing Chrome for Testing chrome-headless-shell 123.0.6312.86...')
            ->expectsOutputToContain('Installed Chrome for Testing chrome-headless-shell 123.0.6312.86 to ')
            ->assertSuccessful();

        $manifest = json_decode(File::get($installPath.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest)
            ->toMatchArray([
                'product' => 'chrome-headless-shell',
                'version' => '123.0.6312.86',
                'channel' => 'Stable',
                'platform' => 'linux64',
                'downloadUrl' => 'https://storage.example/chrome-headless-shell-linux64.zip',
            ])
            ->and(File::exists($manifest['executablePath']))->toBeTrue();
    } finally {
        File::deleteDirectory($workspace);
    }
});

function chromeTestingZip(string $executablePath): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'canio-cft-');
    $zip = new ZipArchive;

    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create test zip.');
    }

    $zip->addFromString($executablePath, '#!/usr/bin/env sh'.PHP_EOL.'exit 0'.PHP_EOL);
    $zip->close();

    $contents = File::get($zipPath);
    File::delete($zipPath);

    return $contents;
}
