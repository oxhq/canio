<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\Support\StagehandServeCommandBuilder;
use Symfony\Component\Process\Process;

final class CanioServeCommand extends Command
{
    protected $signature = 'canio:serve
        {--host= : Override runtime host}
        {--port= : Override runtime port}';

    protected $description = 'Start the Stagehand runtime daemon manually for advanced or remote setups';

    public function handle(StagehandServeCommandBuilder $builder): int
    {
        $runtime = (array) config('canio.runtime', []);
        $host = (string) ($this->option('host') ?: ($runtime['host'] ?? '127.0.0.1'));
        $port = (int) ($this->option('port') ?: ($runtime['port'] ?? 9514));
        $workingDirectory = (string) ($runtime['working_directory'] ?? base_path());
        $command = $builder->build($runtime, $host, $port);

        $process = new Process($command, $workingDirectory);

        $process->setTimeout(null);

        $this->line(sprintf('Starting Stagehand on %s:%s', $host, $port));

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
