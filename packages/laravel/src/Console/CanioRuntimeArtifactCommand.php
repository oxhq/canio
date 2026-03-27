<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;

final class CanioRuntimeArtifactCommand extends Command
{
    protected $signature = 'canio:runtime:artifact
        {id : Artifact id to inspect}
        {--json : Output full JSON payload}';

    protected $description = 'Inspect a persisted Stagehand artifact bundle';

    public function handle(CanioManager $canio): int
    {
        $artifact = $canio->artifact((string) $this->argument('id'));

        if ($this->option('json')) {
            $this->line((string) json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['id', (string) data_get($artifact, 'id', '')],
                ['requestId', (string) data_get($artifact, 'requestId', '')],
                ['status', (string) data_get($artifact, 'status', '')],
                ['createdAt', (string) data_get($artifact, 'createdAt', '')],
                ['sourceType', (string) data_get($artifact, 'sourceType', '')],
                ['profile', (string) data_get($artifact, 'profile', '')],
                ['replayOf', (string) data_get($artifact, 'replayOf', '')],
                ['directory', (string) data_get($artifact, 'directory', '')],
                ['fileName', (string) data_get($artifact, 'output.fileName', '')],
                ['bytes', (string) data_get($artifact, 'output.bytes', '0')],
            ],
        );

        $this->line('Directory: '.(string) data_get($artifact, 'directory', ''));

        $files = data_get($artifact, 'files', []);
        if (is_array($files) && $files !== []) {
            $this->newLine();
            $this->table(
                ['File', 'Path'],
                collect($files)
                    ->map(fn (mixed $path, mixed $name): array => [(string) $name, (string) $path])
                    ->values()
                    ->all(),
            );

            foreach ($files as $name => $path) {
                $this->line(sprintf('%s: %s', (string) $name, (string) $path));
            }
        }

        return self::SUCCESS;
    }
}
