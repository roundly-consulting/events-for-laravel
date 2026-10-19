<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

use RoundlyConsulting\Events\Support\Messages;
use RuntimeException;

/**
 * The base of every domain exception the package throws. Each carries an HTTP status and a
 * stable machine code (`event_full`, `invalid_code`, …) beside its translated message, so a host
 * API maps them without matching message text. Messages never name a PHP class.
 */
abstract class EventsException extends RuntimeException
{
    final public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected static function make(string $code, int $status, array $replace = [], ?string $line = null): static
    {
        return new static(Messages::get($line ?? $code, $replace), $code, $status);
    }
}
