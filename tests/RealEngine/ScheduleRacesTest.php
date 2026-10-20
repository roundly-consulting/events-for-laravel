<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Racer;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

/**
 * A reschedule racing a publish in another session, on PostgreSQL and MySQL: the path (a draft
 * edit or an announced reschedule) is chosen from the phase read under the event's lock.
 */
beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('reschedule_racing_a_publish_reschedules', function (): void {
    $draft = Events::make('Gig')->organizedBy(User::factory()->create())->timezone('UTC')
        ->startsAt('2027-02-10 20:00')->duration('2 hours')->doorsOpenAt('2027-02-10 18:00')->create();
    $eventId = $draft->getKey();
    $racer = null;

    // This session publishes; the other one reads the draft and reschedules it meanwhile.
    DB::transaction(static function () use ($draft, $eventId, &$racer): void {
        Events::for($draft)->publish();

        $racer = (new Racer)->run(static function () use ($eventId): string {
            $stale = Event::query()->findOrFail($eventId);

            return Events::for($stale)->reschedule('2027-03-10 20:00')->event()->phase->value;
        })->waitUntilBlockedOrDone();
    });

    expect($racer?->outcome())->toBe(EventPhase::Rescheduled->value);

    $event = $draft->fresh();

    expect($event?->phase)->toBe(EventPhase::Rescheduled)
        ->and($event?->starts_at->toIso8601String())->toBe('2027-03-10T20:00:00+00:00')
        ->and($event?->previous_starts_at?->toIso8601String())->toBe('2027-02-10T20:00:00+00:00')
        ->and($event?->doors_open_at)->toBeNull();
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
