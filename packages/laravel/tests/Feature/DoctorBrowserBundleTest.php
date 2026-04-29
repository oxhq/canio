<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('reports the installed browser bundle in doctor output', function () {
    $workspace = sys_get_temp_dir().'/canio-doctor-browser-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $browserPath = $workspace.'/browsers/chrome-linux64/chrome';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    File::ensureDirectoryExists(dirname($browserPath));
    File::put($browserPath, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
    @chmod($browserPath, 0755);

    File::put($workspace.'/browsers/manifest.json', json_encode([
        'product' => 'chrome',
        'version' => '123.0.6312.86',
        'channel' => 'Stable',
        'platform' => 'linux64',
        'executablePath' => $browserPath,
    ], JSON_THROW_ON_ERROR));

    config()->set('canio.runtime.mode', 'remote');
    config()->set('canio.runtime.binary', $binaryPath);
    config()->set('canio.runtime.install_path', '');
    config()->set('canio.runtime.browser.install_path', $workspace.'/browsers');
    config()->set('canio.runtime.renderer.driver', 'local-cdp');
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:59999');

    try {
        $this->artisan('canio:doctor')
            ->expectsOutput('Runtime mode: remote')
            ->expectsOutput('Stagehand binary: '.$binaryPath)
            ->expectsOutput('Renderer driver: local-cdp')
            ->expectsOutput('Browser bundle: Chrome for Testing chrome 123.0.6312.86 (linux64)')
            ->expectsOutput('Browser executable: '.$browserPath)
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($workspace);
    }
});
