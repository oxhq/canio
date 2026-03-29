#!/usr/bin/env php
<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf as DompdfPdf;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Contracts\Console\Kernel;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;
use Oxhq\Canio\Facades\Canio;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf as SpatiePdf;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");

    exit(1);
}

$options = getopt('', ['app::', 'json', 'update-golden', 'fair', 'warmups::', 'iterations::']);
$root = realpath(__DIR__.'/..');
$fixture = require __DIR__.'/example_invoice_reference.php';
$appPath = realpath((string) ($options['app'] ?? ($root !== false ? $root.'/examples/laravel-app/app' : '')));
$asJson = array_key_exists('json', $options);
$updateGolden = array_key_exists('update-golden', $options);
$fairMode = array_key_exists('fair', $options);
$warmupRuns = max(0, (int) ($options['warmups'] ?? 1));
$iterationRuns = max(1, (int) ($options['iterations'] ?? 3));

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

configureEnvironment($root, $appPath);

require $autoloadPath;

/** @var \Illuminate\Foundation\Application $app */
$app = require $bootstrapPath;
$app->make(Kernel::class)->bootstrap();

$html = view('pdf.invoice', ['invoice' => $fixture['invoice']])->render();
$html = $fairMode ? normalizeFairBenchmarkHtml($html) : $html;

$engines = [
    ...benchmarkCanioEngines($fixture, $html, $fairMode, $warmupRuns, $iterationRuns),
    benchmarkBarryvdhDompdfEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkBarryvdhSnappyEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkLaravelMpdfEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkSpatieBrowsershotEngine($fixture, $appPath, $html, $fairMode, $warmupRuns, $iterationRuns),
];

$goldenPdfRasterPath = (string) $fixture['golden_pdf_raster'];

if ($updateGolden || ! is_file($goldenPdfRasterPath)) {
    ensureDirectory(dirname($goldenPdfRasterPath));

    if (! copy($engines[0]['pdfRasterPath'], $goldenPdfRasterPath)) {
        throw new RuntimeException(sprintf('Unable to update the PDF raster golden at %s.', $goldenPdfRasterPath));
    }
}

$report = buildMatrixReport($fixture, $goldenPdfRasterPath, $engines, $fairMode ? 'fair' : 'baseline', $warmupRuns, $iterationRuns);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    renderMatrixReport($report);
}

$allOk = true;

foreach ($report['engines'] as $engine) {
    if (($engine['ok'] ?? false) !== true) {
        $allOk = false;
        break;
    }
}

exit($allOk ? 0 : 1);

/**
 * @param  array<string, mixed>  $fixture
 * @return array<int, array<string, mixed>>
 */
function benchmarkCanioEngines(array $fixture, string $html, bool $fairMode, int $warmupRuns, int $iterationRuns): array
{
    return [
        benchmarkCanioWarmMissEngine($fixture, $html, $fairMode, $warmupRuns, $iterationRuns),
        benchmarkCanioCacheHitEngine($fixture, $html, $fairMode, $warmupRuns, $iterationRuns),
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkCanioWarmMissEngine(array $fixture, string $html, bool $fairMode, int $warmupRuns, int $iterationRuns): array
{
    $sequence = 0;

    return benchmarkEngine(
        'canio-warm-miss',
        'oxhq/canio (warm-miss)',
        $warmupRuns,
        $iterationRuns,
        static function (bool $verify) use ($fixture, $html, $fairMode, &$sequence): array {
            $sequence++;

            return renderCanioSample(
                $fixture,
                $html,
                $fairMode,
                $verify,
                canioBenchmarkTitle($fixture, 'warm-miss', $sequence),
            );
        },
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkCanioCacheHitEngine(array $fixture, string $html, bool $fairMode, int $warmupRuns, int $iterationRuns): array
{
    $title = canioBenchmarkTitle($fixture, 'cache-hit', 1);

    // Prime the cache once so the measured path is a real cache hit instead of a warm miss.
    renderCanioSample($fixture, $html, $fairMode, false, $title);

    return benchmarkEngine(
        'canio-cache-hit',
        'oxhq/canio (cache-hit)',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderCanioSample($fixture, $html, $fairMode, $verify, $title),
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkBarryvdhDompdfEngine(array $fixture, string $html, int $warmupRuns, int $iterationRuns): array
{
    return benchmarkEngine(
        'barryvdh-dompdf',
        'barryvdh/laravel-dompdf',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderBarryvdhDompdfSample($fixture, $html, $verify),
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkBarryvdhSnappyEngine(array $fixture, string $html, int $warmupRuns, int $iterationRuns): array
{
    return benchmarkEngine(
        'barryvdh-snappy',
        'barryvdh/laravel-snappy',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderBarryvdhSnappySample($fixture, $html, $verify),
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkLaravelMpdfEngine(array $fixture, string $html, int $warmupRuns, int $iterationRuns): array
{
    return benchmarkEngine(
        'laravel-mpdf',
        'carlos-meneses/laravel-mpdf',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderLaravelMpdfSample($fixture, $html, $verify),
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function benchmarkSpatieBrowsershotEngine(array $fixture, string $appPath, string $html, bool $fairMode, int $warmupRuns, int $iterationRuns): array
{
    return benchmarkEngine(
        'spatie-browsershot',
        'spatie/laravel-pdf (browsershot)',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderSpatieBrowsershotSample($fixture, $appPath, $html, $fairMode, $verify),
    );
}

/**
 * @param  callable(bool): array<string, mixed>  $runner
 * @return array<string, mixed>
 */
function benchmarkEngine(string $key, string $label, int $warmupRuns, int $iterationRuns, callable $runner): array
{
    $cold = $runner(true);

    for ($i = 0; $i < $warmupRuns; $i++) {
        $runner(false);
    }

    $samples = [];
    for ($i = 0; $i < $iterationRuns; $i++) {
        $samples[] = $runner(false);
    }

    $steady = summarizeBenchmarkSamples($samples);

    return [
        'key' => $key,
        'label' => $label,
        'engine' => (string) ($cold['engine'] ?? $key),
        'cold' => $cold,
        'steady' => $steady,
        'samples' => $samples,
        'warmupRuns' => $warmupRuns,
        'iterationRuns' => $iterationRuns,
        'ok' => ($cold['ok'] ?? false) === true && ($steady['ok'] ?? false) === true,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $samples
 * @return array<string, mixed>
 */
function summarizeBenchmarkSamples(array $samples): array
{
    if ($samples === []) {
        return [
            'ok' => false,
            'runCount' => 0,
        ];
    }

    $wallMs = array_map(static fn (array $sample): float => (float) ($sample['wallMs'] ?? 0.0), $samples);
    $engineMs = array_values(array_filter(
        array_map(static fn (array $sample): mixed => $sample['engineMs'] ?? null, $samples),
        static fn (mixed $value): bool => $value !== null,
    ));
    $pdfBytes = array_map(static fn (array $sample): int => (int) ($sample['pdfBytes'] ?? 0), $samples);

    $summary = [
        'ok' => true,
        'runCount' => count($samples),
        'wallMs' => benchmarkStats($wallMs),
        'pdfBytes' => benchmarkStats($pdfBytes),
    ];

    $summary['engineMs'] = $engineMs === [] ? null : benchmarkStats(array_map(static fn (mixed $value): float => (float) $value, $engineMs));

    if (isset($samples[0]['rasterCheck'])) {
        $summary['rasterCheck'] = [
            'similarity' => benchmarkStats(array_map(static fn (array $sample): float => (float) data_get($sample, 'rasterCheck.similarity', 0.0), $samples)),
            'changedPixelRatio' => benchmarkStats(array_map(static fn (array $sample): float => (float) data_get($sample, 'rasterCheck.changedPixelRatio', 0.0), $samples)),
        ];
    }

    return $summary;
}

/**
 * @param  array<int, int|float>  $values
 * @return array<string, float|int>
 */
function benchmarkStats(array $values): array
{
    if ($values === []) {
        return [
            'min' => 0,
            'median' => 0,
            'max' => 0,
        ];
    }

    sort($values);

    return [
        'min' => round((float) $values[0], 2),
        'median' => round(benchmarkMedian($values), 2),
        'max' => round((float) $values[count($values) - 1], 2),
    ];
}

/**
 * @param  array<int, int|float>  $values
 */
function benchmarkMedian(array $values): float
{
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }

    $middle = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (float) $values[$middle];
    }

    return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderCanioSample(array $fixture, string $html, bool $fairMode, bool $verify, ?string $title = null): array
{
    $startedAt = hrtime(true);
    $render = Canio::html($html)
        ->profile((string) $fixture['profile'])
        ->title($title ?? (string) $fixture['title']);

    if (! $fairMode) {
        $render = $render->debug()->watch()->thumbnail();
    }

    $result = $render->render();
    $wallMs = elapsedMilliseconds($startedAt);
    $attributes = $result->toArray();
    $pdfBytes = (int) data_get($attributes, 'pdf.bytes', strlen($result->pdfBytes()));

    $sample = [
        'engine' => 'canio',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($attributes, 'timings.totalMs', 0),
        'timings' => is_array($attributes['timings'] ?? null) ? $attributes['timings'] : [],
        'pdfBytes' => $pdfBytes,
    ];

    if (! $verify) {
        return $sample;
    }

    $artifacts = is_array($attributes['artifacts'] ?? null) ? $attributes['artifacts'] : [];
    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : [];
    $pdfPath = is_string($files['pdf'] ?? null) ? $files['pdf'] : writePdfBytesToTempFile($result->pdfBytes(), 'canio');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'canio');

    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = $pdfRasterPath;
    $sample['artifactId'] = is_string($artifacts['id'] ?? null) ? $artifacts['id'] : null;
    $sample['artifactDirectory'] = is_string($artifacts['directory'] ?? null) ? $artifacts['directory'] : null;
    $sample['artifactCheck'] = $fairMode ? ['ok' => true, 'missing' => []] : checkArtifacts($files, $fixture);
    $sample['textCheck'] = $fairMode
        ? checkRequiredStrings($html, $fixture)
        : checkRequiredStrings(
            readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null)
            .readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null),
            $fixture,
        );
    $sample['rasterCheck'] = comparePngFiles(
        $pdfRasterPath,
        (string) data_get($fixture, 'golden_pdf_raster', ''),
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );
    $sample['rasterCheck']['ok'] = true;

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 */
function canioBenchmarkTitle(array $fixture, string $mode, int $sequence): string
{
    return sprintf('%s [%s:%d]', (string) $fixture['title'], $mode, $sequence);
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderBarryvdhDompdfSample(array $fixture, string $html, bool $verify): array
{
    $startedAt = hrtime(true);
    $bytes = DompdfPdf::loadHTML($html)
        ->setPaper('a4')
        ->output();
    $wallMs = elapsedMilliseconds($startedAt);
    $sample = [
        'engine' => 'dompdf',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
    ];

    if (! $verify) {
        return $sample;
    }

    $pdfPath = writePdfBytesToTempFile($bytes, 'barryvdh-dompdf');
    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'barryvdh-dompdf');
    $sample['textCheck'] = checkRequiredStrings($html, $fixture);
    $sample['rasterCheck'] = comparePngFiles(
        $sample['pdfRasterPath'],
        (string) data_get($fixture, 'golden_pdf_raster', ''),
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderBarryvdhSnappySample(array $fixture, string $html, bool $verify): array
{
    $startedAt = hrtime(true);
    $bytes = SnappyPdf::loadHTML($html)
        ->setPaper('a4')
        ->setOption('enable-local-file-access', true)
        ->setOption('encoding', 'utf-8')
        ->setTemporaryFolder(sys_get_temp_dir())
        ->output();
    $wallMs = elapsedMilliseconds($startedAt);
    $sample = [
        'engine' => 'wkhtmltopdf',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
    ];

    if (! $verify) {
        return $sample;
    }

    $pdfPath = writePdfBytesToTempFile($bytes, 'barryvdh-snappy');
    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'barryvdh-snappy');
    $sample['textCheck'] = checkRequiredStrings($html, $fixture);
    $sample['rasterCheck'] = comparePngFiles(
        $sample['pdfRasterPath'],
        (string) data_get($fixture, 'golden_pdf_raster', ''),
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderLaravelMpdfSample(array $fixture, string $html, bool $verify): array
{
    $startedAt = hrtime(true);
    $bytes = LaravelMpdf::loadHTML($html, [
        'format' => 'A4',
        'temp_dir' => sys_get_temp_dir(),
    ])->output();
    $wallMs = elapsedMilliseconds($startedAt);
    $sample = [
        'engine' => 'mpdf',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
    ];

    if (! $verify) {
        return $sample;
    }

    $pdfPath = writePdfBytesToTempFile($bytes, 'laravel-mpdf');
    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'laravel-mpdf');
    $sample['textCheck'] = checkRequiredStrings($html, $fixture);
    $sample['rasterCheck'] = comparePngFiles(
        $sample['pdfRasterPath'],
        (string) data_get($fixture, 'golden_pdf_raster', ''),
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderSpatieBrowsershotSample(array $fixture, string $appPath, string $html, bool $fairMode, bool $verify): array
{
    $pdfPath = prepareTempPdfPath('spatie-browsershot');
    $startedAt = hrtime(true);
    SpatiePdf::html($html)
        ->driver('browsershot')
        ->format('a4')
        ->withBrowsershot(static function (Browsershot $browsershot) use ($appPath, $fairMode): void {
            $browsershot
                ->setNodeBinary('/opt/homebrew/bin/node')
                ->setNpmBinary('/opt/homebrew/bin/npm')
                ->setChromePath('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
                ->setNodeModulePath($appPath.'/node_modules')
                ->setIncludePath('$PATH:/usr/local/bin:/opt/homebrew/bin')
                ->writeOptionsToFile();

            if ($fairMode) {
                $browsershot
                    ->waitUntilNetworkIdle(false)
                    ->waitForFunction('() => window.__CANIO_READY__ === true', null, 30000);
            }
        })
        ->save($pdfPath);
    $wallMs = elapsedMilliseconds($startedAt);
    $bytes = file_get_contents($pdfPath);

    if (! is_string($bytes)) {
        throw new RuntimeException(sprintf('Unable to read Spatie Browsershot PDF at %s.', $pdfPath));
    }

    $sample = [
        'engine' => 'chromium',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
    ];

    if (! $verify) {
        return $sample;
    }

    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'spatie-browsershot');
    $sample['textCheck'] = checkRequiredStrings($html, $fixture);
    $sample['rasterCheck'] = comparePngFiles(
        $sample['pdfRasterPath'],
        (string) data_get($fixture, 'golden_pdf_raster', ''),
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<int, array<string, mixed>>  $engines
 * @return array<string, mixed>
 */
function buildMatrixReport(array $fixture, string $goldenPdfRasterPath, array $engines, string $mode, int $warmupRuns, int $iterationRuns): array
{
    foreach ($engines as $index => $engine) {
        $engines[$index]['cold']['ok'] = ($engine['cold']['ok'] ?? false) === true;
        $engines[$index]['steady']['ok'] = ($engine['steady']['ok'] ?? false) === true;
        $engines[$index]['ok'] = ($engine['cold']['ok'] ?? false) === true && ($engine['steady']['ok'] ?? false) === true;
    }

    if ($mode !== 'fair') {
        usort($engines, static function (array $left, array $right): int {
            return (($left['steady']['wallMs']['median'] ?? INF) <=> ($right['steady']['wallMs']['median'] ?? INF))
                ?: (($left['cold']['wallMs'] ?? INF) <=> ($right['cold']['wallMs'] ?? INF));
        });
    }

    return [
        'fixture' => (string) $fixture['name'],
        'mode' => $mode,
        'warmups' => $warmupRuns,
        'iterations' => $iterationRuns,
        'goldenPdfRaster' => $goldenPdfRasterPath,
        'engines' => $engines,
    ];
}

/**
 * @param  array<string, mixed>  $report
 */
function renderMatrixReport(array $report): void
{
    echo "Example invoice package matrix\n";
    echo sprintf("- fixture: %s\n", $report['fixture']);
    echo sprintf("- mode: %s\n", $report['mode'] ?? 'baseline');
    echo sprintf("- warmups: %d\n", (int) ($report['warmups'] ?? 0));
    echo sprintf("- iterations: %d\n", (int) ($report['iterations'] ?? 0));
    echo sprintf("- golden_pdf_raster: %s\n", $report['goldenPdfRaster']);
    echo "\n";
    echo "| Package | Engine | Cold ms | Steady ms | Steady best | Steady worst | PDF bytes median | Similarity | Text |\n";
    echo "| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |\n";

    foreach ($report['engines'] as $engine) {
        echo sprintf(
            "| %s | %s | %.2f | %.2f | %.2f | %.2f | %d | %.6f | %s |\n",
            $engine['label'],
            $engine['engine'],
            (float) data_get($engine, 'cold.wallMs', 0),
            (float) data_get($engine, 'steady.wallMs.median', 0),
            (float) data_get($engine, 'steady.wallMs.min', 0),
            (float) data_get($engine, 'steady.wallMs.max', 0),
            (int) data_get($engine, 'steady.pdfBytes.median', 0),
            (float) data_get($engine, 'cold.rasterCheck.similarity', 0),
            ($engine['cold']['textCheck']['ok'] ?? true) ? 'ok' : 'fail',
        );
    }
}

function configureEnvironment(string $root, string $appPath): void
{
    $stagehandBinary = realpath($root.'/bin/stagehand');
    $wkhtmlPdfBinary = resolveBinaryPath([
        getenv('WKHTML_PDF_BINARY') ?: null,
        '/opt/homebrew/bin/wkhtmltopdf',
        '/usr/local/bin/wkhtmltopdf',
        getenv('HOME') ? rtrim((string) getenv('HOME'), '/').'/usr/local/bin/wkhtmltopdf' : null,
    ], 'wkhtmltopdf');
    $wkhtmlImageBinary = resolveBinaryPath([
        getenv('WKHTML_IMG_BINARY') ?: null,
        '/opt/homebrew/bin/wkhtmltoimage',
        '/usr/local/bin/wkhtmltoimage',
        getenv('HOME') ? rtrim((string) getenv('HOME'), '/').'/usr/local/bin/wkhtmltoimage' : null,
    ], 'wkhtmltoimage');

    if ($stagehandBinary === false) {
        fwrite(STDERR, "Unable to resolve bin/stagehand. Run ./scripts/build-stagehand.sh first.\n");

        exit(1);
    }

    $runtimePort = findAvailablePort();
    $runtimeBaseUrl = sprintf('http://127.0.0.1:%d', $runtimePort);
    $runtimeStatePath = sprintf('%s/storage/app/canio/runtime-matrix-%d', $appPath, $runtimePort);
    $chromiumUserDataDir = $runtimeStatePath.'/chromium-profile';

    deleteDirectory($runtimeStatePath);

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
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-matrix-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv(sprintf('CANIO_RUNTIME_BASE_URL=%s', $runtimeBaseUrl));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv(sprintf('CANIO_RUNTIME_PORT=%d', $runtimePort));
    putenv(sprintf('CANIO_RUNTIME_STATE_PATH=%s', $runtimeStatePath));
    putenv(sprintf('CANIO_RUNTIME_LOG_PATH=%s', $appPath.'/storage/logs/canio-matrix-'.$runtimePort.'.log'));
    putenv(sprintf('CANIO_CHROMIUM_USER_DATA_DIR=%s', $chromiumUserDataDir));
    putenv('CANIO_RUNTIME_BROWSER_POOL_SIZE=1');
    putenv('CANIO_RUNTIME_BROWSER_POOL_WARM=1');
    putenv('CANIO_RUNTIME_BROWSER_QUEUE_DEPTH=4');
    putenv('CANIO_RUNTIME_BROWSER_ACQUIRE_TIMEOUT=30');
    putenv('CANIO_RUNTIME_JOB_BACKEND=memory');
    putenv('CANIO_RUNTIME_JOB_WORKERS=1');
    putenv('CANIO_RUNTIME_JOB_QUEUE_DEPTH=8');
    putenv(sprintf('CANIO_PROFILES_PATH=%s', $root.'/resources/profiles'));
    putenv(sprintf('WKHTML_PDF_BINARY=%s', $wkhtmlPdfBinary));
    putenv(sprintf('WKHTML_IMG_BINARY=%s', $wkhtmlImageBinary));
}

function normalizeFairBenchmarkHtml(string $html): string
{
    $normalized = preg_replace('/,\s*250\);/', ', 0);', $html, 1);

    return is_string($normalized) ? $normalized : $html;
}

/**
 * @param  array<int, string|null>  $candidates
 */
function resolveBinaryPath(array $candidates, string $label): string
{
    foreach ($candidates as $candidate) {
        if (! is_string($candidate) || $candidate === '') {
            continue;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(sprintf(
        'Unable to find %s. Set %s or install the official binary.',
        $label,
        match ($label) {
            'wkhtmltopdf' => 'WKHTML_PDF_BINARY',
            'wkhtmltoimage' => 'WKHTML_IMG_BINARY',
            default => strtoupper(str_replace('-', '_', $label)).'_BINARY',
        },
    ));
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
        throw new RuntimeException('Unable to allocate image buffers for screenshot comparison.');
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

function findAvailablePort(int $start = 9814, int $end = 9899): int
{
    for ($port = $start; $port <= $end; $port++) {
        $server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errorCode, $errorMessage);

        if ($server === false) {
            continue;
        }

        fclose($server);

        return $port;
    }

    fwrite(STDERR, sprintf("Unable to find an available Canio runtime port between %d and %d.\n", $start, $end));

    exit(1);
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
