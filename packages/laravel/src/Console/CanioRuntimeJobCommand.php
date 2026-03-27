<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Data\RenderJob;

final class CanioRuntimeJobCommand extends Command
{
    protected $signature = 'canio:runtime:job
        {id : Job id to inspect}
        {--watch : Stream job lifecycle events until the server closes the stream}
        {--since= : Resume the event stream from this sequence id}
        {--json : Output full JSON payload}';

    protected $description = 'Inspect a Stagehand job and optionally follow its event stream';

    public function handle(CanioManager $canio): int
    {
        if ((bool) $this->option('watch') && (bool) $this->option('json')) {
            $this->error('Use either --watch or --json, not both together.');

            return self::INVALID;
        }

        $jobId = (string) $this->argument('id');
        $job = $canio->job($jobId);

        if ((bool) $this->option('watch')) {
            return $this->watch($canio, $job);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderJob($job);

        return self::SUCCESS;
    }

    private function watch(CanioManager $canio, RenderJob $job): int
    {
        $this->renderJob($job);

        if ($job->terminal() && $this->option('since') === null) {
            $this->newLine();
            $this->line('Job is already in a terminal state; nothing live to stream.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Streaming Stagehand events...');

        $latest = $job;
        $since = $this->option('since');
        $since = $since === null || $since === '' ? null : (int) $since;

        foreach ($canio->streamJobEvents($job->id(), $since) as $frame) {
            $payload = $frame['data'] ?? [];
            $event = (string) ($frame['event'] ?? ($payload['kind'] ?? 'job.event'));
            $eventJob = is_array($payload['job'] ?? null) ? RenderJob::fromArray($payload['job']) : $latest;
            $latest = $eventJob;

            $parts = [
                sprintf('[%s]', (string) ($payload['emittedAt'] ?? now('UTC')->toIso8601String())),
                $event,
                'status='.$eventJob->status(),
                'attempts='.$eventJob->attempts(),
            ];

            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($reason !== '') {
                $parts[] = 'reason='.$reason;
            }

            $retryAt = trim((string) ($payload['retryAt'] ?? ''));
            if ($retryAt !== '') {
                $parts[] = 'retryAt='.$retryAt;
            }

            $this->line(implode(' ', $parts));
        }

        $this->newLine();
        $this->renderJob($latest);

        return self::SUCCESS;
    }

    private function renderJob(RenderJob $job): void
    {
        $this->table(
            ['Field', 'Value'],
            [
                ['id', $job->id()],
                ['requestId', $job->requestId()],
                ['status', $job->status()],
                ['attempts', (string) $job->attempts()],
                ['maxRetries', (string) $job->maxRetries()],
                ['submittedAt', (string) data_get($job->toArray(), 'submittedAt', '')],
                ['startedAt', (string) data_get($job->toArray(), 'startedAt', '')],
                ['completedAt', (string) data_get($job->toArray(), 'completedAt', '')],
                ['nextRetryAt', (string) ($job->nextRetryAt() ?? '')],
                ['error', (string) ($job->error() ?? '')],
                ['deadLetterId', (string) ($job->deadLetterId() ?? '')],
                ['artifactId', (string) ($job->artifactId() ?? '')],
            ],
        );
    }
}
