<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Lifecycle;

use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\Messages;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * Refuses while the event has going or held attendees (denial code `no_attendees`): an event
 * people are coming to is postponed or cancelled — so they are told — never quietly unpublished.
 */
final readonly class NoAttendees implements Guard
{
    public const string CODE = 'no_attendees';

    public function check(TransitionContext $context): ?Denial
    {
        $taken = EventModels::rsvp()::query()
            ->where('event_id', $context->subject->getKey())
            ->whereIn('status', [RsvpStatus::Going->value, RsvpStatus::Held->value])
            ->exists();

        return $taken ? Denial::of(self::CODE, message: Messages::get('denial_no_attendees'), source: 'guard') : null;
    }
}
