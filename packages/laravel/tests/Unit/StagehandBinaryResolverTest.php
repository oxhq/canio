<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Support\StagehandBinaryResolver;

it('resolves binaries from the configured install path', function () {
    $workspace = sys_get_temp_dir().'/canio-binary-resolver-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/runtime/bin/stagehand';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    $resolver = new StagehandBinaryResolver;

    try {
        expect($resolver->resolve([
            'binary' => 'stagehand',
            'install_path' => 'runtime/bin/stagehand',
        ], $workspace))->toBe($binaryPath);
    } finally {
        File::deleteDirectory($workspace);
    }
});
