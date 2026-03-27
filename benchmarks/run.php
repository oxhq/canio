<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$defaults = [
    'scenario' => 'render-pool',
    'config' => $root.'/benchmarks/scenarios.json',
    'binary' => $root.'/bin/stagehand',
    'base-url' => 'http://127.0.0.1:9571',
    'fixture-port' => 9771,
    'stagehand-port' => 9571,
    'state-dir' => null,
    'mode' => null,
    'count' => null,
    'concurrency' => null,
    'delay-ms' => null,
    'browser-pool-size' => null,
    'browser-pool-warm' => null,
    'browser-queue-depth' => null,
    'job-backend' => null,
    'job-workers' => null,
    'job-queue-depth' => null,
    'job-lease-timeout' => null,
    'job-heartbeat-interval' => null,
    'dead-letter-ttl-days' => null,
    'redis-host' => null,
    'redis-port' => null,
    'redis-password' => null,
    'redis-db' => null,
    'redis-queue-key' => null,
    'redis-block-timeout' => null,
    'json' => false,
    'help' => false,
];

$longopts = ['json', 'help'];
foreach (array_keys(array_diff_key($defaults, ['json' => true, 'help' => true])) as $key) {
    $longopts[] = $key.':';
}

$options = getopt('', $longopts);
$options['json'] = array_key_exists('json', $options);
$options['help'] = array_key_exists('help', $options);

foreach ($defaults as $key => $value) {
    if ($key === 'json' || $key === 'help') {
        continue;
    }

    if (array_key_exists($key, $options) && $options[$key] !== false) {
        continue;
    }

    $envKey = 'CANIO_BENCH_'.strtoupper(str_replace('-', '_', $key));
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        $options[$key] = $envValue;
        continue;
    }

    $options[$key] = $value;
}

if ($options['help']) {
    fwrite(STDOUT, <<<TXT
Usage:
  php benchmarks/run.php [--scenario=name] [--mode=render|jobs] [--count=n] [--concurrency=n]

TXT);
    exit(0);
}

$scenarios = loadScenarios((string) $options['config']);
$scenarioName = (string) ($options['scenario'] ?: $defaults['scenario']);
if (! array_key_exists($scenarioName, $scenarios)) {
    fwrite(STDERR, "Unknown scenario {$scenarioName}\n");
    exit(1);
}

$scenario = $scenarios[$scenarioName];
$config = array_merge($scenario, array_filter([
    'mode' => $options['mode'],
    'count' => normalizeNullableInt($options['count']),
    'concurrency' => normalizeNullableInt($options['concurrency']),
    'delayMs' => normalizeNullableInt($options['delay-ms']),
    'browserPoolSize' => normalizeNullableInt($options['browser-pool-size']),
    'browserPoolWarm' => normalizeNullableInt($options['browser-pool-warm']),
    'browserQueueDepth' => normalizeNullableInt($options['browser-queue-depth']),
    'jobBackend' => normalizeNullableString($options['job-backend']),
    'jobWorkers' => normalizeNullableInt($options['job-workers']),
    'jobQueueDepth' => normalizeNullableInt($options['job-queue-depth']),
    'jobLeaseTimeout' => normalizeNullableInt($options['job-lease-timeout']),
    'jobHeartbeatInterval' => normalizeNullableInt($options['job-heartbeat-interval']),
    'deadLetterTtlDays' => normalizeNullableInt($options['dead-letter-ttl-days']),
    'redisHost' => normalizeNullableString($options['redis-host']),
    'redisPort' => normalizeNullableInt($options['redis-port']),
    'redisPassword' => normalizeNullableString($options['redis-password']),
    'redisDb' => normalizeNullableInt($options['redis-db']),
    'redisQueueKey' => normalizeNullableString($options['redis-queue-key']),
    'redisBlockTimeout' => normalizeNullableInt($options['redis-block-timeout']),
], static fn (mixed $value): bool => $value !== null));

if (! function_exists('curl_multi_init')) {
    fwrite(STDERR, "PHP curl extension is required for the benchmark runner.\n");
    exit(1);
}

$stateDir = (string) ($options['state-dir'] ?: sys_get_temp_dir().'/canio-bench-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)));
if (! is_dir($stateDir) && ! mkdir($stateDir, 0o755, true) && ! is_dir($stateDir)) {
    fwrite(STDERR, "Unable to create state directory {$stateDir}\n");
    exit(1);
}

$fixturePort = (int) $options['fixture-port'];
$stagehandPort = (int) $options['stagehand-port'];
$fixtureUrl = "http://127.0.0.1:{$fixturePort}/index.php";
$baseUrl = rtrim((string) $options['base-url'], '/');

$fixtureLog = $stateDir.'/fixture.log';
$stagehandLog = $stateDir.'/stagehand.log';

$fixtureProc = startBackgroundProcess([
    'php',
    '-S',
    "127.0.0.1:{$fixturePort}",
    '-t',
    $root.'/benchmarks/fixture',
], $root, $fixtureLog);

try {
    waitForHttp("http://127.0.0.1:{$fixturePort}/index.php?delay=1", 10);

    $binary = (string) $options['binary'];
    if (! is_file($binary)) {
        throw new RuntimeException("Stagehand binary not found at {$binary}");
    }

    $stagehandArgs = [
        $binary,
        'serve',
        '--host', '127.0.0.1',
        '--port', (string) $stagehandPort,
        '--state-dir', $stateDir,
        '--user-data-dir', $stateDir.'/chromium',
        '--browser-pool-size', (string) ($config['browserPoolSize'] ?? 2),
        '--browser-pool-warm', (string) ($config['browserPoolWarm'] ?? 1),
        '--browser-queue-depth', (string) ($config['browserQueueDepth'] ?? 16),
        '--job-backend', (string) ($config['jobBackend'] ?? 'memory'),
        '--job-workers', (string) ($config['jobWorkers'] ?? 2),
        '--job-queue-depth', (string) ($config['jobQueueDepth'] ?? 64),
        '--job-lease-timeout', (string) ($config['jobLeaseTimeout'] ?? 45),
        '--job-heartbeat-interval', (string) ($config['jobHeartbeatInterval'] ?? 10),
        '--job-dead-letter-ttl-days', (string) ($config['deadLetterTtlDays'] ?? 30),
    ];

    if (($config['jobBackend'] ?? 'memory') === 'redis') {
        $stagehandArgs = array_merge($stagehandArgs, [
            '--job-redis-host', (string) ($config['redisHost'] ?? '127.0.0.1'),
            '--job-redis-port', (string) ($config['redisPort'] ?? 6379),
            '--job-redis-password', (string) ($config['redisPassword'] ?? ''),
            '--job-redis-db', (string) ($config['redisDb'] ?? 0),
            '--job-redis-queue-key', (string) ($config['redisQueueKey'] ?? 'canio:bench:jobs'),
            '--job-redis-block-timeout', (string) ($config['redisBlockTimeout'] ?? 1),
        ]);
    }

    $stagehandProc = startBackgroundProcess($stagehandArgs, $root, $stagehandLog);

    try {
        waitForHttp($baseUrl.'/healthz', 20);

        $mode = (string) ($config['mode'] ?? 'render');
        $count = max(1, (int) ($config['count'] ?? 24));
        $concurrency = max(1, (int) ($config['concurrency'] ?? 4));
        $delayMs = max(0, (int) ($config['delayMs'] ?? 250));

        $sourceUrl = $fixtureUrl.'?'.http_build_query([
            'delay' => $delayMs,
            'title' => 'Canio benchmark '.$scenarioName,
        ]);

        $summary = match ($mode) {
            'render' => runRenderBenchmark($baseUrl, $sourceUrl, $count, $concurrency),
            'jobs' => runJobBenchmark($baseUrl, $sourceUrl, $count, $concurrency, (string) ($config['jobBackend'] ?? 'memory'), (string) ($config['redisQueueKey'] ?? 'canio:bench:jobs')),
            default => throw new RuntimeException("Unsupported benchmark mode {$mode}"),
        };

        $result = [
            'scenario' => $scenarioName,
            'mode' => $mode,
            'stateDir' => $stateDir,
            'config' => $config,
            'summary' => $summary,
            'startedAt' => gmdate(DATE_RFC3339),
        ];

        if ($options['json']) {
            fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } else {
            fwrite(STDOUT, formatSummary($result).PHP_EOL);
        }
    } finally {
        stopProcess($stagehandProc);
    }
} finally {
    stopProcess($fixtureProc);
}

exit(0);

function loadScenarios(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Unable to read scenarios from {$path}");
    }

    $decoded = json_decode($raw, true);
    if (! is_array($decoded)) {
        throw new RuntimeException("Unable to decode scenarios from {$path}");
    }

    return $decoded;
}

function normalizeNullableInt(mixed $value): ?int
{
    if ($value === null || $value === '' || $value === false) {
        return null;
    }

    return (int) $value;
}

function normalizeNullableString(mixed $value): ?string
{
    if ($value === null || $value === '' || $value === false) {
        return null;
    }

    return (string) $value;
}

/**
 * @param  array<int, string>  $command
 */
function startBackgroundProcess(array $command, string $cwd, string $logFile): mixed
{
    $logHandle = fopen($logFile, 'ab');
    if ($logHandle === false) {
        throw new RuntimeException("Unable to open log file {$logFile}");
    }

    $spec = [
        0 => ['file', '/dev/null', 'r'],
        1 => $logHandle,
        2 => $logHandle,
    ];

    $proc = proc_open(array_to_command($command), $spec, $pipes, $cwd);
    if (! is_resource($proc)) {
        fclose($logHandle);
        throw new RuntimeException('Unable to start background process');
    }

    return (object) [
        'proc' => $proc,
        'log' => $logHandle,
    ];
}

function array_to_command(array $command): string
{
    return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
}

function stopProcess(mixed $handle): void
{
    if (! is_object($handle) || ! isset($handle->proc)) {
        return;
    }

    if (is_resource($handle->proc)) {
        @proc_terminate($handle->proc);
        @proc_close($handle->proc);
    }

    if (isset($handle->log) && is_resource($handle->log)) {
        fclose($handle->log);
    }
}

function waitForHttp(string $url, int $timeoutSeconds): void
{
    $deadline = time() + $timeoutSeconds;

    while (time() <= $deadline) {
        $response = httpRequest('GET', $url, null, 2);
        if ($response['status'] >= 200 && $response['status'] < 500) {
            return;
        }

        usleep(250000);
    }

    throw new RuntimeException("Timed out waiting for {$url}");
}

function runRenderBenchmark(string $baseUrl, string $sourceUrl, int $count, int $concurrency): array
{
    $startedAt = microtime(true);
    $requests = [];
    for ($i = 0; $i < $count; $i++) {
        $requests[] = buildRenderRequest($sourceUrl, 'render-'.$i);
    }

    $results = executeBatches($baseUrl, $requests, $concurrency);
    $latencies = array_column($results, 'durationMs');
    $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['status'] ?? 0) >= 400));
    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

    return [
        'count' => $count,
        'concurrency' => $concurrency,
        'failures' => count($failures),
        'elapsedMs' => $elapsedMs,
        'throughputPerSecond' => round($count / max(0.001, $elapsedMs / 1000), 2),
        'latencyMs' => summarizeNumericArray($latencies),
        'sample' => array_map('compactRenderResult', array_slice($results, 0, 3)),
    ];
}

function runJobBenchmark(string $baseUrl, string $sourceUrl, int $count, int $concurrency, string $backend, string $queueKey): array
{
    $benchmarkStartedAt = microtime(true);
    $submitRequests = [];
    for ($i = 0; $i < $count; $i++) {
        $submitRequests[] = buildJobRequest($sourceUrl, 'job-'.$i, $backend, $queueKey);
    }

    $submitted = executeBatches($baseUrl, $submitRequests, $concurrency);
    $submissionLatencies = array_column($submitted, 'durationMs');
    $jobs = [];
    $submitFailures = 0;
    foreach ($submitted as $index => $result) {
        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            $submitFailures++;
            continue;
        }

        $job = $result['json'] ?? [];
        if (! is_array($job) || ! isset($job['id'])) {
            $submitFailures++;
            continue;
        }

        $jobs[] = [
            'id' => (string) $job['id'],
            'status' => (string) ($job['status'] ?? 'unknown'),
            'submittedAt' => (string) ($job['submittedAt'] ?? ''),
        ];
    }

    $completed = pollJobs($baseUrl, $jobs, $concurrency);
    $queueWait = [];
    $execution = [];
    $turnaround = [];
    $failures = 0;

    foreach ($completed as $job) {
        if (($job['status'] ?? '') === 'failed') {
            $failures++;
        }

        $submittedAt = parseIsoTime((string) ($job['submittedAt'] ?? ''));
        $startedAtValue = parseIsoTime((string) ($job['startedAt'] ?? ''));
        $completedAt = parseIsoTime((string) ($job['completedAt'] ?? ''));

        if ($submittedAt !== null && $startedAtValue !== null) {
            $queueWait[] = max(0, (int) round(($startedAtValue - $submittedAt) * 1000));
        }
        if ($startedAtValue !== null && $completedAt !== null) {
            $execution[] = max(0, (int) round(($completedAt - $startedAtValue) * 1000));
        }
        if ($submittedAt !== null && $completedAt !== null) {
            $turnaround[] = max(0, (int) round(($completedAt - $submittedAt) * 1000));
        }
    }

    return [
        'count' => $count,
        'acceptedCount' => count($jobs),
        'submitFailures' => $submitFailures,
        'completedCount' => count($completed),
        'concurrency' => $concurrency,
        'failures' => $submitFailures + $failures,
        'elapsedMs' => (int) round((microtime(true) - $benchmarkStartedAt) * 1000),
        'submissionMs' => summarizeNumericArray($submissionLatencies),
        'queueWaitMs' => summarizeNumericArray($queueWait),
        'executionMs' => summarizeNumericArray($execution),
        'turnaroundMs' => summarizeNumericArray($turnaround),
        'sample' => array_map('compactJobResult', array_slice($completed, 0, 3)),
    ];
}

function buildRenderRequest(string $sourceUrl, string $requestId): array
{
    return [
        'method' => 'POST',
        'url' => '/v1/renders',
        'payload' => [
            'contractVersion' => 'canio.stagehand.render-spec.v1',
            'requestId' => $requestId,
            'source' => [
                'type' => 'url',
                'payload' => [
                    'url' => $sourceUrl,
                ],
            ],
            'presentation' => [
                'format' => 'a4',
                'background' => true,
            ],
            'execution' => [
                'timeout' => 90,
            ],
            'output' => [
                'mode' => 'inline',
                'fileName' => $requestId.'.pdf',
            ],
        ],
    ];
}

function buildJobRequest(string $sourceUrl, string $requestId, string $backend, string $queueKey): array
{
    $queue = [
        'enabled' => true,
        'queue' => 'bench',
    ];

    if ($backend === 'redis') {
        $queue['connection'] = 'redis';
        $queue['queue'] = 'bench';
    }

    return [
        'method' => 'POST',
        'url' => '/v1/jobs',
        'payload' => [
            'contractVersion' => 'canio.stagehand.render-spec.v1',
            'requestId' => $requestId,
            'source' => [
                'type' => 'url',
                'payload' => [
                    'url' => $sourceUrl,
                ],
            ],
            'presentation' => [
                'format' => 'a4',
                'background' => true,
            ],
            'execution' => [
                'timeout' => 90,
                'retries' => 0,
            ],
            'queue' => $queue,
            'output' => [
                'mode' => 'inline',
                'fileName' => $requestId.'.pdf',
            ],
        ],
    ];
}

function executeBatches(string $baseUrl, array $requests, int $concurrency): array
{
    $results = [];
    $chunks = array_chunk($requests, max(1, $concurrency));
    foreach ($chunks as $chunk) {
        $results = array_merge($results, executeCurlMultiBatch($baseUrl, $chunk));
    }

    return $results;
}

function executeCurlMultiBatch(string $baseUrl, array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $index => $request) {
        $handles[$index] = createCurlHandle($baseUrl, $request);
        curl_multi_add_handle($multi, $handles[$index]['handle']);
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $entry) {
        $handle = $entry['handle'];
        $body = (string) curl_multi_getcontent($handle);
        $info = curl_getinfo($handle);
        $results[] = [
            'status' => (int) ($info['http_code'] ?? 0),
            'durationMs' => (int) round(($info['total_time'] ?? 0.0) * 1000),
            'json' => json_decode($body, true),
            'bodyBytes' => strlen($body),
            'requestId' => $entry['requestId'],
            'url' => $entry['url'],
        ];
        curl_multi_remove_handle($multi, $handle);
    }

    curl_multi_close($multi);

    return $results;
}

function createCurlHandle(string $baseUrl, array $request): array
{
    $url = (string) $request['url'];
    $method = strtoupper((string) ($request['method'] ?? 'GET'));
    $payload = $request['payload'] ?? null;
    $handle = curl_init();
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize curl');
    }

    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_URL => rtrim($baseUrl, '/').$url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
    }

    curl_setopt_array($handle, $options);

    return [
        'handle' => $handle,
        'requestId' => (string) ($payload['requestId'] ?? ''),
        'url' => $options[CURLOPT_URL],
    ];
}

function httpRequest(string $method, string $url, ?array $payload, int $timeoutSeconds): array
{
    $handle = curl_init();
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize curl');
    }

    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $info = curl_getinfo($handle);
    $error = curl_error($handle);

    return [
        'status' => (int) ($info['http_code'] ?? 0),
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function pollJobs(string $baseUrl, array $jobs, int $concurrency): array
{
    $pending = [];
    foreach ($jobs as $job) {
        $jobID = trim((string) ($job['id'] ?? ''));
        if ($jobID === '') {
            continue;
        }

        $pending[$jobID] = [
            'id' => $jobID,
            'submittedAt' => (string) ($job['submittedAt'] ?? ''),
        ];
    }

    $done = [];

    while ($pending !== []) {
        $batch = array_slice(array_values($pending), 0, max(1, $concurrency));

        $requests = array_map(static function (array $job) use ($baseUrl): array {
            return [
                'method' => 'GET',
                'url' => '/v1/jobs/'.rawurlencode($job['id']),
                'payload' => null,
                'baseUrl' => $baseUrl,
                'job' => $job,
            ];
        }, $batch);

        $results = executeBatchWithBaseUrl($baseUrl, $requests);
        foreach ($results as $index => $result) {
            $requestedJob = $batch[$index] ?? [];
            $requestedJobID = trim((string) ($requestedJob['id'] ?? ''));
            $json = $result['json'] ?? [];
            if (! is_array($json)) {
                if ($requestedJobID !== '') {
                    $done[$requestedJobID] = [
                        'id' => $requestedJobID,
                        'status' => 'failed',
                        'error' => 'Benchmark runner received a non-JSON job response.',
                        'submittedAt' => (string) ($requestedJob['submittedAt'] ?? ''),
                        'startedAt' => '',
                        'completedAt' => gmdate(DATE_RFC3339),
                    ];
                    unset($pending[$requestedJobID]);
                }
                continue;
            }

            $jobID = trim((string) ($json['id'] ?? $requestedJobID));
            if ($jobID === '') {
                continue;
            }

            $status = trim((string) ($json['status'] ?? ''));
            if (isTerminalJobStatus($status)) {
                $done[$jobID] = $json;
                unset($pending[$jobID]);
                continue;
            }

            $pending[$jobID] = [
                'id' => $jobID,
                'submittedAt' => (string) ($json['submittedAt'] ?? ($requestedJob['submittedAt'] ?? '')),
            ];
        }

        if ($pending !== []) {
            usleep(250000);
        }
    }

    return array_values($done);
}

function isTerminalJobStatus(string $status): bool
{
    return in_array($status, ['completed', 'failed', 'cancelled'], true);
}

function executeBatchWithBaseUrl(string $baseUrl, array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $request) {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize curl');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_URL => rtrim($baseUrl, '/').$request['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (($request['method'] ?? 'GET') === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($request['payload'] ?? [], JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
        }

        curl_setopt_array($handle, $options);
        $handles[$index] = ['handle' => $handle];
        curl_multi_add_handle($multi, $handle);
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $entry) {
        $handle = $entry['handle'];
        $body = (string) curl_multi_getcontent($handle);
        $info = curl_getinfo($handle);
        $results[] = [
            'status' => (int) ($info['http_code'] ?? 0),
            'durationMs' => (int) round(($info['total_time'] ?? 0.0) * 1000),
            'json' => json_decode($body, true),
        ];
        curl_multi_remove_handle($multi, $handle);
    }

    curl_multi_close($multi);

    return $results;
}

function parseIsoTime(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = [
        DATE_RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
        DATE_RFC3339,
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            return (float) sprintf('%.6f', $date->format('U.u'));
        }
    }

    $time = strtotime($value);
    if ($time === false) {
        return null;
    }

    return (float) $time;
}

function summarizeNumericArray(array $values): array
{
    if ($values === []) {
        return ['count' => 0];
    }

    sort($values);
    $count = count($values);
    $sum = array_sum($values);
    $p50 = percentile($values, 0.50);
    $p95 = percentile($values, 0.95);

    return [
        'count' => $count,
        'min' => $values[0],
        'p50' => $p50,
        'p95' => $p95,
        'max' => $values[$count - 1],
        'avg' => round($sum / $count, 2),
    ];
}

function percentile(array $sortedValues, float $quantile): int
{
    $count = count($sortedValues);
    if ($count === 0) {
        return 0;
    }

    $index = (int) round(($count - 1) * $quantile);
    return (int) $sortedValues[max(0, min($count - 1, $index))];
}

function formatSummary(array $result): string
{
    $summary = $result['summary'];
    $lines = [
        'Scenario: '.$result['scenario'].' ('.$result['mode'].')',
        'State dir: '.$result['stateDir'],
        'Count: '.($summary['count'] ?? 0).' at concurrency '.($summary['concurrency'] ?? 0),
    ];

    if (isset($summary['acceptedCount']) || isset($summary['submitFailures'])) {
        $lines[] = sprintf(
            'Accepted: %s, submit failures: %s',
            $summary['acceptedCount'] ?? 0,
            $summary['submitFailures'] ?? 0,
        );
    }

    if (isset($summary['latencyMs'])) {
        $lines[] = sprintf(
            'Latency ms: p50=%s p95=%s max=%s',
            $summary['latencyMs']['p50'] ?? 0,
            $summary['latencyMs']['p95'] ?? 0,
            $summary['latencyMs']['max'] ?? 0,
        );
    }

    if (isset($summary['queueWaitMs'])) {
        $lines[] = sprintf(
            'Queue wait ms: p50=%s p95=%s max=%s',
            $summary['queueWaitMs']['p50'] ?? 0,
            $summary['queueWaitMs']['p95'] ?? 0,
            $summary['queueWaitMs']['max'] ?? 0,
        );
    }

    if (isset($summary['submissionMs'])) {
        $lines[] = sprintf(
            'Submission ms: p50=%s p95=%s max=%s',
            $summary['submissionMs']['p50'] ?? 0,
            $summary['submissionMs']['p95'] ?? 0,
            $summary['submissionMs']['max'] ?? 0,
        );
    }

    return implode(PHP_EOL, $lines);
}

function compactRenderResult(array $result): array
{
    $json = is_array($result['json'] ?? null) ? $result['json'] : [];

    return [
        'requestId' => (string) ($json['requestId'] ?? $result['requestId'] ?? ''),
        'status' => (int) ($result['status'] ?? 0),
        'durationMs' => (int) ($result['durationMs'] ?? 0),
        'jobId' => (string) ($json['jobId'] ?? ''),
        'artifactId' => (string) ($json['artifacts']['id'] ?? ''),
        'pdfBytes' => (int) ($json['pdf']['bytes'] ?? 0),
    ];
}

function compactJobResult(array $job): array
{
    return [
        'id' => (string) ($job['id'] ?? ''),
        'status' => (string) ($job['status'] ?? ''),
        'attempts' => (int) ($job['attempts'] ?? 0),
        'maxRetries' => (int) ($job['maxRetries'] ?? 0),
        'nextRetryAt' => (string) ($job['nextRetryAt'] ?? ''),
        'deadLetterId' => (string) ($job['deadLetter']['id'] ?? ''),
        'resultJobId' => (string) ($job['result']['jobId'] ?? ''),
        'resultArtifactId' => (string) ($job['result']['artifacts']['id'] ?? ''),
    ];
}
