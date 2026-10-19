<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Support\EventCounters;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('counters_match_recount', function (int $seed): void {
    mt_srand($seed);
    $event = Scenarios::event(capacity: 12, maxGuests: 2);
    Events::for($event)->tiers()->make('Floor')->key('floor')->capacity(6)->create();
    Events::for($event)->tiers()->add('Gallery', key: 'gallery');
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('10', 'EUR'), key: 'supporter');
    $people = User::factory()->count(14)->create();
    $tiers = ['floor', 'gallery', 'supporter'];

    for ($step = 0; $step < 120; $step++) {
        $person = $people[mt_rand(0, 13)];
        $handle = Events::for($event);

        try {
            match (mt_rand(0, 9)) {
                0, 1, 2, 3 => $handle->rsvp($person)->going(tier: $tiers[mt_rand(0, 2)], guests: mt_rand(0, 2)),
                4 => $handle->rsvp($person)->interested(),
                5 => $handle->rsvp($person)->notGoing(),
                6 => $handle->attendees()->add($person, tier: $tiers[mt_rand(0, 1)], guests: mt_rand(0, 1)),
                7 => $handle->attendees()->changeGuests($person, mt_rand(0, 2)),
                8 => $handle->update(new UpdateEventData(capacity: mt_rand(6, 16))),
                default => $handle->waitlist()->promote(),
            };
        } catch (RuntimeException) {
            // Refusals (full, sold out, unknown RSVP, closed) change nothing — which is the point.
        }

        if ($step % 30 === 29) {
            Scenarios::freeze(Scenarios::now()->addMinutes(31)->format('Y-m-d H:i:s'));
            Events::sweep();
        }
    }

    expect(EventCounters::stored($event))->toEqual(EventCounters::recount($event));
})->with([[1], [7], [42], [1337]]);

test('deadlock_retry_starts_fresh', function (): void {
    $event = Scenarios::event(capacity: 10);
    $attempts = 0;
    $seen = [];

    // Another session moved the counters after this model was loaded.
    $event->newQueryWithoutScopes()->whereKey($event->getKey())->toBase()->update(['seats_taken' => 4]);

    EventTransaction::run($event, function () use ($event, &$attempts, &$seen): void {
        $attempts++;
        $seen[] = LockedEvent::lock($event)->seats_taken;

        if ($attempts === 1) {
            // A deadlock on the first attempt: the outermost transaction retries.
            throw new QueryException($event->getConnectionName() ?? 'testing', 'update events', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction (SQLSTATE 40001)'));
        }
    });

    expect($attempts)->toBe(2)
        ->and($seen)->toBe([4, 4]);
});
