<?php

declare(strict_types=1);

namespace Oxhq\Canio\Data;

final class RenderJob
{
    public const CONTRACT_VERSION = 'canio.stagehand.job.v1';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly array $attributes,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self($attributes);
    }

    public function id(): string
    {
        return (string) ($this->attributes['id'] ?? '');
    }

    public function requestId(): string
    {
        return (string) ($this->attributes['requestId'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? 'unknown');
    }

    public function queued(): bool
    {
        return $this->status() === 'queued';
    }

    public function running(): bool
    {
        return $this->status() === 'running';
    }

    public function completed(): bool
    {
        return $this->status() === 'completed';
    }

    public function cancelled(): bool
    {
        return $this->status() === 'cancelled';
    }

    public function failed(): bool
    {
        return $this->status() === 'failed';
    }

    public function terminal(): bool
    {
        return $this->completed() || $this->failed() || $this->cancelled();
    }

    public function successful(): bool
    {
        return $this->completed() && $this->result()?->successful() === true;
    }

    public function error(): ?string
    {
        $error = $this->attributes['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }

    public function attempts(): int
    {
        return (int) ($this->attributes['attempts'] ?? 0);
    }

    public function maxRetries(): int
    {
        return (int) ($this->attributes['maxRetries'] ?? 0);
    }

    public function nextRetryAt(): ?string
    {
        $value = $this->attributes['nextRetryAt'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deadLetter(): ?array
    {
        $value = $this->attributes['deadLetter'] ?? null;

        return is_array($value) ? $value : null;
    }

    public function deadLetterId(): ?string
    {
        $deadLetter = $this->deadLetter();

        return is_array($deadLetter) ? (($deadLetter['id'] ?? null) ?: null) : null;
    }

    public function result(): ?RenderResult
    {
        $result = $this->attributes['result'] ?? null;

        return is_array($result) ? RenderResult::fromArray($result) : null;
    }

    public function artifactId(): ?string
    {
        return $this->result()?->artifactId();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
