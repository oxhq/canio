<?php

declare(strict_types=1);

namespace Oxhq\Canio\Console;

use Illuminate\Console\Command;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Support\StagehandBinaryResolver;
use RuntimeException;

final class CanioDoctorCommand extends Command
{
    protected $signature = 'canio:doctor';

    protected $description = 'Run basic runtime, binary, and configuration health checks for Canio';

    public function handle(StagehandBinaryResolver $resolver, CanioManager $canio): int
    {
        $runtime = (array) config('canio.runtime', []);
        $workingDirectory = (string) ($runtime['working_directory'] ?? base_path());
        $binaryOkay = false;
        $mode = strtolower(trim((string) ($runtime['mode'] ?? 'embedded')));

        $this->info(sprintf('Runtime mode: %s', $mode));

        try {
            $binary = $resolver->resolve($runtime, $workingDirectory);
            $binaryOkay = true;
            $this->info(sprintf('Stagehand binary: %s', $binary));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        $chromiumPath = trim((string) data_get($runtime, 'chromium.path', ''));

        if ($chromiumPath === '') {
            $this->warn('Chromium path is not configured. Stagehand will try Chrome/Chromium auto-detection when it starts.');
        } else {
            $this->info(sprintf('Chromium path: %s', $chromiumPath));
        }

        try {
            $status = $canio->runtimeStatus();
            $this->info(sprintf(
                'Stagehand status: %s (%s)',
                (string) data_get($status, 'runtime.state', 'unknown'),
                (string) data_get($status, 'version', 'unknown'),
            ));
        } catch (RuntimeException $exception) {
            if ($mode === 'embedded' && (bool) ($runtime['auto_start'] ?? true)) {
                $this->warn('Embedded Stagehand is not running yet. It will be started automatically on first use.');
            } else {
                $this->warn($exception->getMessage());
            }
        }

        return $binaryOkay ? self::SUCCESS : self::FAILURE;
    }
}
