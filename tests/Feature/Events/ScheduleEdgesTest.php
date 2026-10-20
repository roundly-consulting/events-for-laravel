<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

function festival(bool $publish = true, string $through = '2027-07-19'): Event
{
    $builder = Events::make('Festival')->organizedBy(User::factory()->create())->timezone('Europe/Bratislava')
        ->allDay('2027-07-17', through: $through);

    return $publish ? $builder->publish() : $builder->create();
}

test('changing_the_timezone_of_an_all_day_event_keeps_its_days', function (): void {
    $event = festival();
    $sequence = $event->sequence;

    Events::for($event)->update(new UpdateEventData(timezone: 'America/New_York'));
    $fresh = $event->fresh();

    expect([$fresh?->starts_on, $fresh?->ends_on])->toBe(['2027-07-17', '2027-07-19'])
        ->and($fresh?->all_day)->toBeTrue()
        ->and($fresh?->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-07-17 00:00')
        ->and($fresh?->endsAtLocal()->format('Y-m-d H:i'))->toBe('2027-07-20 00:00')
        ->and($fresh?->sequence)->toBe($sequence + 1)
        ->and((string) Events::for($fresh ?? $event)->ics())->toContain('DTSTART;VALUE=DATE:20270717');
});

test('changing_the_timezone_of_a_timed_event_keeps_its_instants', function (): void {
    $event = Scenarios::event();
    $start = $event->starts_at;

    Events::for($event)->update(new UpdateEventData(timezone: 'America/New_York'));

    expect($event->fresh()?->starts_at->equalTo($start))->toBeTrue();
});

test('repeating_the_unchanged_start_is_an_ordinary_update', function (): void {
    $event = Scenarios::event();
    $start = $event->starts_at->setTimezone($event->timezone)->format('Y-m-d H:i');

    Events::for($event)->update(new UpdateEventData(title: 'Concert (sold out)', startsAt: $start));
    expect($event->fresh()?->title)->toBe('Concert (sold out)');

    $all = festival();
    Events::for($all)->update(new UpdateEventData(title: 'Festival (sold out)', allDayFrom: '2027-07-17'));
    expect($all->fresh()?->title)->toBe('Festival (sold out)')->and($all->fresh()?->ends_on)->toBe('2027-07-17');

    // A different start still needs reschedule().
    expect(fn () => Events::for($event)->update(new UpdateEventData(startsAt: '2027-02-10 21:00')))->toThrow(InvalidEventException::class)
        ->and(fn () => Events::for($all)->update(new UpdateEventData(allDayFrom: '2027-07-18')))->toThrow(InvalidEventException::class)
        ->and(fn () => Events::for($event)->update(new UpdateEventData(startsAt: '2027-02-10 20:00', timezone: 'Mars/Base')))->toThrow(InvalidEventScheduleException::class);
});

test('an_all_day_event_can_change_its_last_day', function (): void {
    $event = festival();

    Events::for($event)->update(new UpdateEventData(allDayThrough: '2027-07-20'));
    expect($event->fresh()?->ends_on)->toBe('2027-07-20')->and($event->fresh()?->starts_on)->toBe('2027-07-17');

    Events::for($event)->update(new UpdateEventData(allDayThrough: null));
    expect($event->fresh()?->ends_on)->toBe('2027-07-17');

    expect(fn () => Events::for($event)->update(new UpdateEventData(allDayThrough: '2027-07-16')))->toThrow(InvalidEventScheduleException::class)
        ->and(fn () => Events::for(Scenarios::event())->update(new UpdateEventData(allDayThrough: '2027-02-12')))->toThrow(InvalidEventScheduleException::class);
});

test('a_start_time_turns_an_all_day_draft_into_a_timed_event', function (): void {
    $draft = festival(publish: false, through: '2027-07-17');

    Events::for($draft)->update(new UpdateEventData(startsAt: '2027-07-17 20:00', duration: 'PT2H', doorsOpenAt: '2027-07-17 19:00'));
    $draft->refresh();

    expect($draft->all_day)->toBeFalse()
        ->and($draft->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-07-17 20:00')
        ->and($draft->endsAtLocal()->format('Y-m-d H:i'))->toBe('2027-07-17 22:00')
        ->and($draft->doors_open_at?->setTimezone('Europe/Bratislava')->format('H:i'))->toBe('19:00');
});

test('a_doors_time_on_an_all_day_event_is_refused', function (): void {
    $event = festival();

    expect(fn () => Events::for($event)->update(new UpdateEventData(doorsOpenAt: '2027-07-17 08:00')))->toThrow(InvalidEventScheduleException::class);

    // Clearing it is a no-op.
    Events::for($event)->update(new UpdateEventData(doorsOpenAt: null));
    expect($event->fresh()?->doors_open_at)->toBeNull();
});
