<?php

declare(strict_types=1);

namespace Oxhq\Canio\Data;

final class RenderSpec
{
    public const CONTRACT_VERSION = 'canio.stagehand.render-spec.v1';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly array $attributes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['contractVersion' => self::CONTRACT_VERSION, ...$this->attributes];
    }
}
