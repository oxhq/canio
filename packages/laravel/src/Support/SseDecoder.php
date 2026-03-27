<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Generator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class SseDecoder
{
    /**
     * @param  resource  $stream
     * @return Generator<array{id:string|null,event:string|null,data:array<string, mixed>}>
     */
    public function decode($stream): Generator
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('SSE decoder expects a valid stream resource.');
        }

        $event = null;
        $id = null;
        $dataLines = [];

        while (! feof($stream)) {
            $line = fgets($stream);

            if ($line === false) {
                continue;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                $frame = $this->frame($id, $event, $dataLines);

                if ($frame !== null) {
                    yield $frame;
                }

                $event = null;
                $id = null;
                $dataLines = [];

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = $this->splitLine($line);

            match ($field) {
                'event' => $event = $value,
                'id' => $id = $value,
                'data' => $dataLines[] = $value,
                default => null,
            };
        }

        $frame = $this->frame($id, $event, $dataLines);

        if ($frame !== null) {
            yield $frame;
        }
    }

    /**
     * @param  list<string>  $dataLines
     * @return array{id:string|null,event:string|null,data:array<string, mixed>}|null
     */
    private function frame(?string $id, ?string $event, array $dataLines): ?array
    {
        if ($dataLines === []) {
            return null;
        }

        $payload = implode("\n", $dataLines);

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Stagehand SSE payload.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Stagehand SSE payload did not decode to an object.');
        }

        return [
            'id' => $id !== '' ? $id : null,
            'event' => $event !== '' ? $event : null,
            'data' => $decoded,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitLine(string $line): array
    {
        $parts = explode(':', $line, 2);
        $field = trim($parts[0]);
        $value = ltrim($parts[1] ?? '', ' ');

        return [$field, $value];
    }
}
