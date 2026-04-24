<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Support\StagehandBinaryResolver;

it('resolves binaries from the configured install path', function () {
    $workspace = sys_get_temp_dir().'/canio-binary-resolver-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/runtime/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    $resolver = new StagehandBinaryResolver;

    try {
        $resolved = str_replace('\\', '/', $resolver->resolve([
            'binary' => 'stagehand',
            'install_path' => 'runtime/bin/stagehand',
        ], $workspace));

        $expected = str_replace('\\', '/', $binaryPath);

        expect(PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved)
            ->toBe(PHP_OS_FAMILY === 'Windows' ? strtolower($expected) : $expected);
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('resolves absolute drive-letter paths on windows', function () {
    $resolver = new StagehandBinaryResolver;
    $reflection = new ReflectionMethod($resolver, 'normalizePath');

    expect($reflection->invoke($resolver, 'C:/canio/bin/stagehand.exe', 'D:/app'))
        ->toBe('C:/canio/bin/stagehand.exe');
});
