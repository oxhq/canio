<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeDeadLettersCommand extends Command
{
    protected $signature = 'canio:runtime:deadletters {--json : Output full JSON payload}';

    protected $description = 'List Stagehand dead-letters available for inspection or requeue';

    public function handle(CanioManager $canio): int
    {
        $payload = $canio->deadLetters();

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        if ($items === []) {
            $this->info('No dead-letters found.');

            return self::SUCCESS;
        }

        $rows = array_map(static function (array $item): array {
            return [
                (string) ($item['id'] ?? ''),
                (string) ($item['jobId'] ?? ''),
                (string) ($item['requestId'] ?? ''),
                (string) ($item['attempts'] ?? 0),
                (string) ($item['failedAt'] ?? ''),
                (string) ($item['error'] ?? ''),
            ];
        }, array_values(array_filter($items, 'is_array')));

        $this->table(
            ['DeadLetter', 'Job', 'Request', 'Attempts', 'FailedAt', 'Error'],
            $rows,
        );

        return self::SUCCESS;
    }
}
