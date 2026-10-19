<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * A distance expression in kilometres and its bindings, in order.
 *
 * @internal
 */
final readonly class DistanceSql
{
    /**
     * @param  list<float>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {}
}
