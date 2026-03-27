<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeStatusCommand extends Command
{
    protected $signature = 'canio:runtime:status {--json : Output full JSON status payload}';

    protected $description = 'Fetch the current Stagehand runtime status';

    public function handle(CanioManager $canio): int
    {
        $status = $canio->runtimeStatus();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['version', (string) data_get($status, 'version', 'unknown')],
                ['state', (string) data_get($status, 'runtime.state', 'unknown')],
                ['startedAt', (string) data_get($status, 'runtime.startedAt', 'unknown')],
                ['queueDepth', (string) data_get($status, 'queue.depth', '0')],
                ['browserPool', sprintf(
                    'ready %s/%s, busy %s, starting %s, waiting %s',
                    data_get($status, 'browserPool.warm', 0),
                    data_get($status, 'browserPool.size', 0),
                    data_get($status, 'browserPool.busy', 0),
                    data_get($status, 'browserPool.starting', 0),
                    data_get($status, 'browserPool.waiting', 0),
                )],
                ['workerPool', sprintf(
                    'ready %s/%s, busy %s, starting %s, waiting %s',
                    data_get($status, 'workerPool.warm', 0),
                    data_get($status, 'workerPool.size', 0),
                    data_get($status, 'workerPool.busy', 0),
                    data_get($status, 'workerPool.starting', 0),
                    data_get($status, 'workerPool.waiting', 0),
                )],
            ],
        );

        return self::SUCCESS;
    }
}
