<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

function utcEvent(string $startsAt, string $endsAt, bool $publish = true): Event
{
    $builder = Events::make('Festival')->organizedBy(User::factory()->create())->timezone('UTC')->startsAt($startsAt)->endsAt($endsAt);

    return $publish ? $builder->publish() : $builder->create();
}

test('a_reschedule_keeps_the_exact_length', function (): void {
    // 28 days in February, moved into April (a 30-day month): still 28 days.
    $event = utcEvent('2027-02-01 10:00', '2027-03-01 10:00');
    Events::for($event)->reschedule('2027-04-01 10:00');

    expect($event->fresh()?->ends_at->toIso8601String())->toBe('2027-04-29T10:00:00+00:00');

    // A draft given a new start through update() keeps it too.
    $draft = utcEvent('2027-02-01 10:00', '2027-03-01 10:00', publish: false);
    Events::for($draft)->update(new UpdateEventData(startsAt: '2027-04-01 10:00'));

    expect($draft->fresh()?->ends_at->toIso8601String())->toBe('2027-04-29T10:00:00+00:00');

    // The longest event allowed (P31D) moves and stays 31 days.
    $long = utcEvent('2027-02-01 10:00', '2027-03-04 10:00');
    Events::for($long)->reschedule('2027-03-01 10:00');

    expect($long->fresh()?->ends_at->toIso8601String())->toBe('2027-04-01T10:00:00+00:00');
});
