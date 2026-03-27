<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeDeadLettersRequeueCommand extends Command
{
    protected $signature = 'canio:runtime:deadletters:requeue
        {id : Dead-letter id to requeue}
        {--json : Output full JSON payload}';

    protected $description = 'Requeue a Stagehand dead-letter as a fresh async job';

    public function handle(CanioManager $canio): int
    {
        $job = $canio->requeueDeadLetter((string) $this->argument('id'));

        if ($this->option('json')) {
            $this->line((string) json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Requeued %s as job %s (%s).',
            (string) $this->argument('id'),
            $job->id(),
            $job->status(),
        ));

        return self::SUCCESS;
    }
}
