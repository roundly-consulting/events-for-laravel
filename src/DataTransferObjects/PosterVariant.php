<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * One configured poster size (`events.poster.variants`), cover-fitted and encoded as webp.
 */
final readonly class PosterVariant
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
    ) {}
}
