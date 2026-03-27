<?php

declare(strict_types=1);

namespace Oxhq\Canio\Contracts;

use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

interface StagehandClient
{
    public function render(RenderSpec $spec): RenderResult;

    public function dispatch(RenderSpec $spec): RenderJob;

    public function job(string $jobId): RenderJob;

    /**
     * @return array<string, mixed>
     */
    public function jobs(int $limit = 20): array;

    /**
     * @return iterable<array{id:string|null,event:string|null,data:array<string, mixed>}>
     */
    public function streamJobEvents(string $jobId, ?int $since = null): iterable;

    public function cancelJob(string $jobId): RenderJob;

    /**
     * @return array<string, mixed>
     */
    public function artifact(string $artifactId): array;

    /**
     * @return array<string, mixed>
     */
    public function artifacts(int $limit = 20): array;

    /**
     * @return array<string, mixed>
     */
    public function deadLetters(): array;

    public function requeueDeadLetter(string $deadLetterId): RenderJob;

    /**
     * @return array<string, mixed>
     */
    public function cleanupDeadLetters(?int $olderThanDays = null): array;

    /**
     * @return array<string, mixed>
     */
    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array;

    public function replay(string $artifactId): RenderResult;

    /**
     * @return array<string, mixed>
     */
    public function status(): array;

    /**
     * @return array<string, mixed>
     */
    public function restart(): array;
}
