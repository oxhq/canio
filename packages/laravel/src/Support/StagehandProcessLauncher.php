<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class StagehandProcessLauncher
{
    /**
     * @param  list<string>  $command
     */
    public function start(array $command, string $workingDirectory): void
    {
        $process = Process::fromShellCommandline(
            $this->detachedCommand($command, $workingDirectory),
            $workingDirectory,
        );
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Unable to launch Stagehand in embedded mode: %s',
                trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput()),
            ));
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function detachedCommand(array $command, string $workingDirectory): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return sprintf(
                'cd /d %s && start "" /B %s',
                $this->escapeForWindows($workingDirectory),
                implode(' ', array_map([$this, 'escapeForWindows'], $command)),
            );
        }

        return sprintf(
            'cd %s && nohup %s >/dev/null 2>&1 &',
            escapeshellarg($workingDirectory),
            implode(' ', array_map('escapeshellarg', $command)),
        );
    }

    private function escapeForWindows(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
