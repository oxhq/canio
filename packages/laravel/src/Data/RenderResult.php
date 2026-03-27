<?php

declare(strict_types=1);

namespace Oxhq\Canio\Data;

use RuntimeException;

final class RenderResult
{
    public const CONTRACT_VERSION = 'canio.stagehand.render-result.v1';

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

    public function successful(): bool
    {
        return in_array($this->status(), ['completed', 'success'], true);
    }

    public function status(): string
    {
        return (string) ($this->attributes['status'] ?? 'unknown');
    }

    public function fileName(): string
    {
        $pdf = $this->pdf();

        return (string) ($pdf['fileName'] ?? 'document.pdf');
    }

    public function contentType(): string
    {
        $pdf = $this->pdf();

        return (string) ($pdf['contentType'] ?? 'application/pdf');
    }

    public function artifactId(): ?string
    {
        $artifacts = $this->artifacts();
        $id = $artifacts['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function artifacts(): ?array
    {
        $artifacts = $this->attributes['artifacts'] ?? null;

        return is_array($artifacts) ? $artifacts : null;
    }

    public function pdfBytes(): string
    {
        $pdf = $this->pdf();
        $base64 = (string) ($pdf['base64'] ?? '');

        if ($base64 === '') {
            throw new RuntimeException('Stagehand response did not include inline PDF bytes.');
        }

        $decoded = base64_decode($base64, true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Stagehand returned invalid base64 PDF bytes.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function withStoredOutput(string $path, ?string $disk = null): self
    {
        $attributes = $this->attributes;
        $attributes['stored'] = array_filter([
            'path' => $path,
            'disk' => $disk,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return new self($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function pdf(): array
    {
        $pdf = $this->attributes['pdf'] ?? [];

        return is_array($pdf) ? $pdf : [];
    }
}
