#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;
use Oxhq\Canio\Facades\Canio;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");

    exit(1);
}

$options = getopt('', ['app::', 'json', 'cold-runs::', 'warm-runs::', 'top::']);
$root = realpath(__DIR__.'/..');
$fixture = require __DIR__.'/example_invoice_reference.php';
$appPath = realpath((string) ($options['app'] ?? ($root !== false ? $root.'/examples/laravel-app/app' : '')));
$asJson = array_key_exists('json', $options);
$tuningSettings = resolveTuningSettings($fixture, $options);

if ($root === false || $appPath === false) {
    fwrite(STDERR, "Unable to resolve the Canio repository root or example app path.\n");

    exit(1);
}

$autoloadPath = $appPath.'/vendor/autoload.php';
$bootstrapPath = $appPath.'/bootstrap/app.php';

if (! is_file($autoloadPath) || ! is_file($bootstrapPath)) {
    fwrite(STDERR, "Example Laravel app is missing vendor/autoload.php or bootstrap/app.php. Run ./examples/laravel-app/create-project.sh first.\n");

    exit(1);
}

configureBaseEnvironment($root, $appPath);

require $autoloadPath;

/** @var \Illuminate\Foundation\Application $app */
$app = require $bootstrapPath;
$app->make(Kernel::class)->bootstrap();

$report = buildTuningReport($fixture, $root, $appPath, buildCandidates($fixture), $tuningSettings);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    renderTuningReport($report);
}

exit(($report['recommended']['ok'] ?? false) ? 0 : 1);

function configureBaseEnvironment(string $root, string $appPath): void
{
    $stagehandBinary = realpath($root.'/bin/stagehand');

    if ($stagehandBinary === false) {
        fwrite(STDERR, "Unable to resolve bin/stagehand. Run ./scripts/build-stagehand.sh first.\n");

        exit(1);
    }

    putenv('APP_ENV=local');
    putenv('APP_DEBUG=true');
    putenv('APP_URL=http://localhost');
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');
    putenv('BROADCAST_CONNECTION=null');
    putenv('MAIL_MAILER=array');
    putenv('CANIO_RUNTIME_MODE=embedded');
    putenv(sprintf('CANIO_RUNTIME_BINARY=%s', $stagehandBinary));
    putenv('CANIO_RUNTIME_AUTO_INSTALL=false');
    putenv('CANIO_RUNTIME_AUTO_START=true');
    putenv('CANIO_RUNTIME_REQUEST_LOGGING=false');
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-tune-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv('CANIO_RUNTIME_JOB_BACKEND=memory');
    putenv('CANIO_RUNTIME_JOB_WORKERS=1');
    putenv('CANIO_RUNTIME_JOB_QUEUE_DEPTH=8');
    putenv(sprintf('CANIO_PROFILES_PATH=%s', $root.'/resources/profiles'));
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function resolveTuningSettings(array $fixture, array $options): array
{
    $coldRuns = resolvePositiveIntegerOption(
        $options['cold-runs'] ?? null,
        (int) arrayPathGet($fixture, 'tuning.runs.cold', 1),
    );
    $warmRuns = resolvePositiveIntegerOption(
        $options['warm-runs'] ?? null,
        (int) arrayPathGet($fixture, 'tuning.runs.warm', 3),
    );
    $top = resolvePositiveIntegerOption(
        $options['top'] ?? null,
        (int) arrayPathGet($fixture, 'tuning.report.top', 5),
    );
    $coldWeight = (float) arrayPathGet($fixture, 'tuning.score_weights.cold', 0.35);
    $warmWeight = (float) arrayPathGet($fixture, 'tuning.score_weights.warm', 0.65);
    $weightSum = max(0.0001, $coldWeight + $warmWeight);

    return [
        'coldRuns' => $coldRuns,
        'warmRuns' => $warmRuns,
        'top' => $top,
        'scoreWeights' => [
            'cold' => $coldWeight / $weightSum,
            'warm' => $warmWeight / $weightSum,
        ],
    ];
}

function arrayPathGet(array $source, string $path, mixed $default = null): mixed
{
    $segments = explode('.', $path);
    $current = $source;

    foreach ($segments as $segment) {
        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return $default;
        }

        $current = $current[$segment];
    }

    return $current;
}

function resolvePositiveIntegerOption(mixed $value, int $default): int
{
    if (! is_numeric($value) || (int) $value <= 0) {
        return max(1, $default);
    }

    return (int) $value;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<int, array<string, int|string>>
 */
function buildCandidates(array $fixture): array
{
    $pollIntervals = normalizeNumericOptions((array) data_get($fixture, 'tuning.poll_interval_ms', [25, 50, 75]));
    $settleFrames = normalizeNumericOptions((array) data_get($fixture, 'tuning.settle_frames', [1, 2, 3]));
    $poolWarmValues = normalizeNumericOptions((array) data_get($fixture, 'tuning.pool_warm', [0, 1]));

    $candidates = [];

    foreach ($poolWarmValues as $poolWarm) {
        foreach ($pollIntervals as $pollInterval) {
            foreach ($settleFrames as $frames) {
                $candidates[] = [
                    'name' => sprintf('warm%d-poll%d-frames%d', $poolWarm, $pollInterval, $frames),
                    'poolWarm' => $poolWarm,
                    'pollIntervalMs' => $pollInterval,
                    'settleFrames' => $frames,
                ];
            }
        }
    }

    return $candidates;
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function normalizeNumericOptions(array $values): array
{
    $normalized = [];

    foreach ($values as $value) {
        if (! is_numeric($value)) {
            continue;
        }

        $normalized[] = (int) $value;
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized);

    return $normalized;
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<int, array<string, int|string>>  $candidates
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function buildTuningReport(array $fixture, string $root, string $appPath, array $candidates, array $settings): array
{
    $results = [];

    foreach ($candidates as $candidate) {
        $results[] = evaluateCandidate($fixture, $root, $appPath, $candidate, $settings);
    }

    $valid = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === true));
    $valid = annotateBalanceScores($valid, $settings);
    $recommendations = buildRecommendations($valid, $settings);
    $frontier = paretoFrontier($valid);

    usort($valid, static function (array $left, array $right): int {
        return (($left['score'] ?? INF) <=> ($right['score'] ?? INF))
            ?: ($left['warm']['engineMs'] <=> $right['warm']['engineMs'])
            ?: ($left['cold']['engineMs'] <=> $right['cold']['engineMs']);
    });

    return [
        'fixture' => (string) $fixture['name'],
        'settings' => $settings,
        'candidateCount' => count($results),
        'validCount' => count($valid),
        'recommended' => $recommendations['balanced'] ?? ['ok' => false],
        'recommendations' => $recommendations,
        'insights' => buildInsights($recommendations),
        'paretoFrontier' => $frontier,
        'topCandidates' => array_slice($valid, 0, (int) $settings['top']),
        'results' => $results,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $valid
 * @param  array<string, mixed>  $settings
 * @return array<int, array<string, mixed>>
 */
function annotateBalanceScores(array $valid, array $settings): array
{
    if ($valid === []) {
        return [];
    }

    $minCold = min(array_map(static fn (array $candidate): float => (float) $candidate['cold']['engineMs'], $valid));
    $minWarm = min(array_map(static fn (array $candidate): float => (float) $candidate['warm']['engineMs'], $valid));

    return array_map(static function (array $candidate) use ($minCold, $minWarm, $settings): array {
        $coldRatio = (float) $candidate['cold']['engineMs'] / max(1.0, $minCold);
        $warmRatio = (float) $candidate['warm']['engineMs'] / max(1.0, $minWarm);
        $balanceScore = ($coldRatio * (float) data_get($settings, 'scoreWeights.cold', 0.35))
            + ($warmRatio * (float) data_get($settings, 'scoreWeights.warm', 0.65));
        $candidate['balanceScore'] = round($balanceScore, 4);

        return $candidate;
    }, $valid);
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, int|string>  $candidate
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function evaluateCandidate(array $fixture, string $root, string $appPath, array $candidate, array $settings): array
{
    $coldSamples = [];
    $warmSamples = [];
    $errors = [];

    for ($coldRun = 1; $coldRun <= (int) $settings['coldRuns']; $coldRun++) {
        $port = findAvailablePort();
        $statePath = sprintf('%s/storage/app/canio/runtime-tune-%d', $appPath, $port);
        $userDataDir = $statePath.'/chromium-profile';
        $baseUrl = sprintf('http://127.0.0.1:%d', $port);

        deleteDirectory($statePath);
        applyCandidateConfig($root, $appPath, $candidate, $baseUrl, $port, $statePath, $userDataDir);
        resetCanioContainer();

        try {
            $coldReport = runColdDebugRender($fixture);
            $coldReport['run'] = $coldRun;
            $coldSamples[] = $coldReport;

            for ($warmRun = 1; $warmRun <= (int) $settings['warmRuns']; $warmRun++) {
                $warmReport = runWarmPlainRender($fixture);
                $warmReport['coldRun'] = $coldRun;
                $warmReport['warmRun'] = $warmRun;
                $warmSamples[] = $warmReport;
            }
        } catch (\Throwable $exception) {
            $errors[] = sprintf('cold_run_%d: %s', $coldRun, $exception->getMessage());
        } finally {
            terminateRuntimeOnPort($port);
            deleteDirectory($statePath);
        }
    }

    $coldSummary = summarizeRunGroup($coldSamples, (int) $settings['coldRuns']);
    $warmSummary = summarizeRunGroup($warmSamples, (int) $settings['coldRuns'] * (int) $settings['warmRuns']);
    $score = computeCandidateScore($coldSummary, $warmSummary, $settings);
    $ok = $errors === []
        && ($coldSummary['ok'] ?? false) === true
        && ($warmSummary['ok'] ?? false) === true;

    if (! $ok) {
        return [
            'name' => (string) $candidate['name'],
            'candidate' => $candidate,
            'ok' => false,
            'cold' => $coldSummary,
            'warm' => $warmSummary,
            'coldSamples' => $coldSamples,
            'warmSamples' => $warmSamples,
            'error' => $errors === []
                ? 'Candidate did not preserve fidelity across every run.'
                : implode('; ', $errors),
        ];
    }

    return [
        'name' => (string) $candidate['name'],
        'candidate' => $candidate,
        'ok' => $ok,
        'score' => round($score, 4),
        'cold' => $coldSummary,
        'warm' => $warmSummary,
        'coldSamples' => $coldSamples,
        'warmSamples' => $warmSamples,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $samples
 * @return array<string, mixed>
 */
function summarizeRunGroup(array $samples, int $expectedRuns): array
{
    $engineValues = [];
    $wallValues = [];
    $ok = count($samples) === $expectedRuns;

    foreach ($samples as $sample) {
        $engineValues[] = (float) ($sample['engineMs'] ?? 0.0);
        $wallValues[] = (float) ($sample['wallMs'] ?? 0.0);

        if (($sample['ok'] ?? false) !== true) {
            $ok = false;
        }
    }

    return [
        'ok' => $ok,
        'runCount' => count($samples),
        'expectedRuns' => $expectedRuns,
        'wallMs' => round(median($wallValues), 2),
        'engineMs' => round(median($engineValues), 2),
        'bestEngineMs' => $engineValues === [] ? 0.0 : round(min($engineValues), 2),
        'worstEngineMs' => $engineValues === [] ? 0.0 : round(max($engineValues), 2),
        'timings' => summarizeTimings($samples),
        'checks' => summarizeChecks($samples),
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $samples
 * @return array<string, mixed>
 */
function summarizeTimings(array $samples): array
{
    $actionablePhases = [
        'acquireMs',
        'startupMs',
        'prepareMs',
        'waitMs',
        'printMs',
        'debugArtifactsMs',
        'artifactSaveMs',
    ];
    $perMetric = [];

    foreach ($samples as $sample) {
        foreach ((array) ($sample['timings'] ?? []) as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $perMetric[$key][] = (float) $value;
        }
    }

    $median = [];
    $min = [];
    $max = [];

    foreach ($perMetric as $key => $values) {
        $median[$key] = round(median($values), 2);
        $min[$key] = round(min($values), 2);
        $max[$key] = round(max($values), 2);
    }

    $dominantPhase = '';
    $dominantValue = 0.0;

    foreach ($actionablePhases as $key) {
        $value = (float) ($median[$key] ?? 0.0);

        if ($value > $dominantValue) {
            $dominantPhase = (string) $key;
            $dominantValue = $value;
        }
    }

    $totalMs = (float) ($median['totalMs'] ?? 0.0);

    return [
        'median' => $median,
        'min' => $min,
        'max' => $max,
        'dominantPhase' => $dominantPhase,
        'dominantShare' => $totalMs > 0 ? round($dominantValue / $totalMs, 4) : 0.0,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $samples
 * @return array<string, mixed>
 */
function summarizeChecks(array $samples): array
{
    $summary = [
        'failedRuns' => [],
    ];
    $pageScreenshotSimilarities = [];
    $pageScreenshotChangedRatios = [];
    $pdfRasterSimilarities = [];
    $pdfRasterChangedRatios = [];
    $pdfByteValues = [];

    foreach ($samples as $index => $sample) {
        if (($sample['ok'] ?? false) !== true) {
            $summary['failedRuns'][] = $index + 1;
        }

        $pageScreenshot = (array) data_get($sample, 'checks.pageScreenshot', []);
        $pdfRaster = (array) data_get($sample, 'checks.pdfRaster', []);
        $pdfBytes = (array) data_get($sample, 'checks.pdfBytes', []);

        if (is_numeric($pageScreenshot['similarity'] ?? null)) {
            $pageScreenshotSimilarities[] = (float) $pageScreenshot['similarity'];
        }

        if (is_numeric($pageScreenshot['changedPixelRatio'] ?? null)) {
            $pageScreenshotChangedRatios[] = (float) $pageScreenshot['changedPixelRatio'];
        }

        if (is_numeric($pdfRaster['similarity'] ?? null)) {
            $pdfRasterSimilarities[] = (float) $pdfRaster['similarity'];
        }

        if (is_numeric($pdfRaster['changedPixelRatio'] ?? null)) {
            $pdfRasterChangedRatios[] = (float) $pdfRaster['changedPixelRatio'];
        }

        if (is_numeric($pdfBytes['actual'] ?? null)) {
            $pdfByteValues[] = (float) $pdfBytes['actual'];
        }
    }

    if ($pageScreenshotSimilarities !== []) {
        $summary['pageScreenshot'] = [
            'minSimilarity' => round(min($pageScreenshotSimilarities), 6),
            'maxChangedRatio' => round(max($pageScreenshotChangedRatios ?: [0.0]), 6),
        ];
    }

    if ($pdfRasterSimilarities !== []) {
        $summary['pdfRaster'] = [
            'minSimilarity' => round(min($pdfRasterSimilarities), 6),
            'maxChangedRatio' => round(max($pdfRasterChangedRatios ?: [0.0]), 6),
        ];
    }

    if ($pdfByteValues !== []) {
        $summary['pdfBytes'] = [
            'min' => (int) round(min($pdfByteValues)),
            'max' => (int) round(max($pdfByteValues)),
            'median' => (int) round(median($pdfByteValues)),
        ];
    }

    return $summary;
}

/**
 * @param  array<string, mixed>  $coldSummary
 * @param  array<string, mixed>  $warmSummary
 * @param  array<string, mixed>  $settings
 */
function computeCandidateScore(array $coldSummary, array $warmSummary, array $settings): float
{
    $coldMs = max(1.0, (float) ($coldSummary['engineMs'] ?? 0.0));
    $warmMs = max(1.0, (float) ($warmSummary['engineMs'] ?? 0.0));

    return ($coldMs * (float) data_get($settings, 'scoreWeights.cold', 0.35))
        + ($warmMs * (float) data_get($settings, 'scoreWeights.warm', 0.65));
}

/**
 * @param  array<int, array<string, mixed>>  $valid
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function buildRecommendations(array $valid, array $settings): array
{
    if ($valid === []) {
        return [
            'bestCold' => ['ok' => false],
            'bestWarm' => ['ok' => false],
            'balanced' => ['ok' => false],
        ];
    }

    $bestCold = sortCandidates($valid, static fn (array $left, array $right): int => ($left['cold']['engineMs'] <=> $right['cold']['engineMs'])
        ?: ($left['warm']['engineMs'] <=> $right['warm']['engineMs']));
    $bestWarm = sortCandidates($valid, static fn (array $left, array $right): int => ($left['warm']['engineMs'] <=> $right['warm']['engineMs'])
        ?: ($left['cold']['engineMs'] <=> $right['cold']['engineMs']));

    $frontier = paretoFrontier($valid);

    usort($frontier, static function (array $left, array $right): int {
        return (($left['balanceScore'] ?? INF) <=> ($right['balanceScore'] ?? INF))
            ?: ($left['warm']['engineMs'] <=> $right['warm']['engineMs'])
            ?: ($left['cold']['engineMs'] <=> $right['cold']['engineMs']);
    });

    return [
        'bestCold' => $bestCold[0] ?? ['ok' => false],
        'bestWarm' => $bestWarm[0] ?? ['ok' => false],
        'balanced' => $frontier[0] ?? ['ok' => false],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $candidates
 * @return array<int, array<string, mixed>>
 */
function sortCandidates(array $candidates, callable $compare): array
{
    usort($candidates, $compare);

    return $candidates;
}

/**
 * @param  array<string, mixed>  $recommendations
 * @return array<string, mixed>
 */
function buildInsights(array $recommendations): array
{
    $balanced = $recommendations['balanced'] ?? null;

    if (! is_array($balanced) || ($balanced['ok'] ?? false) !== true) {
        return [];
    }

    $coldPhase = (string) data_get($balanced, 'cold.timings.dominantPhase', '');
    $warmPhase = (string) data_get($balanced, 'warm.timings.dominantPhase', '');
    $coldShare = (float) data_get($balanced, 'cold.timings.dominantShare', 0.0);
    $warmShare = (float) data_get($balanced, 'warm.timings.dominantShare', 0.0);

    return [
        'coldBottleneck' => [
            'phase' => $coldPhase,
            'share' => $coldShare,
        ],
        'warmBottleneck' => [
            'phase' => $warmPhase,
            'share' => $warmShare,
        ],
        'nextMove' => describeNextMove($coldPhase, $coldShare, $warmPhase, $warmShare),
    ];
}

function describeNextMove(string $coldPhase, float $coldShare, string $warmPhase, float $warmShare): string
{
    if ($warmPhase === 'waitMs' && $warmShare >= 0.25) {
        return 'Readiness dominates warm renders. The next optimization should tighten the readiness contract or emit a more explicit app-ready signal, not just keep tuning pool knobs.';
    }

    if ($coldPhase === 'startupMs' && $coldShare >= 0.25) {
        return 'Cold startup still dominates. The next optimization should focus on browser/process reuse or faster pool warmup rather than render parameters.';
    }

    if ($warmPhase === 'printMs' || $coldPhase === 'printMs') {
        return 'PDF printing is the current ceiling. The next optimization should inspect Chromium print settings, page complexity, or font/image loading cost.';
    }

    if ($coldPhase === 'debugArtifactsMs' && $coldShare >= 0.2) {
        return 'Debug artifact capture is a meaningful slice of cold time. Keep it enabled for fidelity probes, but separate it from steady-state performance claims.';
    }

    return 'No single phase dominates enough to justify another parameter sweep. The next step should be code-level profiling in the dominant phase reported above.';
}

/**
 * @param  array<int, float>  $values
 */
function median(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    if ($count % 2 === 1) {
        return (float) $values[$middle];
    }

    return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

/**
 * @param  array<string, mixed>  $candidate
 */
function applyCandidateConfig(
    string $root,
    string $appPath,
    array $candidate,
    string $baseUrl,
    int $port,
    string $statePath,
    string $userDataDir,
): void {
    config([
        'canio.runtime.mode' => 'embedded',
        'canio.runtime.binary' => realpath($root.'/bin/stagehand'),
        'canio.runtime.working_directory' => $appPath,
        'canio.runtime.base_url' => $baseUrl,
        'canio.runtime.host' => '127.0.0.1',
        'canio.runtime.port' => $port,
        'canio.runtime.auto_start' => true,
        'canio.runtime.auto_install' => false,
        'canio.runtime.state_path' => $statePath,
        'canio.runtime.log_path' => sprintf('%s/storage/logs/canio-tune-%d.log', $appPath, $port),
        'canio.runtime.chromium.user_data_dir' => $userDataDir,
        'canio.runtime.pool.size' => 1,
        'canio.runtime.pool.warm' => (int) $candidate['poolWarm'],
        'canio.runtime.pool.queue_depth' => 4,
        'canio.runtime.pool.acquire_timeout' => 30,
        'canio.runtime.wait.poll_interval_ms' => (int) $candidate['pollIntervalMs'],
        'canio.runtime.wait.settle_frames' => (int) $candidate['settleFrames'],
        'canio.runtime.jobs.backend' => 'memory',
        'canio.runtime.jobs.workers' => 1,
        'canio.runtime.jobs.queue_depth' => 8,
        'canio.runtime.observability.request_logging' => false,
        'canio.runtime.auth.shared_secret' => 'canio-tune-secret',
        'canio.profiles_path' => $root.'/resources/profiles',
    ]);
}

function resetCanioContainer(): void
{
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);
    app()->forgetInstance(StagehandClient::class);
    app()->forgetInstance(StagehandRuntimeBootstrapper::class);
    app()->forgetInstance(CanioCloudSyncer::class);

    Facade::clearResolvedInstance('canio');
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function runColdDebugRender(array $fixture): array
{
    $startedAt = hrtime(true);
    $result = Canio::view('pdf.invoice', ['invoice' => $fixture['invoice']])
        ->profile((string) $fixture['profile'])
        ->title((string) $fixture['title'])
        ->debug()
        ->watch()
        ->thumbnail()
        ->render();
    $wallMs = elapsedMilliseconds($startedAt);
    $attributes = $result->toArray();
    $artifacts = is_array($attributes['artifacts'] ?? null) ? $attributes['artifacts'] : [];
    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : [];
    $sourceHtml = readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null);
    $domSnapshot = readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null);
    $pdfPath = is_string($files['pdf'] ?? null) ? $files['pdf'] : writePdfBytesToTempFile($result->pdfBytes(), 'tune-cold');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'tune-cold');

    $checks = [
        'artifacts' => checkArtifacts($files, $fixture),
        'requiredText' => checkRequiredStrings($sourceHtml.$domSnapshot, $fixture),
        'pdfBytes' => checkPdfBytes($attributes, $fixture),
        'pageScreenshot' => checkScreenshot($files, $fixture),
        'pdfRaster' => checkPdfRaster($pdfRasterPath, $fixture),
    ];

    return [
        'ok' => checksPass($checks),
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($attributes, 'timings.totalMs', 0),
        'timings' => is_array($attributes['timings'] ?? null) ? $attributes['timings'] : [],
        'pdfBytes' => (int) data_get($attributes, 'pdf.bytes', 0),
        'artifactId' => is_string($artifacts['id'] ?? null) ? $artifacts['id'] : null,
        'checks' => $checks,
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function runWarmPlainRender(array $fixture): array
{
    $startedAt = hrtime(true);
    $result = Canio::view('pdf.invoice', ['invoice' => $fixture['invoice']])
        ->profile((string) $fixture['profile'])
        ->title((string) $fixture['title'])
        ->render();
    $wallMs = elapsedMilliseconds($startedAt);
    $attributes = $result->toArray();
    $pdfPath = writePdfBytesToTempFile($result->pdfBytes(), 'tune-warm');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'tune-warm');

    $checks = [
        'pdfBytes' => checkPdfBytes($attributes, $fixture),
        'pdfRaster' => checkPdfRaster($pdfRasterPath, $fixture),
    ];

    return [
        'ok' => checksPass($checks),
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($attributes, 'timings.totalMs', 0),
        'timings' => is_array($attributes['timings'] ?? null) ? $attributes['timings'] : [],
        'pdfBytes' => (int) data_get($attributes, 'pdf.bytes', 0),
        'checks' => $checks,
    ];
}

/**
 * @param  array<string, mixed>  $checks
 */
function checksPass(array $checks): bool
{
    foreach ($checks as $check) {
        if (($check['ok'] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

/**
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkArtifacts(array $files, array $fixture): array
{
    $missing = [];

    foreach ($fixture['required_artifacts'] as $key) {
        $path = $files[$key] ?? null;

        if (! is_string($path) || ! is_file($path)) {
            $missing[] = $key;
        }
    }

    return [
        'ok' => $missing === [],
        'missing' => $missing,
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkRequiredStrings(string $text, array $fixture): array
{
    $missing = [];

    foreach ($fixture['required_strings'] as $needle) {
        if (! str_contains($text, (string) $needle)) {
            $missing[] = $needle;
        }
    }

    return [
        'ok' => $missing === [],
        'missing' => $missing,
    ];
}

/**
 * @param  array<string, mixed>  $result
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkPdfBytes(array $result, array $fixture): array
{
    $expected = (int) data_get($fixture, 'pdf_bytes.expected', 0);
    $toleranceRatio = (float) data_get($fixture, 'pdf_bytes.tolerance_ratio', 0.25);
    $min = (int) floor($expected * (1 - $toleranceRatio));
    $max = (int) ceil($expected * (1 + $toleranceRatio));
    $actual = (int) data_get($result, 'pdf.bytes', 0);

    return [
        'ok' => $actual >= $min && $actual <= $max,
        'expected' => $expected,
        'min' => $min,
        'max' => $max,
        'actual' => $actual,
    ];
}

/**
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkScreenshot(array $files, array $fixture): array
{
    $actualPath = is_string($files['pageScreenshot'] ?? null) ? $files['pageScreenshot'] : null;
    $expectedPath = (string) data_get($fixture, 'golden_screenshot', '');

    if ($actualPath === null || ! is_file($actualPath) || ! is_file($expectedPath)) {
        return [
            'ok' => false,
            'error' => 'Missing actual or expected screenshot.',
        ];
    }

    $sampleWidth = (int) data_get($fixture, 'screenshot.sample_width', 160);
    $sampleHeight = (int) data_get($fixture, 'screenshot.sample_height', 160);
    $comparison = comparePngFiles($actualPath, $expectedPath, $sampleWidth, $sampleHeight);
    $comparison['expectedPath'] = $expectedPath;
    $comparison['actualPath'] = $actualPath;
    $comparison['ok'] = $comparison['similarity'] >= (float) data_get($fixture, 'screenshot.min_similarity', 0.995)
        && $comparison['changedPixelRatio'] <= (float) data_get($fixture, 'screenshot.max_changed_ratio', 0.02);

    return $comparison;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkPdfRaster(string $actualPath, array $fixture): array
{
    $expectedPath = (string) data_get($fixture, 'golden_pdf_raster', '');

    if (! is_file($actualPath) || ! is_file($expectedPath)) {
        return [
            'ok' => false,
            'error' => 'Missing actual or expected PDF raster.',
        ];
    }

    $sampleWidth = (int) data_get($fixture, 'pdf_raster.sample_width', 240);
    $sampleHeight = (int) data_get($fixture, 'pdf_raster.sample_height', 240);
    $comparison = comparePngFiles($actualPath, $expectedPath, $sampleWidth, $sampleHeight);
    $comparison['expectedPath'] = $expectedPath;
    $comparison['actualPath'] = $actualPath;
    $comparison['ok'] = $comparison['similarity'] >= (float) data_get($fixture, 'pdf_raster.min_similarity', 1.0)
        && $comparison['changedPixelRatio'] <= (float) data_get($fixture, 'pdf_raster.max_changed_ratio', 0.0);

    return $comparison;
}

/**
 * @return array<string, mixed>
 */
function comparePngFiles(string $actualPath, string $goldenPath, int $sampleWidth, int $sampleHeight): array
{
    $actualSize = getimagesize($actualPath);
    $goldenSize = getimagesize($goldenPath);

    if (! is_array($actualSize) || ! is_array($goldenSize)) {
        throw new RuntimeException('Unable to read one of the PNG images for comparison.');
    }

    $actualImage = imagecreatefrompng($actualPath);
    $goldenImage = imagecreatefrompng($goldenPath);

    if ($actualImage === false || $goldenImage === false) {
        throw new RuntimeException('Unable to load one of the PNG images for comparison.');
    }

    $actualSample = imagecreatetruecolor($sampleWidth, $sampleHeight);
    $goldenSample = imagecreatetruecolor($sampleWidth, $sampleHeight);

    if ($actualSample === false || $goldenSample === false) {
        throw new RuntimeException('Unable to allocate image buffers for comparison.');
    }

    imagecopyresampled($actualSample, $actualImage, 0, 0, 0, 0, $sampleWidth, $sampleHeight, imagesx($actualImage), imagesy($actualImage));
    imagecopyresampled($goldenSample, $goldenImage, 0, 0, 0, 0, $sampleWidth, $sampleHeight, imagesx($goldenImage), imagesy($goldenImage));

    $sumAbsoluteChannelDelta = 0.0;
    $changedPixels = 0;
    $totalPixels = $sampleWidth * $sampleHeight;

    for ($y = 0; $y < $sampleHeight; $y++) {
        for ($x = 0; $x < $sampleWidth; $x++) {
            $actualColor = imagecolorsforindex($actualSample, imagecolorat($actualSample, $x, $y));
            $goldenColor = imagecolorsforindex($goldenSample, imagecolorat($goldenSample, $x, $y));

            $delta = abs($actualColor['red'] - $goldenColor['red'])
                + abs($actualColor['green'] - $goldenColor['green'])
                + abs($actualColor['blue'] - $goldenColor['blue']);

            $sumAbsoluteChannelDelta += $delta;

            if ($delta > 24) {
                $changedPixels++;
            }
        }
    }

    imagedestroy($actualImage);
    imagedestroy($goldenImage);
    imagedestroy($actualSample);
    imagedestroy($goldenSample);

    $avgChannelDelta = $sumAbsoluteChannelDelta / max(1, $totalPixels * 3);
    $similarity = max(0.0, 1 - ($avgChannelDelta / 255));

    return [
        'dimensionsMatch' => $actualSize[0] === $goldenSize[0] && $actualSize[1] === $goldenSize[1],
        'actualDimensions' => [$actualSize[0], $actualSize[1]],
        'goldenDimensions' => [$goldenSize[0], $goldenSize[1]],
        'avgChannelDelta' => round($avgChannelDelta, 4),
        'changedPixelRatio' => round($changedPixels / max(1, $totalPixels), 6),
        'similarity' => round($similarity, 6),
        'sampleSize' => [$sampleWidth, $sampleHeight],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $valid
 * @return array<int, array<string, mixed>>
 */
function paretoFrontier(array $valid): array
{
    $frontier = [];

    foreach ($valid as $index => $candidate) {
        $dominated = false;

        foreach ($valid as $otherIndex => $other) {
            if ($index === $otherIndex) {
                continue;
            }

            $otherCold = (float) data_get($other, 'cold.engineMs', INF);
            $otherWarm = (float) data_get($other, 'warm.engineMs', INF);
            $candidateCold = (float) data_get($candidate, 'cold.engineMs', INF);
            $candidateWarm = (float) data_get($candidate, 'warm.engineMs', INF);

            $dominates = $otherCold <= $candidateCold
                && $otherWarm <= $candidateWarm
                && ($otherCold < $candidateCold || $otherWarm < $candidateWarm);

            if ($dominates) {
                $dominated = true;
                break;
            }
        }

        if (! $dominated) {
            $frontier[] = $candidate;
        }
    }

    usort($frontier, static function (array $left, array $right): int {
        return ($left['warm']['engineMs'] <=> $right['warm']['engineMs'])
            ?: ($left['cold']['engineMs'] <=> $right['cold']['engineMs']);
    });

    return $frontier;
}

/**
 * @param  array<string, mixed>  $report
 */
function renderTuningReport(array $report): void
{
    echo "Example invoice Canio tuner\n";
    echo sprintf("- fixture: %s\n", $report['fixture']);
    echo sprintf(
        "- search: %d cold runs x %d warm runs per candidate\n",
        data_get($report, 'settings.coldRuns', 1),
        data_get($report, 'settings.warmRuns', 3),
    );
    echo sprintf("- candidates: %d\n", $report['candidateCount']);
    echo sprintf("- valid: %d\n\n", $report['validCount']);

    if (($report['recommended']['ok'] ?? false) === false) {
        echo "No valid candidate preserved the fidelity gates.\n";
        return;
    }

    echo "Recommendations\n";
    printRecommendationLine('balanced', (array) data_get($report, 'recommendations.balanced', []));
    printRecommendationLine('best_cold', (array) data_get($report, 'recommendations.bestCold', []));
    printRecommendationLine('best_warm', (array) data_get($report, 'recommendations.bestWarm', []));
    echo "\n";

    $coldBottleneck = (array) data_get($report, 'insights.coldBottleneck', []);
    $warmBottleneck = (array) data_get($report, 'insights.warmBottleneck', []);
    echo "Phase diagnosis\n";
    echo sprintf(
        "- cold bottleneck: %s (%.1f%%)\n",
        $coldBottleneck['phase'] ?? 'n/a',
        ((float) ($coldBottleneck['share'] ?? 0.0)) * 100,
    );
    echo sprintf(
        "- warm bottleneck: %s (%.1f%%)\n",
        $warmBottleneck['phase'] ?? 'n/a',
        ((float) ($warmBottleneck['share'] ?? 0.0)) * 100,
    );
    echo sprintf("- next move: %s\n\n", data_get($report, 'insights.nextMove', 'n/a'));

    echo "Top valid candidates\n";
    echo "| Candidate | Score | Cold ms | Warm ms | Poll ms | Frames | Pool warm |\n";
    echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";

    foreach ((array) ($report['topCandidates'] ?? []) as $candidate) {
        echo sprintf(
            "| %s | %.4f | %.2f | %.2f | %d | %d | %d |\n",
            $candidate['name'],
            (float) ($candidate['score'] ?? 0.0),
            $candidate['cold']['engineMs'],
            $candidate['warm']['engineMs'],
            $candidate['candidate']['pollIntervalMs'],
            $candidate['candidate']['settleFrames'],
            $candidate['candidate']['poolWarm'],
        );
    }

    echo "\n";

    echo "Pareto frontier\n";
    echo "| Candidate | Balance | Cold ms | Warm ms | Poll ms | Frames | Pool warm |\n";
    echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";

    foreach ($report['paretoFrontier'] as $candidate) {
        echo sprintf(
            "| %s | %.4f | %.2f | %.2f | %d | %d | %d |\n",
            $candidate['name'],
            (float) ($candidate['balanceScore'] ?? 0.0),
            $candidate['cold']['engineMs'],
            $candidate['warm']['engineMs'],
            $candidate['candidate']['pollIntervalMs'],
            $candidate['candidate']['settleFrames'],
            $candidate['candidate']['poolWarm'],
        );
    }
}

/**
 * @param  array<string, mixed>  $candidate
 */
function printRecommendationLine(string $label, array $candidate): void
{
    if (($candidate['ok'] ?? false) !== true) {
        echo sprintf("- %s: n/a\n", $label);

        return;
    }

    echo sprintf(
        "- %s: %s (cold %.2f ms, warm %.2f ms, poll %d, frames %d, warm %d)\n",
        $label,
        $candidate['name'],
        $candidate['cold']['engineMs'],
        $candidate['warm']['engineMs'],
        $candidate['candidate']['pollIntervalMs'],
        $candidate['candidate']['settleFrames'],
        $candidate['candidate']['poolWarm'],
    );
}

function prepareTempPdfPath(string $prefix): string
{
    $base = tempnam(sys_get_temp_dir(), $prefix.'-invoice-');

    if ($base === false) {
        throw new RuntimeException('Unable to allocate a temporary PDF file.');
    }

    $path = $base.'.pdf';

    if (! rename($base, $path)) {
        throw new RuntimeException(sprintf('Unable to prepare PDF path %s.', $path));
    }

    return $path;
}

function writePdfBytesToTempFile(string $bytes, string $prefix): string
{
    $path = prepareTempPdfPath($prefix);

    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException(sprintf('Unable to write PDF bytes to %s.', $path));
    }

    return $path;
}

function rasterizePdfToPng(string $pdfPath, int $size, string $prefix): string
{
    $outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'-pdf-raster-'.bin2hex(random_bytes(4));
    ensureDirectory($outputDir);

    $command = sprintf(
        '/usr/bin/qlmanage -t -s %d -o %s %s >/dev/null 2>&1',
        $size,
        escapeshellarg($outputDir),
        escapeshellarg($pdfPath),
    );

    exec($command, $output, $exitCode);

    $pngPath = $outputDir.DIRECTORY_SEPARATOR.basename($pdfPath).'.png';

    if ($exitCode !== 0 || ! is_file($pngPath)) {
        throw new RuntimeException(sprintf('Unable to rasterize %s with qlmanage.', $pdfPath));
    }

    return $pngPath;
}

function readTextFile(?string $path): string
{
    if ($path === null || ! is_file($path)) {
        return '';
    }

    $contents = file_get_contents($path);

    return is_string($contents) ? $contents : '';
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (! mkdir($path, 0777, true) && ! is_dir($path)) {
        throw new RuntimeException(sprintf('Unable to create directory %s.', $path));
    }
}

function elapsedMilliseconds(int $startedAt): float
{
    return (hrtime(true) - $startedAt) / 1_000_000;
}

function findAvailablePort(int $start = 9840, int $end = 9999): int
{
    for ($port = $start; $port <= $end; $port++) {
        $server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errorCode, $errorMessage);

        if ($server === false) {
            continue;
        }

        fclose($server);

        return $port;
    }

    throw new RuntimeException(sprintf('Unable to find an available port between %d and %d.', $start, $end));
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path.DIRECTORY_SEPARATOR.$item;

        if (is_dir($itemPath)) {
            deleteDirectory($itemPath);

            continue;
        }

        @unlink($itemPath);
    }

    @rmdir($path);
}

function terminateRuntimeOnPort(int $port): void
{
    $pids = [];
    exec(sprintf('lsof -ti tcp:%d 2>/dev/null', $port), $pids);

    foreach ($pids as $pid) {
        $pid = trim((string) $pid);

        if ($pid === '' || ! ctype_digit($pid)) {
            continue;
        }

        exec(sprintf('kill %s 2>/dev/null', escapeshellarg($pid)));
    }

    usleep(200000);

    $remaining = [];
    exec(sprintf('lsof -ti tcp:%d 2>/dev/null', $port), $remaining);

    foreach ($remaining as $pid) {
        $pid = trim((string) $pid);

        if ($pid === '' || ! ctype_digit($pid)) {
            continue;
        }

        exec(sprintf('kill -9 %s 2>/dev/null', escapeshellarg($pid)));
    }
}
