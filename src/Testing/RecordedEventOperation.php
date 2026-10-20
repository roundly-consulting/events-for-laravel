<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * One operation recorded by {@see EventsFake}: what ran, with which arguments, and what it
 * returned. Context keys: `event` (the event it ran on), `actor`, `system`, `silent` (handle
 * calls); `data` (DTOs); `reason`, `starts_at`, `ends_at`, `transition`, `keep_venue`, `force`,
 * `removed` (status and record changes); `organizer`, `keep_as_host` (transfers); `labels`,
 * `category`; `venue`, `tier`, `order`, `rsvp`, `attendee`, `guests`, `host`, `invitee`,
 * `response`, `reference`, `options`. A check-in code is never recorded.
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
     * The event the operation ran on (or created) — for a call that only returned an RSVP (an
     * invitation accepted from its link) or a check-in (a scan by code alone), that RSVP's event.
     */
    public function event(): ?Event
    {
        $event = $this->context['event'] ?? $this->result;

        if ($event instanceof CheckInResult) {
            $event = $event->rsvp;
        }

        if ($event instanceof Rsvp) {
            $event = $event->event;
        }

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
