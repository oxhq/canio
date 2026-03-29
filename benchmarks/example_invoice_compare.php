#!/usr/bin/env php
<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;
use Oxhq\Canio\Facades\Canio;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");

    exit(1);
}

$options = getopt('', ['app::', 'json', 'update-golden']);
$root = realpath(__DIR__.'/..');
$fixture = require __DIR__.'/example_invoice_reference.php';
$appPath = realpath((string) ($options['app'] ?? ($root !== false ? $root.'/examples/laravel-app/app' : '')));
$asJson = array_key_exists('json', $options);
$updateGolden = array_key_exists('update-golden', $options);

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

if (! class_exists(Canio::class)) {
    fwrite(STDERR, "Canio facade is not available in the example app.\n");

    exit(1);
}

if (! class_exists(Pdf::class)) {
    fwrite(STDERR, "Dompdf facade is not available in the example app. Install barryvdh/laravel-dompdf first.\n");

    exit(1);
}

$canio = renderCanioEngine($fixture);
$goldenPdfRasterPath = (string) $fixture['golden_pdf_raster'];

if ($updateGolden || ! is_file($goldenPdfRasterPath)) {
    ensureDirectory(dirname($goldenPdfRasterPath));

    if (! copy($canio['pdfRasterPath'], $goldenPdfRasterPath)) {
        throw new RuntimeException(sprintf('Unable to update the PDF raster golden at %s.', $goldenPdfRasterPath));
    }
}

$dompdf = renderDompdfEngine($fixture);
$comparison = buildComparison($fixture, $goldenPdfRasterPath, $canio, $dompdf);

if ($asJson) {
    echo json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    renderComparison($comparison);
}

exit(($comparison['canio']['ok'] && $comparison['dompdf']['ok']) ? 0 : 1);

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderCanioEngine(array $fixture): array
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
    $pdfPath = is_string($files['pdf'] ?? null) ? $files['pdf'] : writePdfBytesToTempFile($result->pdfBytes(), 'canio');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'canio');

    return [
        'engine' => 'canio',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($attributes, 'timings.totalMs', 0),
        'timings' => is_array($attributes['timings'] ?? null) ? $attributes['timings'] : [],
        'pdfBytes' => (int) data_get($attributes, 'pdf.bytes', 0),
        'pdfPath' => $pdfPath,
        'pdfRasterPath' => $pdfRasterPath,
        'pageCount' => readPdfPageCount($pdfPath),
        'artifactId' => is_string($artifacts['id'] ?? null) ? $artifacts['id'] : null,
        'artifactDirectory' => is_string($artifacts['directory'] ?? null) ? $artifacts['directory'] : null,
        'artifactFiles' => $files,
        'textCheck' => checkRequiredStrings(
            readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null)
            .readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null),
            $fixture,
        ),
        'artifactCheck' => checkArtifacts($files, $fixture),
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function renderDompdfEngine(array $fixture): array
{
    $html = view('pdf.invoice', ['invoice' => $fixture['invoice']])->render();
    $startedAt = hrtime(true);
    $bytes = Pdf::loadHTML($html)
        ->setPaper('a4')
        ->output();
    $wallMs = elapsedMilliseconds($startedAt);
    $pdfPath = writePdfBytesToTempFile($bytes, 'dompdf');
    $pdfRasterPath = rasterizePdfToPng($pdfPath, (int) data_get($fixture, 'pdf_raster.thumbnail_size', 1440), 'dompdf');

    return [
        'engine' => 'dompdf',
        'ok' => true,
        'wallMs' => round($wallMs, 2),
        'engineMs' => null,
        'pdfBytes' => strlen($bytes),
        'pdfPath' => $pdfPath,
        'pdfRasterPath' => $pdfRasterPath,
        'pageCount' => readPdfPageCount($pdfPath),
        'textCheck' => checkRequiredStrings($html, $fixture),
    ];
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $canio
 * @param  array<string, mixed>  $dompdf
 * @return array<string, mixed>
 */
function buildComparison(array $fixture, string $goldenPdfRasterPath, array $canio, array $dompdf): array
{
    $sampleWidth = (int) data_get($fixture, 'pdf_raster.sample_width', 240);
    $sampleHeight = (int) data_get($fixture, 'pdf_raster.sample_height', 240);

    $canioRaster = comparePngFiles($canio['pdfRasterPath'], $goldenPdfRasterPath, $sampleWidth, $sampleHeight);
    $canioRaster['expectedPath'] = $goldenPdfRasterPath;
    $canioRaster['actualPath'] = $canio['pdfRasterPath'];
    $dompdfRaster = comparePngFiles($dompdf['pdfRasterPath'], $goldenPdfRasterPath, $sampleWidth, $sampleHeight);
    $dompdfRaster['expectedPath'] = $goldenPdfRasterPath;
    $dompdfRaster['actualPath'] = $dompdf['pdfRasterPath'];

    $canio['rasterCheck'] = $canioRaster;
    $dompdf['rasterCheck'] = $dompdfRaster;

    return [
        'fixture' => (string) $fixture['name'],
        'goldenPdfRaster' => $goldenPdfRasterPath,
        'canio' => $canio,
        'dompdf' => $dompdf,
        'summary' => [
            'wallMsDelta' => round((float) $dompdf['wallMs'] - (float) $canio['wallMs'], 2),
            'wallSpeedup' => speedup((float) $dompdf['wallMs'], (float) $canio['wallMs']),
            'similarityDelta' => round((float) $canioRaster['similarity'] - (float) $dompdfRaster['similarity'], 6),
            'changedRatioDelta' => round((float) $dompdfRaster['changedPixelRatio'] - (float) $canioRaster['changedPixelRatio'], 6),
            'pdfBytesDelta' => (int) $dompdf['pdfBytes'] - (int) $canio['pdfBytes'],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $comparison
 */
function renderComparison(array $comparison): void
{
    echo "Example invoice engine comparison\n";
    echo sprintf("- fixture: %s\n", $comparison['fixture']);
    echo sprintf("- golden_pdf_raster: %s\n", $comparison['goldenPdfRaster']);
    echo "\n";
    echo "| Engine | Wall ms | Engine ms | PDF bytes | Pages | Similarity | Changed ratio |\n";
    echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
    echo sprintf(
        "| canio | %.2f | %.2f | %d | %s | %.6f | %.6f |\n",
        $comparison['canio']['wallMs'],
        $comparison['canio']['engineMs'],
        $comparison['canio']['pdfBytes'],
        formatPageCount($comparison['canio']['pageCount']),
        $comparison['canio']['rasterCheck']['similarity'],
        $comparison['canio']['rasterCheck']['changedPixelRatio'],
    );
    echo sprintf(
        "| dompdf | %.2f | %s | %d | %s | %.6f | %.6f |\n",
        $comparison['dompdf']['wallMs'],
        'n/a',
        $comparison['dompdf']['pdfBytes'],
        formatPageCount($comparison['dompdf']['pageCount']),
        $comparison['dompdf']['rasterCheck']['similarity'],
        $comparison['dompdf']['rasterCheck']['changedPixelRatio'],
    );
    echo "\n";
    echo sprintf("- wall_ms_delta_dompdf_minus_canio: %.2f\n", $comparison['summary']['wallMsDelta']);
    echo sprintf("- canio_speedup_vs_dompdf: %.2fx\n", $comparison['summary']['wallSpeedup']);
    echo sprintf("- similarity_delta_canio_minus_dompdf: %.6f\n", $comparison['summary']['similarityDelta']);
    echo sprintf("- changed_ratio_delta_dompdf_minus_canio: %.6f\n", $comparison['summary']['changedRatioDelta']);
    echo sprintf("- pdf_bytes_delta_dompdf_minus_canio: %d\n", $comparison['summary']['pdfBytesDelta']);
    echo sprintf(
        "- canio_checks: artifacts=%s text=%s\n",
        $comparison['canio']['artifactCheck']['ok'] ? 'ok' : 'fail',
        $comparison['canio']['textCheck']['ok'] ? 'ok' : 'fail',
    );
    echo sprintf(
        "- dompdf_checks: text=%s\n",
        $comparison['dompdf']['textCheck']['ok'] ? 'ok' : 'fail',
    );
}

function configureEnvironment(string $root, string $appPath): void
{
    $stagehandBinary = realpath($root.'/bin/stagehand');

    if ($stagehandBinary === false) {
        fwrite(STDERR, "Unable to resolve bin/stagehand. Run ./scripts/build-stagehand.sh first.\n");

        exit(1);
    }

    $runtimePort = findAvailablePort();
    $runtimeBaseUrl = sprintf('http://127.0.0.1:%d', $runtimePort);
    $runtimeStatePath = sprintf('%s/storage/app/canio/runtime-compare-%d', $appPath, $runtimePort);
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
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-compare-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv(sprintf('CANIO_RUNTIME_BASE_URL=%s', $runtimeBaseUrl));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv(sprintf('CANIO_RUNTIME_PORT=%d', $runtimePort));
    putenv(sprintf('CANIO_RUNTIME_STATE_PATH=%s', $runtimeStatePath));
    putenv(sprintf('CANIO_RUNTIME_LOG_PATH=%s', $appPath.'/storage/logs/canio-compare-'.$runtimePort.'.log'));
    putenv(sprintf('CANIO_CHROMIUM_USER_DATA_DIR=%s', $chromiumUserDataDir));
    putenv('CANIO_RUNTIME_BROWSER_POOL_SIZE=1');
    putenv('CANIO_RUNTIME_BROWSER_POOL_WARM=1');
    putenv('CANIO_RUNTIME_BROWSER_QUEUE_DEPTH=4');
    putenv('CANIO_RUNTIME_BROWSER_ACQUIRE_TIMEOUT=30');
    putenv('CANIO_RUNTIME_JOB_BACKEND=memory');
    putenv('CANIO_RUNTIME_JOB_WORKERS=1');
    putenv('CANIO_RUNTIME_JOB_QUEUE_DEPTH=8');
    putenv(sprintf('CANIO_PROFILES_PATH=%s', $root.'/resources/profiles'));
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

function writePdfBytesToTempFile(string $bytes, string $prefix): string
{
    $base = tempnam(sys_get_temp_dir(), $prefix.'-invoice-');

    if ($base === false) {
        throw new RuntimeException('Unable to allocate a temporary PDF file.');
    }

    $path = $base.'.pdf';

    if (! rename($base, $path)) {
        throw new RuntimeException(sprintf('Unable to prepare PDF path %s.', $path));
    }

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

function readPdfPageCount(string $pdfPath): ?int
{
    $command = sprintf('/usr/bin/mdls -raw -name kMDItemNumberOfPages %s 2>/dev/null', escapeshellarg($pdfPath));
    $output = trim((string) shell_exec($command));

    return ctype_digit($output) ? (int) $output : null;
}

function formatPageCount(?int $pageCount): string
{
    return $pageCount === null ? 'n/a' : (string) $pageCount;
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

function speedup(float $baseline, float $candidate): float
{
    if ($candidate <= 0.0) {
        return 0.0;
    }

    return round($baseline / $candidate, 2);
}

function findAvailablePort(int $start = 9714, int $end = 9799): int
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
