#!/usr/bin/env php
<?php

declare(strict_types=1);

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

$startedAt = hrtime(true);
$result = Canio::view('pdf.invoice', ['invoice' => $fixture['invoice']])
    ->profile((string) $fixture['profile'])
    ->title((string) $fixture['title'])
    ->debug()
    ->watch()
    ->thumbnail()
    ->render();
$wallMs = elapsedMilliseconds($startedAt);

$report = buildReport($result->toArray(), $fixture, $appPath, $wallMs, $updateGolden);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    renderReport($report);
}

exit($report['ok'] ? 0 : 1);

/**
 * @param  array<string, mixed>  $result
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function buildReport(array $result, array $fixture, string $appPath, float $wallMs, bool $updateGolden): array
{
    $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : [];
    $metadata = readJsonFile(is_string($files['metadata'] ?? null) ? $files['metadata'] : null);
    $sourceHtml = readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null);
    $domSnapshot = readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null);

    if ($updateGolden && isset($files['pageScreenshot']) && is_string($files['pageScreenshot'])) {
        $goldenPath = (string) $fixture['golden_screenshot'];
        ensureDirectory(dirname($goldenPath));

        if (! copy($files['pageScreenshot'], $goldenPath)) {
            throw new RuntimeException(sprintf('Unable to update golden screenshot at %s.', $goldenPath));
        }
    }

    $artifactCheck = checkArtifacts($files, $fixture);
    $textCheck = checkRequiredStrings($sourceHtml.$domSnapshot, $fixture);
    $profileCheck = checkProfile($result, $metadata, $fixture);
    $pdfBytesCheck = checkPdfBytes($result, $fixture);
    $screenshotCheck = checkScreenshot($files, $fixture);

    $checks = [
        'artifacts' => $artifactCheck,
        'requiredText' => $textCheck,
        'profile' => $profileCheck,
        'pdfBytes' => $pdfBytesCheck,
        'screenshot' => $screenshotCheck,
    ];

    $ok = true;

    foreach ($checks as $check) {
        if (($check['ok'] ?? false) !== true) {
            $ok = false;
            break;
        }
    }

    return [
        'ok' => $ok,
        'fixture' => (string) $fixture['name'],
        'app' => $appPath,
        'profile' => (string) ($result['profile'] ?? $metadata['profile'] ?? $fixture['profile']),
        'wallMs' => round($wallMs, 2),
        'engineMs' => (float) data_get($result, 'timings.totalMs', 0),
        'timings' => is_array($result['timings'] ?? null) ? $result['timings'] : [],
        'pdfBytes' => (int) data_get($result, 'pdf.bytes', 0),
        'artifactId' => is_string($artifacts['id'] ?? null) ? $artifacts['id'] : null,
        'artifactDirectory' => is_string($artifacts['directory'] ?? null) ? $artifacts['directory'] : null,
        'goldenScreenshot' => (string) $fixture['golden_screenshot'],
        'checks' => $checks,
    ];
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
    $runtimeStatePath = sprintf('%s/storage/app/canio/runtime-fidelity-%d', $appPath, $runtimePort);
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
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-fidelity-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv(sprintf('CANIO_RUNTIME_BASE_URL=%s', $runtimeBaseUrl));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv(sprintf('CANIO_RUNTIME_PORT=%d', $runtimePort));
    putenv(sprintf('CANIO_RUNTIME_STATE_PATH=%s', $runtimeStatePath));
    putenv(sprintf('CANIO_RUNTIME_LOG_PATH=%s', $appPath.'/storage/logs/canio-fidelity-'.$runtimePort.'.log'));
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
 * @param  array<string, mixed>  $result
 * @param  array<string, mixed>  $metadata
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkProfile(array $result, array $metadata, array $fixture): array
{
    $expected = (string) $fixture['profile'];
    $actual = (string) ($result['profile'] ?? $metadata['profile'] ?? '');

    return [
        'ok' => $actual === $expected,
        'expected' => $expected,
        'actual' => $actual,
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
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkScreenshot(array $files, array $fixture): array
{
    $actualPath = $files['pageScreenshot'] ?? null;
    $goldenPath = $fixture['golden_screenshot'] ?? null;

    if (! is_string($actualPath) || ! is_file($actualPath)) {
        return [
            'ok' => false,
            'reason' => 'Rendered screenshot is missing.',
        ];
    }

    if (! is_string($goldenPath) || ! is_file($goldenPath)) {
        return [
            'ok' => false,
            'reason' => 'Golden screenshot is missing. Re-run with --update-golden to create it.',
        ];
    }

    $comparison = comparePngFiles(
        $actualPath,
        $goldenPath,
        (int) data_get($fixture, 'screenshot.sample_width', 160),
        (int) data_get($fixture, 'screenshot.sample_height', 160),
    );
    $comparison['expectedPath'] = $goldenPath;
    $comparison['actualPath'] = $actualPath;
    $comparison['ok'] = $comparison['dimensionsMatch']
        && $comparison['similarity'] >= (float) data_get($fixture, 'screenshot.min_similarity', 0.995)
        && $comparison['changedPixelRatio'] <= (float) data_get($fixture, 'screenshot.max_changed_ratio', 0.02);

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

/**
 * @param  array<string, mixed>  $report
 */
function renderReport(array $report): void
{
    echo "Example invoice fidelity\n";
    echo sprintf("- status: %s\n", $report['ok'] ? 'PASS' : 'FAIL');
    echo sprintf("- app: %s\n", $report['app']);
    echo sprintf("- wall_ms: %.2f\n", $report['wallMs']);
    echo sprintf("- engine_ms: %.2f\n", $report['engineMs']);
    echo sprintf("- pdf_bytes: %d\n", $report['pdfBytes']);
    echo sprintf("- artifact_id: %s\n", $report['artifactId'] ?? 'n/a');
    echo sprintf("- golden: %s\n", $report['goldenScreenshot']);

    foreach ($report['checks'] as $name => $check) {
        echo sprintf("- %s: %s\n", $name, ($check['ok'] ?? false) ? 'ok' : 'fail');

        if ($name === 'artifacts' && ($check['ok'] ?? false) !== true) {
            echo sprintf("  missing: %s\n", implode(', ', $check['missing']));
        }

        if ($name === 'requiredText' && ($check['ok'] ?? false) !== true) {
            echo sprintf("  missing: %s\n", implode(' | ', $check['missing']));
        }

        if ($name === 'profile') {
            echo sprintf("  expected=%s actual=%s\n", $check['expected'], $check['actual']);
        }

        if ($name === 'pdfBytes') {
            echo sprintf("  actual=%d expected=%d range=%d-%d\n", $check['actual'], $check['expected'], $check['min'], $check['max']);
        }

        if ($name === 'screenshot') {
            if (isset($check['reason'])) {
                echo sprintf("  reason=%s\n", $check['reason']);
            } else {
                echo sprintf(
                    "  similarity=%0.6f avg_channel_delta=%0.4f changed_ratio=%0.6f dims=%dx%d\n",
                    $check['similarity'],
                    $check['avgChannelDelta'],
                    $check['changedPixelRatio'],
                    $check['actualDimensions'][0],
                    $check['actualDimensions'][1],
                );
            }
        }
    }
}

/**
 * @return array<string, mixed>
 */
function readJsonFile(?string $path): array
{
    if ($path === null || ! is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if (! is_string($contents) || $contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? $decoded : [];
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

function findAvailablePort(int $start = 9614, int $end = 9699): int
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
