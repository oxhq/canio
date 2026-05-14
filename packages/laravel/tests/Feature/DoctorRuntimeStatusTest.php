<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

it('fails when embedded auto start cannot reach Stagehand status with redis backend', function () {
    $workspace = sys_get_temp_dir().'/canio-doctor-runtime-'.bin2hex(random_bytes(6));
    $binaryPath = $workspace.'/bin/stagehand'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : '');

    File::ensureDirectoryExists(dirname($binaryPath));
    File::put($binaryPath, PHP_OS_FAMILY === 'Windows' ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    @chmod($binaryPath, 0755);

    config()->set('canio.runtime.mode', 'embedded');
    config()->set('canio.runtime.auto_start', true);
    config()->set('canio.runtime.binary', $binaryPath);
    config()->set('canio.runtime.install_path', '');
    config()->set('canio.runtime.browser.install_path', $workspace.'/browsers');
    config()->set('canio.runtime.jobs.backend', 'redis');

    app()->instance(StagehandClient::class, new DoctorUnavailableStagehandClient(
        'Canio started Stagehand in embedded mode, but it did not become healthy at http://127.0.0.1:9514 within 3 seconds. Check storage/logs/canio-runtime.log.',
    ));
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);

    try {
        $this->artisan('canio:doctor')
            ->expectsOutput('Runtime mode: embedded')
            ->expectsOutput('Stagehand binary: '.$binaryPath)
            ->expectsOutput('Renderer driver: rod-cdp')
            ->expectsOutput('Embedded Stagehand could not be reached after the auto-start readiness check.')
            ->expectsOutputToContain('Canio started Stagehand in embedded mode, but it did not become healthy')
            ->expectsOutput('Runtime job backend is redis. Verify Redis is reachable or set CANIO_RUNTIME_JOB_BACKEND=memory for non-production local checks.')
            ->assertExitCode(1);
    } finally {
        File::deleteDirectory($workspace);
    }
});

final class DoctorUnavailableStagehandClient implements StagehandClient
{
    public function __construct(private readonly string $message) {}

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
        throw new RuntimeException($this->message);
    }

    public function restart(): array
    {
        throw new RuntimeException('not used');
    }
}
