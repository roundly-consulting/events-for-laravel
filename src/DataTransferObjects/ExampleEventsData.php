<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use RoundlyConsulting\Events\Actions\ExampleEventsAction;

/**
 * REPLACE ME — the input of {@see ExampleEventsAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExampleEventsData
{
    public function __construct(
        public string $name,
    ) {}
}
