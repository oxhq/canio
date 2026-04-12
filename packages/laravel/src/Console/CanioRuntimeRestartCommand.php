<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;
use RuntimeException;

final class CanioRuntimeRestartCommand extends Command
{
    protected $signature = 'canio:runtime:restart';

    protected $description = 'Ask the Stagehand runtime to reset its in-memory state';

    public function handle(CanioManager $canio): int
    {
        try {
            $status = $canio->runtimeRestart();
        } catch (RuntimeException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Stagehand restarted. Current state: %s',
            (string) data_get($status, 'runtime.state', 'unknown'),
        ));

        return self::SUCCESS;
    }
}
