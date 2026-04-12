<?php

declare(strict_types=1);

use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

it('fails explicitly when managed runtime restart is unavailable', function () {
    config()->set('canio.cloud.mode', 'managed');

    app()->instance(StagehandClient::class, new RestartUnavailableStagehandClient);
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);

    $this->artisan('canio:runtime:restart')
        ->expectsOutputToContain('Canio Cloud managed runtime restart is unavailable from the Laravel package.')
        ->assertExitCode(1);
});

final class RestartUnavailableStagehandClient implements StagehandClient
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
        throw new RuntimeException('not used');
    }

    public function restart(): array
    {
        throw new RuntimeException('Canio Cloud managed runtime restart is unavailable from the Laravel package.');
    }
}
