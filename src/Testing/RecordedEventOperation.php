<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Models\Event;

/**
 * One operation recorded by {@see EventsFake}: what ran, with which arguments, and what it
 * returned. Context keys: `event` (the event it ran on), `actor`, `system`, `silent` (handle
 * calls); `data` (DTOs); `reason`, `starts_at`, `ends_at`, `transition`, `url` (status changes);
 * `venue`, `tier`, `rsvp`, `attendee`, `status`, `host`, `invitee`, `reference`, `code` (hashed
 * never — the plain code is not recorded).
 */
final readonly class RecordedEventOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public EventOperation $operation,
        public array $context,
        public mixed $result,
    ) {}

    /**
     * The event the operation ran on (or created).
     */
    public function event(): ?Event
    {
        $event = $this->context['event'] ?? $this->result;

        return $event instanceof Event ? $event : null;
    }

    /**
     * Whether the operation concerns `$event` (null = any).
     */
    public function concerns(?Event $event): bool
    {
        if ($event === null) {
            return true;
        }

        return $this->event()?->is($event) ?? false;
    }

    public function model(string $key): ?Model
    {
        $value = $this->context[$key] ?? null;

        return $value instanceof Model ? $value : null;
    }
}
