<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CanioRuntimeLogsCommand extends Command
{
    protected $signature = 'canio:runtime:logs {--lines=100 : Number of lines to show}';

    protected $description = 'Read the local Stagehand runtime log file';

    public function handle(): int
    {
        $path = (string) config('canio.runtime.log_path');
        $lines = max(1, (int) $this->option('lines'));

        if ($path === '' || ! File::exists($path)) {
            $this->error('Stagehand log file does not exist yet. Start the runtime first.');

            return self::FAILURE;
        }

        $entries = preg_split("/\r?\n/", (string) File::get($path)) ?: [];
        $tail = array_slice($entries, -$lines);
        $output = trim(implode(PHP_EOL, $tail));

        $this->line($output === '' ? '(log file is empty)' : $output);

        return self::SUCCESS;
    }
}
