#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Oxhq\Canio\Facades\Canio;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");

    exit(1);
}

$options = getopt('', ['app::', 'json']);
$root = realpath(__DIR__.'/..');
$fixture = require __DIR__.'/javascript_probe_reference.php';
$appPath = realpath((string) ($options['app'] ?? ($root !== false ? $root.'/examples/laravel-app/app' : '')));
$asJson = array_key_exists('json', $options);

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
$result = Canio::view('pdf.javascript-probe', [
    'title' => (string) $fixture['title'],
    'probeUrl' => (string) $fixture['probe_url'],
])
    ->profile((string) $fixture['profile'])
    ->title((string) $fixture['title'])
    ->debug()
    ->watch()
    ->render();
$wallMs = elapsedMilliseconds($startedAt);

$report = buildReport($result->toArray(), $fixture, $appPath, $wallMs);

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
function buildReport(array $result, array $fixture, string $appPath, float $wallMs): array
{
    $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
    $files = is_array($artifacts['files'] ?? null) ? $artifacts['files'] : [];
    $metadata = readJsonFile(is_string($files['metadata'] ?? null) ? $files['metadata'] : null);
    $sourceHtml = readTextFile(is_string($files['sourceHtml'] ?? null) ? $files['sourceHtml'] : null);
    $domSnapshot = readTextFile(is_string($files['domSnapshot'] ?? null) ? $files['domSnapshot'] : null);

    $checks = [
        'artifacts' => checkArtifacts($files, $fixture),
        'profile' => checkProfile($result, $metadata, $fixture),
        'sourceStatic' => checkSourceStatic($sourceHtml, $fixture),
        'domMutation' => checkRequiredStrings($domSnapshot, $fixture['dom_required_strings']),
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
    $runtimeStatePath = sprintf('%s/storage/app/canio/runtime-js-probe-%d', $appPath, $runtimePort);
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
    putenv('CANIO_RUNTIME_SHARED_SECRET=canio-js-probe-secret');
    putenv(sprintf('CANIO_RUNTIME_WORKING_DIRECTORY=%s', $appPath));
    putenv(sprintf('CANIO_RUNTIME_BASE_URL=%s', $runtimeBaseUrl));
    putenv('CANIO_RUNTIME_HOST=127.0.0.1');
    putenv(sprintf('CANIO_RUNTIME_PORT=%d', $runtimePort));
    putenv(sprintf('CANIO_RUNTIME_STATE_PATH=%s', $runtimeStatePath));
    putenv(sprintf('CANIO_RUNTIME_LOG_PATH=%s', $appPath.'/storage/logs/canio-js-probe-'.$runtimePort.'.log'));
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
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function checkSourceStatic(string $sourceHtml, array $fixture): array
{
    $unexpected = [];

    foreach ($fixture['source_absent_strings'] as $needle) {
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
 * @return array<string, mixed>
 */
function readJsonFile(?string $path): array
{
    if (! is_string($path) || ! is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function readTextFile(?string $path): string
{
    if (! is_string($path) || ! is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

function elapsedMilliseconds(int $startedAt): float
{
    return (hrtime(true) - $startedAt) / 1_000_000;
}

/**
 * @param  array<string, mixed>  $report
 */
function renderReport(array $report): void
{
    echo "JavaScript probe smoke\n";
    echo sprintf("- fixture: %s\n", $report['fixture']);
    echo sprintf("- app: %s\n", $report['app']);
    echo sprintf("- profile: %s\n", $report['profile']);
    echo sprintf("- wallMs: %.2f\n", $report['wallMs']);
    echo sprintf("- engineMs: %.2f\n", $report['engineMs']);
    echo sprintf("- pdfBytes: %d\n", $report['pdfBytes']);
    echo sprintf("- artifactDirectory: %s\n", $report['artifactDirectory'] ?? 'n/a');
    echo "\n";

    foreach ($report['checks'] as $name => $check) {
        echo sprintf("[%s] %s\n", ($check['ok'] ?? false) ? 'ok' : 'fail', $name);
    }
}

function findAvailablePort(): int
{
    for ($port = 42000; $port < 47000; $port++) {
        $server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
        if ($server !== false) {
            fclose($server);

            return $port;
        }
    }

    throw new RuntimeException('Unable to find an available port for the Stagehand runtime.');
}

function deleteDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory.'/'.$item;

        if (is_dir($path) && ! is_link($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}
