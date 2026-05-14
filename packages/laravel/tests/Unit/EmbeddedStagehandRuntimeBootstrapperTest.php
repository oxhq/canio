<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\Support\EmbeddedStagehandRuntimeBootstrapper;
use Oxhq\Canio\Support\StagehandBinaryResolver;
use Oxhq\Canio\Support\StagehandHealthProbe;
use Oxhq\Canio\Support\StagehandProcessLauncher;
use Oxhq\Canio\Support\StagehandReleaseInstaller;
use Oxhq\Canio\Support\StagehandServeCommandBuilder;

it('cleans stale chromium singleton locks from legacy and stagehand rod profile layouts only', function () {
    $workspace = sys_get_temp_dir().'/canio-embedded-bootstrapper-'.bin2hex(random_bytes(6));
    $userDataDir = $workspace.'/chromium-profile';

    $legacyLock = $userDataDir.'/browser-legacy/SingletonLock';
    $legacyCookie = $userDataDir.'/browser-legacy/Cookies';
    $stagehandLock = $userDataDir.'/stagehand-123/browser-current/SingletonCookie';
    $stagehandLocalState = $userDataDir.'/stagehand-123/browser-current/Local State';
    $unrelatedStagehandLock = $userDataDir.'/stagehand-123/not-browser/SingletonLock';
    $unrelatedRootLock = $userDataDir.'/SingletonLock';

    foreach ([
        $legacyLock,
        $legacyCookie,
        $stagehandLock,
        $stagehandLocalState,
        $unrelatedStagehandLock,
        $unrelatedRootLock,
    ] as $path) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'sentinel');
    }

    $bootstrapper = new EmbeddedStagehandRuntimeBootstrapper(
        config: [
            'state_path' => $workspace.'/runtime',
            'chromium' => [
                'user_data_dir' => $userDataDir,
            ],
        ],
        resolver: new StagehandBinaryResolver,
        installer: new StagehandReleaseInstaller,
        commandBuilder: new StagehandServeCommandBuilder(new StagehandBinaryResolver),
        launcher: new StagehandProcessLauncher,
        healthProbe: new StagehandHealthProbe,
    );

    try {
        $method = new ReflectionMethod($bootstrapper, 'cleanupStaleChromiumLocks');
        $method->setAccessible(true);
        $method->invoke($bootstrapper);

        expect(File::exists($legacyLock))->toBeFalse()
            ->and(File::exists($stagehandLock))->toBeFalse()
            ->and(File::exists($legacyCookie))->toBeTrue()
            ->and(File::exists($stagehandLocalState))->toBeTrue()
            ->and(File::exists($unrelatedStagehandLock))->toBeTrue()
            ->and(File::exists($unrelatedRootLock))->toBeTrue();
    } finally {
        File::deleteDirectory($workspace);
    }
});
