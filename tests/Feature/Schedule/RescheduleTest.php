<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
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

test('a_31_day_all_day_event_across_the_autumn_switch_is_allowed', function (): void {
    $organizer = User::factory()->create();
    $bratislava = static fn () => Events::make('Month of design')->organizedBy($organizer)->timezone('Europe/Bratislava');

    // 31 local days; across the autumn switch the UTC span is 31 days and an hour.
    $october = $bratislava()->allDay('2027-10-01', through: '2027-10-31')->create();
    expect($october->ends_on)->toBe('2027-10-31');

    // The same for a timed event of 31 local days.
    $timed = $bratislava()->startsAt('2027-10-10 10:00')->endsAt('2027-11-10 10:00')->create();
    expect($timed->ends_at->toIso8601String())->toBe('2027-11-10T09:00:00+00:00');

    // An event of exactly 31 days (in seconds) moved across the spring switch keeps its length.
    $spring = $bratislava()->startsAt('2027-02-01 10:00')->duration('P31D')->publish();
    Events::for($spring)->reschedule('2027-03-10 10:00');
    expect((int) $spring->fresh()?->starts_at->diffInSeconds($spring->fresh()?->ends_at))->toBe(31 * 86400);

    // Still a limit: a 32nd local day is refused.
    expect(fn () => $bratislava()->allDay('2027-10-01', through: '2027-11-01')->create())
        ->toThrow(InvalidEventScheduleException::class);
});
