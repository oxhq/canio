<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeCancelCommand extends Command
{
    protected $signature = 'canio:runtime:cancel
        {id : Job id to cancel}
        {--json : Output full JSON payload}';

    protected $description = 'Cancel a queued or running Stagehand job';

    public function handle(CanioManager $canio): int
    {
        $job = $canio->cancelJob((string) $this->argument('id'));

        if ($this->option('json')) {
            $this->line((string) json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Cancelled job %s (%s).', $job->id(), $job->status()));

        return self::SUCCESS;
    }
}
