<?php

declare(strict_types=1);

use Oxhq\Canio\Support\SseDecoder;

it('decodes stagehand sse frames from a stream resource', function () {
    $stream = fopen('php://temp', 'r+');
    expect($stream)->not->toBeFalse();

    fwrite($stream, ": keep-alive\n");
    fwrite($stream, "\n");
    fwrite($stream, "id: 1\n");
    fwrite($stream, "event: job.running\n");
    fwrite($stream, "data: {\"kind\":\"job.running\",\"job\":{\"id\":\"job-123\",\"status\":\"running\"}}\n\n");
    fwrite($stream, "id: 2\n");
    fwrite($stream, "event: job.completed\n");
    fwrite($stream, "data: {\"kind\":\"job.completed\",\"job\":{\"id\":\"job-123\",\"status\":\"completed\"}}\n\n");
    rewind($stream);

    $frames = iterator_to_array((new SseDecoder)->decode($stream), false);

    expect($frames)->toHaveCount(2)
        ->and($frames[0]['id'])->toBe('1')
        ->and($frames[0]['event'])->toBe('job.running')
        ->and($frames[0]['data']['job']['status'])->toBe('running')
        ->and($frames[1]['id'])->toBe('2')
        ->and($frames[1]['event'])->toBe('job.completed')
        ->and($frames[1]['data']['job']['status'])->toBe('completed');

    fclose($stream);
});
