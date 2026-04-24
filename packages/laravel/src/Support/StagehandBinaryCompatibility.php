<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class StagehandBinaryCompatibility
{
    /**
     * @var list<string>
     */
    private const REQUIRED_SERVE_FLAGS = [
        'allow-private-targets',
        'request-body-limit-bytes',
    ];

    public function assertCompatible(string $binary): void
    {
        $process = new Process([$binary, 'serve', '--help']);
        $process->setTimeout(10);
        $process->run();

        $this->assertCompatibleHelp(
            $process->getOutput().PHP_EOL.$process->getErrorOutput(),
            $binary,
        );
    }

    public function assertCompatibleHelp(string $help, string $binary): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_SERVE_FLAGS,
            static fn (string $flag): bool => ! str_contains($help, '-'.$flag),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Stagehand binary %s is incompatible with this Canio package. Missing serve flags: %s.',
            $binary,
            implode(', ', array_map(static fn (string $flag): string => '--'.$flag, $missing)),
        ));
    }
}
