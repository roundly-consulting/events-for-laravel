<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\ReminderDelivery;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Racer;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

/**
 * Concurrent sweeps on PostgreSQL and MySQL: the delivery insert is the claim, so each reminder
 * goes out exactly once.
 */
beforeEach(fn () => Scenarios::freeze('2027-01-01 08:00:00'));
afterEach(fn () => Scenarios::thaw());

test('reminders_fire_once_under_concurrent_sweeps', function (): void {
    $events = collect(range(1, 3))->map(function (): Event {
        $event = Events::make('Concert')->organizedBy(User::factory()->create())->startsAt('2027-01-03 20:00')->reminders(['PT2H', 'PT90M'])->publish();
        Events::for($event)->rsvp(User::factory()->create())->going();

        return $event;
    });
    $due = '2027-01-03 18:45:00';

    $racers = array_map(static fn (): Racer => (new Racer)->run(static function () use ($due): string {
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse($due, 'UTC'));

        return (string) Events::sweep()->reminders;
    }), range(1, 6));

    $claimed = array_sum(array_map(static fn (Racer $racer): int => (int) $racer->outcome(), $racers));

    // Two offsets per event, three events: six reminders, however many sweepers ran.
    expect($claimed)->toBe(6)
        ->and(ReminderDelivery::query()->whereIn('event_id', $events->map->getKey())->count())->toBe(6);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
