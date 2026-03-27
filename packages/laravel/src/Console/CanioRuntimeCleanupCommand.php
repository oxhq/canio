<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeCleanupCommand extends Command
{
    protected $signature = 'canio:runtime:cleanup
        {--jobs-older-than-days= : Delete completed, failed, or cancelled jobs older than this many days}
        {--artifacts-older-than-days= : Delete artifacts older than this many days}
        {--dead-letters-older-than-days= : Delete dead-letters older than this many days}
        {--json : Output full JSON payload}';

    protected $description = 'Cleanup persisted Stagehand jobs, artifacts, and dead-letters';

    public function handle(CanioManager $canio): int
    {
        $payload = $canio->runtimeCleanup(
            $this->normalizeOption('jobs-older-than-days'),
            $this->normalizeOption('artifacts-older-than-days'),
            $this->normalizeOption('dead-letters-older-than-days'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Group', 'Removed'],
            [
                ['jobs', (string) data_get($payload, 'jobs.count', 0)],
                ['artifacts', (string) data_get($payload, 'artifacts.count', 0)],
                ['deadLetters', (string) data_get($payload, 'deadLetters.count', 0)],
            ],
        );

        return self::SUCCESS;
    }

    private function normalizeOption(string $key): ?int
    {
        $value = $this->option($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
