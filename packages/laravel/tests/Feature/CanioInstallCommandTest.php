<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

it('skips existing runtime and browser bundle during repeat installs', function () {
    $workspace = sys_get_temp_dir().'/canio-install-command-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');
    $browserPath = $workspace.'/browsers/chrome-linux64/chrome';

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    File::ensureDirectoryExists(dirname($browserPath));
    File::put($browserPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($browserPath, 0755);

    File::put($workspace.'/browsers/manifest.json', json_encode([
        'product' => 'chrome',
        'version' => '123.0.6312.86',
        'channel' => 'Stable',
        'platform' => 'linux64',
        'executablePath' => $browserPath,
    ], JSON_THROW_ON_ERROR));

    config()->set('canio.runtime.mode', 'remote');
    config()->set('canio.runtime.binary', 'stagehand');
    config()->set('canio.runtime.install_path', $binaryPath);
    config()->set('canio.runtime.working_directory', $workspace);
    config()->set('canio.runtime.browser.install_path', $workspace.'/browsers');
    config()->set('canio.runtime.renderer.driver', 'rod-cdp');

    app()->instance(StagehandClient::class, new class implements StagehandClient
    {
        public function render(RenderSpec $spec): RenderResult
        {
            throw new RuntimeException('not used');
        }

        public function dispatch(RenderSpec $spec): RenderJob
        {
            throw new RuntimeException('not used');
        }

        public function job(string $jobId): RenderJob
        {
            throw new RuntimeException('not used');
        }

        public function jobs(int $limit = 20): array
        {
            throw new RuntimeException('not used');
        }

        public function streamJobEvents(string $jobId, ?int $since = null): iterable
        {
            throw new RuntimeException('not used');
        }

        public function cancelJob(string $jobId): RenderJob
        {
            throw new RuntimeException('not used');
        }

        public function artifact(string $artifactId): array
        {
            throw new RuntimeException('not used');
        }

        public function artifacts(int $limit = 20): array
        {
            throw new RuntimeException('not used');
        }

        public function deadLetters(): array
        {
            throw new RuntimeException('not used');
        }

        public function requeueDeadLetter(string $deadLetterId): RenderJob
        {
            throw new RuntimeException('not used');
        }

        public function cleanupDeadLetters(?int $olderThanDays = null): array
        {
            throw new RuntimeException('not used');
        }

        public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
        {
            throw new RuntimeException('not used');
        }

        public function replay(string $artifactId): RenderResult
        {
            throw new RuntimeException('not used');
        }

        public function status(): array
        {
            return [
                'version' => 'test',
                'runtime' => [
                    'state' => 'ready',
                ],
            ];
        }

        public function restart(): array
        {
            throw new RuntimeException('not used');
        }
    });
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);

    Http::fake();

    try {
        $this->artisan('canio:install')
            ->expectsOutput('Stagehand binary already installed: '.$binaryPath)
            ->expectsOutput('Browser bundle already installed: '.$browserPath)
            ->expectsOutput('Canio install completed.')
            ->assertSuccessful();

        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($workspace);
    }
});
