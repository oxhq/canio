<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Support\StagehandServeCommandBuilder;

it('applies production-safe defaults when runtime settings are omitted', function () {
    config()->set('app.env', 'production');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));

    $workspace = sys_get_temp_dir().'/canio-serve-builder-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $statePath = $workspace.'/state';
    $logPath = $workspace.'/logs/runtime.log';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    $builder = app(StagehandServeCommandBuilder::class);

    try {
        $command = $builder->build([
            'binary' => $binaryPath,
            'working_directory' => $workspace,
            'state_path' => $statePath,
            'log_path' => $logPath,
        ], host: '0.0.0.0', port: 9514);

        expect($command)->toContain('--ignore-https-errors=false')
            ->toContain('--allow-private-targets=false')
            ->toContain('--renderer-driver')
            ->toContain('rod-cdp')
            ->toContain('--job-backend')
            ->toContain('redis')
            ->toContain('--auth-shared-secret')
            ->toContain(hash('sha256', config('app.key').':canio-runtime'));
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('forwards navigation policy and explicit runtime overrides to stagehand', function () {
    config()->set('app.env', 'local');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));

    $workspace = sys_get_temp_dir().'/canio-serve-builder-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $statePath = $workspace.'/state';
    $logPath = $workspace.'/logs/runtime.log';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    $builder = app(StagehandServeCommandBuilder::class);

    try {
        $command = $builder->build([
            'binary' => $binaryPath,
            'working_directory' => $workspace,
            'state_path' => $statePath,
            'log_path' => $logPath,
            'chromium' => [
                'ignore_https_errors' => true,
            ],
            'renderer' => [
                'driver' => 'remote-cdp',
                'remote_cdp' => [
                    'endpoint' => 'ws://127.0.0.1:9222/devtools/browser/test',
                ],
            ],
            'navigation' => [
                'allowed_hosts' => 'example.com,*.example.com',
                'allow_private_targets' => true,
            ],
            'jobs' => [
                'backend' => 'redis',
            ],
            'auth' => [
                'shared_secret' => 'runtime-secret',
            ],
        ]);

        expect($command)->toContain('--ignore-https-errors=true')
            ->toContain('--allow-private-targets=true')
            ->toContain('--renderer-driver')
            ->toContain('remote-cdp')
            ->toContain('--remote-cdp-endpoint')
            ->toContain('ws://127.0.0.1:9222/devtools/browser/test')
            ->toContain('--allowed-target-hosts')
            ->toContain('example.com,*.example.com')
            ->toContain('--job-backend')
            ->toContain('redis')
            ->toContain('--auth-shared-secret')
            ->toContain('runtime-secret');
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('uses an installed browser bundle when chromium path is not configured', function () {
    config()->set('app.env', 'local');

    $workspace = sys_get_temp_dir().'/canio-serve-builder-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $browserPath = $workspace.'/browsers/chrome-linux64/chrome';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    File::ensureDirectoryExists(dirname($browserPath));
    File::put($browserPath, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
    @chmod($browserPath, 0755);

    File::put($workspace.'/browsers/manifest.json', json_encode([
        'version' => '123.0.6312.86',
        'channel' => 'Stable',
        'platform' => 'linux64',
        'executablePath' => $browserPath,
    ], JSON_THROW_ON_ERROR));

    $builder = app(StagehandServeCommandBuilder::class);

    try {
        $command = $builder->build([
            'binary' => $binaryPath,
            'working_directory' => $workspace,
            'state_path' => $workspace.'/state',
            'log_path' => $workspace.'/logs/runtime.log',
            'browser' => [
                'install_path' => $workspace.'/browsers',
            ],
        ]);

        expect($command)->toContain('--chromium-path')
            ->toContain($browserPath);
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('passes installed browser bundles to the rod renderer driver', function () {
    config()->set('app.env', 'local');

    $workspace = sys_get_temp_dir().'/canio-serve-builder-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $browserPath = $workspace.'/browsers/chrome-linux64/chrome';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    File::ensureDirectoryExists(dirname($browserPath));
    File::put($browserPath, '#!/bin/sh'.PHP_EOL.'exit 0'.PHP_EOL);
    @chmod($browserPath, 0755);

    File::put($workspace.'/browsers/manifest.json', json_encode([
        'version' => '123.0.6312.86',
        'channel' => 'Stable',
        'platform' => 'linux64',
        'executablePath' => $browserPath,
    ], JSON_THROW_ON_ERROR));

    $builder = app(StagehandServeCommandBuilder::class);

    try {
        $command = $builder->build([
            'binary' => $binaryPath,
            'working_directory' => $workspace,
            'state_path' => $workspace.'/state',
            'log_path' => $workspace.'/logs/runtime.log',
            'browser' => [
                'install_path' => $workspace.'/browsers',
            ],
            'renderer' => [
                'driver' => 'rod-cdp',
            ],
        ]);

        expect($command)->toContain('--renderer-driver')
            ->toContain('rod-cdp')
            ->toContain('--chromium-path')
            ->toContain($browserPath);
    } finally {
        File::deleteDirectory($workspace);
    }
});
