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

$options = getopt('', ['app::', 'json', 'update-golden', 'warmups::', 'iterations::']);
$root = realpath(__DIR__.'/..');
$fixture = require __DIR__.'/javascript_probe_reference.php';
$appPath = realpath((string) ($options['app'] ?? ($root !== false ? $root.'/examples/laravel-app/app' : '')));
$asJson = array_key_exists('json', $options);
$updateGolden = array_key_exists('update-golden', $options);
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

$html = view('pdf.javascript-probe', [
    'title' => (string) $fixture['title'],
    'probeUrl' => (string) $fixture['probe_url'],
])->render();

$goldenPdfRasterPath = (string) $fixture['golden_pdf_raster'];

if ($updateGolden || ! is_file($goldenPdfRasterPath)) {
    $seed = renderCanioSample($fixture, $html, true, (string) $fixture['title'].' [golden-seed]');
    ensureDirectory(dirname($goldenPdfRasterPath));

    if (! copy((string) $seed['pdfRasterPath'], $goldenPdfRasterPath)) {
        throw new RuntimeException(sprintf('Unable to update the JavaScript probe golden at %s.', $goldenPdfRasterPath));
    }
}

$engines = [
    benchmarkCanioEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkBarryvdhDompdfEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkBarryvdhSnappyEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkLaravelMpdfEngine($fixture, $html, $warmupRuns, $iterationRuns),
    benchmarkSpatieBrowsershotEngine($fixture, $appPath, $html, $warmupRuns, $iterationRuns),
];

$report = buildMatrixReport($fixture, $goldenPdfRasterPath, $engines, $warmupRuns, $iterationRuns);

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
 * @return array<string, mixed>
 */
function benchmarkCanioEngine(array $fixture, string $html, int $warmupRuns, int $iterationRuns): array
{
    $sequence = 0;

    return benchmarkEngine(
        'canio',
        'oxhq/canio',
        $warmupRuns,
        $iterationRuns,
        static function (bool $verify) use ($fixture, $html, &$sequence): array {
            $sequence++;

            return renderCanioSample(
                $fixture,
                $html,
                $verify,
                sprintf('%s [js-probe:%d]', (string) $fixture['title'], $sequence),
            );
        },
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
function benchmarkSpatieBrowsershotEngine(array $fixture, string $appPath, string $html, int $warmupRuns, int $iterationRuns): array
{
    return benchmarkEngine(
        'spatie-browsershot',
        'spatie/laravel-pdf (browsershot)',
        $warmupRuns,
        $iterationRuns,
        static fn (bool $verify) => renderSpatieBrowsershotSample($fixture, $appPath, $html, $verify),
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

    return [
        'key' => $key,
        'label' => $label,
        'engine' => (string) ($cold['engine'] ?? $key),
        'cold' => $cold,
        'steady' => summarizeBenchmarkSamples($samples),
        'samples' => $samples,
        'warmupRuns' => $warmupRuns,
        'iterationRuns' => $iterationRuns,
        'ok' => ($cold['ok'] ?? false) === true,
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

    return [
        'ok' => true,
        'runCount' => count($samples),
        'wallMs' => benchmarkStats($wallMs),
        'pdfBytes' => benchmarkStats($pdfBytes),
        'engineMs' => $engineMs === [] ? null : benchmarkStats(array_map(static fn (mixed $value): float => (float) $value, $engineMs)),
    ];
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
function renderCanioSample(array $fixture, string $html, bool $verify, string $title): array
{
    $startedAt = hrtime(true);
    $render = Canio::html($html)
        ->profile((string) $fixture['profile'])
        ->title($title);

    if ($verify) {
        $render = $render->debug()->watch();
    }

    $result = $render->render();
    $wallMs = elapsedMilliseconds($startedAt);
    $attributes = $result->toArray();
    $sample = [
        'engine' => 'canio',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($attributes, 'timings.totalMs', 0),
        'timings' => is_array($attributes['timings'] ?? null) ? $attributes['timings'] : [],
        'pdfBytes' => (int) data_get($attributes, 'pdf.bytes', strlen($result->pdfBytes())),
    ];

    if (! $verify) {
        return $sample;
    }

    $artifacts = is_array($attributes['artifacts'] ?? null) ? $attributes['artifacts'] : [];
    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : [];
    $sourceHtml = readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null);
    $domSnapshot = readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null);
    $pdfPath = is_string($files['pdf'] ?? null) ? $files['pdf'] : writePdfBytesToTempFile($result->pdfBytes(), 'js-probe-canio');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'js-probe-canio');
    $rasterCheck = compareAgainstGoldenIfAvailable(
        $pdfRasterPath,
        (string) $fixture['golden_pdf_raster'],
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );
    $jsSignal = extractProbeBadgeSignal(
        $pdfRasterPath,
        (int) data_get($fixture, 'js_signal.crop_width', 420),
        (int) data_get($fixture, 'js_signal.crop_height', 120),
        (float) data_get($fixture, 'js_signal.min_dark_ratio', 0.15),
        (float) data_get($fixture, 'js_signal.min_green_ratio', 0.005),
    );

    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = $pdfRasterPath;
    $sample['artifactDirectory'] = is_string($artifacts['directory'] ?? null) ? $artifacts['directory'] : null;
    $sample['checks'] = [
        'artifacts' => checkArtifacts($files, $fixture),
        'sourceStatic' => checkSourceStatic($sourceHtml, $fixture),
        'domMutation' => checkRequiredStrings($domSnapshot, (array) $fixture['dom_required_strings']),
    ];
    $sample['rasterCheck'] = $rasterCheck;
    $sample['jsSignal'] = $jsSignal;
    $sample['jsExecuted'] = $jsSignal['ok']
        && ($sample['checks']['artifacts']['ok'] ?? false)
        && ($sample['checks']['sourceStatic']['ok'] ?? false)
        && ($sample['checks']['domMutation']['ok'] ?? false);

    return $sample;
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

    return finalizeNonBrowserSample($fixture, 'dompdf', 'js-probe-dompdf', $bytes, $wallMs, $verify);
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

    return finalizeNonBrowserSample($fixture, 'wkhtmltopdf', 'js-probe-snappy', $bytes, $wallMs, $verify);
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

    return finalizeNonBrowserSample($fixture, 'mpdf', 'js-probe-mpdf', $bytes, $wallMs, $verify);
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderSpatieBrowsershotSample(array $fixture, string $appPath, string $html, bool $verify): array
{
    $pdfPath = prepareTempPdfPath('js-probe-browsershot');
    $startedAt = hrtime(true);
    SpatiePdf::html($html)
        ->driver('browsershot')
        ->format('a4')
        ->withBrowsershot(static function (Browsershot $browsershot) use ($appPath): void {
            $browsershot
                ->setNodeBinary('/opt/homebrew/bin/node')
                ->setNpmBinary('/opt/homebrew/bin/npm')
                ->setChromePath('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
                ->setNodeModulePath($appPath.'/node_modules')
                ->setIncludePath('$PATH:/usr/local/bin:/opt/homebrew/bin')
                ->waitUntilNetworkIdle(false)
                ->waitForFunction('() => window.__CANIO_READY__ === true', null, 30000)
                ->writeOptionsToFile();
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
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'js-probe-browsershot');
    $sample['rasterCheck'] = compareAgainstGoldenIfAvailable(
        $sample['pdfRasterPath'],
        (string) $fixture['golden_pdf_raster'],
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );
    $sample['jsSignal'] = extractProbeBadgeSignal(
        $sample['pdfRasterPath'],
        (int) data_get($fixture, 'js_signal.crop_width', 420),
        (int) data_get($fixture, 'js_signal.crop_height', 120),
        (float) data_get($fixture, 'js_signal.min_dark_ratio', 0.15),
        (float) data_get($fixture, 'js_signal.min_green_ratio', 0.005),
    );
    $sample['jsExecuted'] = $sample['jsSignal']['ok'];

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function finalizeNonBrowserSample(array $fixture, string $engine, string $prefix, string $bytes, float $wallMs, bool $verify): array
{
    $sample = [
        'engine' => $engine,
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
    ];

    if (! $verify) {
        return $sample;
    }

    $pdfPath = writePdfBytesToTempFile($bytes, $prefix);
    $sample['pdfPath'] = $pdfPath;
    $sample['pdfRasterPath'] = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), $prefix);
    $sample['rasterCheck'] = compareAgainstGoldenIfAvailable(
        $sample['pdfRasterPath'],
        (string) $fixture['golden_pdf_raster'],
        (int) data_get($fixture, 'pdf_raster.sample_width', 240),
        (int) data_get($fixture, 'pdf_raster.sample_height', 240),
    );
    $sample['jsSignal'] = extractProbeBadgeSignal(
        $sample['pdfRasterPath'],
        (int) data_get($fixture, 'js_signal.crop_width', 420),
        (int) data_get($fixture, 'js_signal.crop_height', 120),
        (float) data_get($fixture, 'js_signal.min_dark_ratio', 0.15),
        (float) data_get($fixture, 'js_signal.min_green_ratio', 0.005),
    );
    $sample['jsExecuted'] = $sample['jsSignal']['ok'];

    return $sample;
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<int, array<string, mixed>>  $engines
 * @return array<string, mixed>
 */
function buildMatrixReport(array $fixture, string $goldenPdfRasterPath, array $engines, int $warmupRuns, int $iterationRuns): array
{
    foreach ($engines as $index => $engine) {
        $engines[$index]['cold']['ok'] = ($engine['cold']['ok'] ?? false) === true;
        $engines[$index]['ok'] = ($engine['cold']['ok'] ?? false) === true;
        $engines[$index]['jsExecuted'] = ($engine['cold']['jsExecuted'] ?? false) === true;
    }

    return [
        'fixture' => (string) $fixture['name'],
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
    echo "JavaScript probe package matrix\n";
    echo sprintf("- fixture: %s\n", $report['fixture']);
    echo sprintf("- warmups: %d\n", (int) ($report['warmups'] ?? 0));
    echo sprintf("- iterations: %d\n", (int) ($report['iterations'] ?? 0));
    echo sprintf("- golden_pdf_raster: %s\n", $report['goldenPdfRaster']);
    echo "\n";
    echo "| Package | Engine | Cold ms | Steady ms | Full sim | Badge dark | Badge green | JS |\n";
    echo "| --- | --- | ---: | ---: | ---: | ---: | ---: | --- |\n";

    foreach ($report['engines'] as $engine) {
        echo sprintf(
            "| %s | %s | %.2f | %.2f | %.6f | %.4f | %.4f | %s |\n",
            $engine['label'],
            $engine['engine'],
            (float) data_get($engine, 'cold.wallMs', 0),
            (float) data_get($engine, 'steady.wallMs.median', 0),
            (float) data_get($engine, 'cold.rasterCheck.similarity', 0),
            (float) data_get($engine, 'cold.jsSignal.darkRatio', 0),
            (float) data_get($engine, 'cold.jsSignal.greenRatio', 0),
            ($engine['jsExecuted'] ?? false) ? 'yes' : 'no',
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
    $runtimeStatePath = sprintf('%s/storage/app/canio/runtime-js-matrix-%d', $appPath, $runtimePort);
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
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-js-matrix-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv(sprintf('CANIO_RUNTIME_BASE_URL=%s', $runtimeBaseUrl));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv(sprintf('CANIO_RUNTIME_PORT=%d', $runtimePort));
    putenv(sprintf('CANIO_RUNTIME_STATE_PATH=%s', $runtimeStatePath));
    putenv(sprintf('CANIO_RUNTIME_LOG_PATH=%s', $appPath.'/storage/logs/canio-js-matrix-'.$runtimePort.'.log'));
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
 * @param  array<int, string>  $needles
 * @return array<string, mixed>
 */
function checkRequiredStrings(string $text, array $needles): array
{
    $missing = [];

    foreach ($needles as $needle) {
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
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkSourceStatic(string $sourceHtml, array $fixture): array
{
    $unexpected = [];

    foreach ((array) $fixture['source_absent_strings'] as $needle) {
        if (str_contains($sourceHtml, (string) $needle)) {
            $unexpected[] = $needle;
        }
    }

    return [
        'ok' => $unexpected === [],
        'unexpected' => $unexpected,
    ];
}

/**
 * @return array<string, mixed>
 */
function comparePngFiles(string $actualPath, string $goldenPath, int $sampleWidth, int $sampleHeight): array
{
    return comparePngRegion($actualPath, $goldenPath, 0, 0, $sampleWidth, $sampleHeight);
}

/**
 * @return array<string, mixed>
 */
function compareAgainstGoldenIfAvailable(string $actualPath, string $goldenPath, int $sampleWidth, int $sampleHeight): array
{
    if (! is_file($goldenPath)) {
        return [
            'dimensionsMatch' => true,
            'actualDimensions' => [0, 0],
            'goldenDimensions' => [0, 0],
            'avgChannelDelta' => 0.0,
            'changedPixelRatio' => 0.0,
            'similarity' => 1.0,
            'sampleSize' => [$sampleWidth, $sampleHeight],
        ];
    }

    return comparePngFiles($actualPath, $goldenPath, $sampleWidth, $sampleHeight);
}

/**
 * @return array<string, mixed>
 */
function comparePngRegion(string $actualPath, string $goldenPath, int $cropWidth, int $cropHeight, int $sampleWidth, int $sampleHeight): array
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

    $actualCropWidth = $cropWidth > 0 ? min($cropWidth, imagesx($actualImage)) : imagesx($actualImage);
    $actualCropHeight = $cropHeight > 0 ? min($cropHeight, imagesy($actualImage)) : imagesy($actualImage);
    $goldenCropWidth = $cropWidth > 0 ? min($cropWidth, imagesx($goldenImage)) : imagesx($goldenImage);
    $goldenCropHeight = $cropHeight > 0 ? min($cropHeight, imagesy($goldenImage)) : imagesy($goldenImage);

    imagecopyresampled($actualSample, $actualImage, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $actualCropWidth, $actualCropHeight);
    imagecopyresampled($goldenSample, $goldenImage, 0, 0, 0, 0, $sampleWidth, $sampleHeight, $goldenCropWidth, $goldenCropHeight);

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
 * @return array<string, mixed>
 */
function extractProbeBadgeSignal(string $actualPath, int $cropWidth, int $cropHeight, float $minDarkRatio, float $minGreenRatio): array
{
    $actualImage = imagecreatefrompng($actualPath);

    if ($actualImage === false) {
        throw new RuntimeException('Unable to load the PNG image for probe signal detection.');
    }

    $width = min($cropWidth, imagesx($actualImage));
    $height = min($cropHeight, imagesy($actualImage));
    $totalPixels = max(1, $width * $height);
    $darkPixels = 0;
    $greenPixels = 0;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorsforindex($actualImage, imagecolorat($actualImage, $x, $y));
            $luminance = (0.2126 * $color['red']) + (0.7152 * $color['green']) + (0.0722 * $color['blue']);

            if ($luminance < 50) {
                $darkPixels++;
            }

            if ($color['green'] > 120 && $color['green'] > $color['red'] + 40 && $color['green'] > $color['blue'] + 40) {
                $greenPixels++;
            }
        }
    }

    $darkRatio = $darkPixels / $totalPixels;
    $greenRatio = $greenPixels / $totalPixels;

    return [
        'cropSize' => [$width, $height],
        'darkRatio' => round($darkRatio, 4),
        'greenRatio' => round($greenRatio, 4),
        'ok' => $darkRatio >= $minDarkRatio && $greenRatio >= $minGreenRatio,
    ];
}

function prepareTempPdfPath(string $prefix): string
{
    $base = tempnam(sys_get_temp_dir(), $prefix.'-');

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

function findAvailablePort(int $start = 9914, int $end = 9999): int
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
