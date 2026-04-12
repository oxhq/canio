<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Support\StagehandServeCommandBuilder;

it('applies production-safe defaults when runtime settings are omitted', function () {
    config()->set('app.env', 'production');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));

    $workspace = sys_get_temp_dir().'/canio-serve-builder-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand';
    $statePath = $workspace.'/state';
    $logPath = $workspace.'/logs/runtime.log';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, "#!/bin/sh\nexit 0\n");
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
    $binaryPath = $workspace.'/bin/stagehand';
    $statePath = $workspace.'/state';
    $logPath = $workspace.'/logs/runtime.log';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, "#!/bin/sh\nexit 0\n");
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
