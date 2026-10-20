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
