<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidCheckInCodeException;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventCounters;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Racer;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('check_in_is_idempotent', function (): void {
    $event = Scenarios::event(capacity: 5);
    $code = (string) Events::for($event)->rsvp(User::factory()->create())->going()->checkInCode();
    Scenarios::minutesBeforeStart($event, 30);
    $eventId = $event->getKey();

    // Two (four) scanners read the same code at once: exactly one is first.
    $racers = [];

    for ($i = 0; $i < 4; $i++) {
        $racers[] = (new Racer)->run(static fn (): string => Events::for(Event::query()->findOrFail($eventId))->checkIn($code)->firstCheckIn ? 'first' : 'again');
    }

    $outcomes = array_map(static fn (Racer $racer): string => $racer->outcome(), $racers);

    expect(array_count_values($outcomes))->toEqualCanonicalizing(['first' => 1, 'again' => 3]);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('check_in_and_decline_race_has_one_outcome', function (): void {
    $organizer = User::factory()->create();
    $event = Scenarios::event(capacity: 50, organizer: $organizer);
    $eventId = $event->getKey();
    $organizerId = $organizer->getKey();
    $attendees = User::factory()->count(20)->create();
    $codes = $attendees->mapWithKeys(fn (User $user): array => [$user->getKey() => (string) Events::for($event)->rsvp($user)->going()->checkInCode()])->all();
    Scenarios::minutesBeforeStart($event, 30);

    // Each round: a door scanner and the attendee leaving race on one RSVP. Exactly one wins —
    // either checked in (and the decline refused) or gone (and the scan refused). A decline that
    // commits before the scan's lookup has already revoked the code, so that scan is refused as
    // an invalid code; one that commits after the lookup is refused as not going.
    foreach ($codes as $attendeeId => $code) {
        $scan = (new Racer)->run(static function () use ($eventId, $organizerId, $code): string {
            try {
                return Events::for(Event::query()->findOrFail($eventId))->by(User::query()->findOrFail($organizerId))->checkIn($code)->firstCheckIn ? 'won' : 'again';
            } catch (CheckInRefusedException|InvalidCheckInCodeException) {
                return 'lost';
            }
        });
        $decline = (new Racer)->run(static function () use ($eventId, $attendeeId): string {
            try {
                Events::for(Event::query()->findOrFail($eventId))->rsvp(User::query()->findOrFail($attendeeId))->notGoing();

                return 'won';
            } catch (InvalidRsvpTransitionException) {
                return 'lost';
            }
        });

        $outcomes = [$scan->outcome(), $decline->outcome()];
        sort($outcomes);

        expect($outcomes)->toBe(['lost', 'won']);
    }

    expect(Rsvp::query()->where('event_id', $eventId)->where('status', '!=', RsvpStatus::Going->value)->whereNotNull('checked_in_at')->count())->toBe(0)
        ->and(EventCounters::stored($event->fresh() ?? $event))->toEqual(EventCounters::recount($event->fresh() ?? $event));
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
