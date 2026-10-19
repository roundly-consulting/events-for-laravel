<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use RoundlyConsulting\Events\Enums\FindingSeverity;

/**
 * One `events:doctor` finding: a stable check key and what to fix.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public FindingSeverity $severity,
        public string $check,
        public string $message,
    ) {}

    /**
     * @return array{severity: string, check: string, message: string}
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity->value, 'check' => $this->check, 'message' => $this->message];
    }
}
