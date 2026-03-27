<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeRetryCommand extends Command
{
    protected $signature = 'canio:runtime:retry
        {id : Failed job id to retry from its dead-letter snapshot}
        {--json : Output full JSON payload}';

    protected $description = 'Retry a failed Stagehand job by requeueing its dead-letter snapshot';

    public function handle(CanioManager $canio): int
    {
        $job = $canio->retryJob((string) $this->argument('id'));

        if ($this->option('json')) {
            $this->line((string) json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Retried job as %s (%s).',
            $job->id(),
            $job->status(),
        ));

        return self::SUCCESS;
    }
}
