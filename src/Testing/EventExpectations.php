<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * Pest expectations for host tests — call `EventExpectations::register()` in `tests/Pest.php`:
 *
 *     expect($member)->toBeGoingTo($event);
 *     expect($member)->toBeWaitlistedFor($event);
 *     expect($member)->toBeCheckedInTo($event);
 *
 * Each reads the stored RSVP, so it works with or without `Events::fake()` (which runs for real).
 */
final class EventExpectations
{
    public static function register(): void
    {
        if (! function_exists('expect')) {
            return;
        }

        expect()->extend('toBeGoingTo', function (Event $event): mixed {
            $rsvp = EventExpectations::rsvp($this->value, $event);

            Assert::assertSame(RsvpStatus::Going, $rsvp?->status, 'Expected the attendee to be going to the event, but they are '.($rsvp?->status->value ?? 'not on its list').'.');

            return $this;
        });

        expect()->extend('toBeWaitlistedFor', function (Event $event): mixed {
            $rsvp = EventExpectations::rsvp($this->value, $event);

            Assert::assertSame(RsvpStatus::Waitlisted, $rsvp?->status, 'Expected the attendee to be on the waitlist, but they are '.($rsvp?->status->value ?? 'not on its list').'.');

            return $this;
        });

        expect()->extend('toBeCheckedInTo', function (Event $event): mixed {
            $rsvp = EventExpectations::rsvp($this->value, $event);

            Assert::assertNotNull($rsvp?->checked_in_at, 'Expected the attendee to be checked in to the event, but they are not.');

            return $this;
        });
    }

    /**
     * @internal for the expectations above
     */
    public static function rsvp(mixed $attendee, Event $event): ?Rsvp
    {
        Assert::assertInstanceOf(Model::class, $attendee, 'The expectation value must be the attendee model.');

        return EventModels::rsvp()::query()
            ->where('event_id', $event->getKey())
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->getKey())
            ->first();
    }
}
