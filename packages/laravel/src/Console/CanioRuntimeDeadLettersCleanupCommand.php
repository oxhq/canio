<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeDeadLettersCleanupCommand extends Command
{
    protected $signature = 'canio:runtime:deadletters:cleanup
        {--older-than-days= : Delete dead-letters older than this many days}
        {--json : Output full JSON payload}';

    protected $description = 'Delete old Stagehand dead-letters from the local runtime state';

    public function handle(CanioManager $canio): int
    {
        $olderThanDays = $this->option('older-than-days');
        $payload = $canio->cleanupDeadLetters(
            $olderThanDays !== null && $olderThanDays !== ''
                ? (int) $olderThanDays
                : null,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $removed = is_array($payload['removed'] ?? null) ? $payload['removed'] : [];

        if ($removed === []) {
            $this->info('No dead-letters matched the cleanup window.');

            return self::SUCCESS;
        }

        $rows = array_map(static function (array $item): array {
            return [
                (string) ($item['id'] ?? ''),
                (string) ($item['jobId'] ?? ''),
                (string) ($item['failedAt'] ?? ''),
            ];
        }, array_values(array_filter($removed, 'is_array')));

        $this->table(['DeadLetter', 'Job', 'FailedAt'], $rows);
        $this->info(sprintf('Removed %d dead-letter(s).', count($rows)));

        return self::SUCCESS;
    }
}
