<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Bridge\HttpStagehandClient;
use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;

it('encodes empty object-like render spec fields as json objects', function () {
    $client = new HttpStagehandClient([]);
    $method = new ReflectionMethod($client, 'encodePayload');
    $method->setAccessible(true);

    $json = $method->invoke($client, [
        'contractVersion' => 'canio.stagehand.render-spec.v1',
        'requestId' => 'req-123',
        'source' => [
            'type' => 'html',
            'payload' => [
                'html' => '<p>Hello</p>',
                'origin' => [],
            ],
        ],
        'postprocess' => [],
        'correlation' => [],
        'output' => [
            'mode' => 'inline',
        ],
    ]);

    expect($json)->toContain('"origin":{}')
        ->and($json)->toContain('"postprocess":{}')
        ->and($json)->toContain('"correlation":{}');
});

it('encodes empty request payloads as a json object', function () {
    $client = new HttpStagehandClient([]);
    $method = new ReflectionMethod($client, 'encodePayload');
    $method->setAccessible(true);

    expect($method->invoke($client, []))->toBe('{}');
});

it('bootstraps the runtime before issuing requests', function () {
    $bootstrapper = new RecordingRuntimeBootstrapper;

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs*' => Http::response([
            'contractVersion' => 'canio.stagehand.jobs.v1',
            'count' => 0,
            'items' => [],
        ]),
    ]);

    $client = new HttpStagehandClient([
        'base_url' => 'http://127.0.0.1:9514',
    ], $bootstrapper);

    expect($client->jobs())->toBeArray()
        ->and($bootstrapper->calls)->toBe(1);
});

final class RecordingRuntimeBootstrapper implements StagehandRuntimeBootstrapper
{
    public int $calls = 0;

    public function ensureAvailable(): void
    {
        $this->calls++;
    }
}
