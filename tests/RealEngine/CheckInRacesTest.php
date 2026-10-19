<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
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
