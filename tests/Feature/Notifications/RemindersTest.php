<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Events\RemindersDispatched;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\ReminderDelivery;
use RoundlyConsulting\Events\Notifications\EventReminderNotification;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\ReminderSchedule;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze('2027-01-01 08:00:00');
    Notification::fake();
    $this->attendee = User::factory()->create();
});

afterEach(fn () => Scenarios::thaw());

/**
 * An announced event with these reminder offsets and one going attendee.
 *
 * @param  list<string>  $offsets
 */
function remindedEvent(User $attendee, string $startsAt, array $offsets, string $timezone = 'Europe/Bratislava'): Event
{
    $event = Events::make('Concert')->organizedBy(User::factory()->create())->timezone($timezone)
        ->startsAt($startsAt)->reminders($offsets)->publish();
    Events::for($event)->rsvp($attendee)->going();

    return $event;
}

function sweepAt(string $at): int
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));

    return Events::sweep()->reminders;
}

test('reminders_fire_once', function (): void {
    EventBus::fake([RemindersDispatched::class]);
    $event = remindedEvent($this->attendee, '2027-01-03 20:00', ['P1D', 'PT2H']);

    // The day before at 20:00 local (19:00 UTC), then two hours before.
    expect(sweepAt('2027-01-02 18:59:00'))->toBe(0)
        ->and(sweepAt('2027-01-02 19:00:00'))->toBe(1)
        ->and(sweepAt('2027-01-02 19:05:00'))->toBe(0)
        ->and(sweepAt('2027-01-03 17:00:00'))->toBe(1)
        ->and(sweepAt('2027-01-03 17:01:00'))->toBe(0)
        ->and(ReminderDelivery::query()->where('event_id', $event->getKey())->count())->toBe(2);

    Notification::assertSentToTimes($this->attendee, EventReminderNotification::class, 2);
    EventBus::assertDispatched(RemindersDispatched::class, static fn (RemindersDispatched $sent): bool => $sent->offset === 'P1D' && $sent->recipients === 1);
});

test('reminders_skip_offsets_before_publish', function (): void {
    // Announced an hour before it starts: no "tomorrow" reminder, the PT30M one still comes.
    Scenarios::freeze('2027-01-03 18:00:00');
    remindedEvent($this->attendee, '2027-01-03 20:00', ['P1D', 'PT30M']);

    expect(sweepAt('2027-01-03 18:01:00'))->toBe(0)
        ->and(sweepAt('2027-01-03 18:30:00'))->toBe(1);
});

test('reschedule_rearms_reminders', function (): void {
    $event = remindedEvent($this->attendee, '2027-01-03 20:00', ['PT2H']);
    expect(sweepAt('2027-01-03 17:00:00'))->toBe(1);

    Events::for($event)->reschedule('2027-01-10 20:00');

    expect(sweepAt('2027-01-10 17:00:00'))->toBe(1)
        ->and(ReminderDelivery::query()->where('event_id', $event->getKey())->count())->toBe(2);
});

test('reschedule_to_an_earlier_time_sends_no_stale_reminder', function (): void {
    $event = remindedEvent($this->attendee, '2027-01-10 20:00', ['P1D']);

    // Rescheduled to three hours from now: the P1D reminder of the new date is already due —
    // but before the new schedule was announced, so it is skipped.
    Scenarios::freeze('2027-01-05 16:00:00');
    Events::for($event)->reschedule('2027-01-05 20:00');

    expect(sweepAt('2027-01-05 16:01:00'))->toBe(0);
});

test('late_reminders_are_dropped', function (): void {
    remindedEvent($this->attendee, '2027-01-03 20:00', ['P1D']);

    // A scheduler outage: the sweep comes back long after the reminder was due.
    expect(sweepAt('2027-01-02 21:30:00'))->toBe(0)
        // and nothing at or after the start.
        ->and(sweepAt('2027-01-03 20:00:00'))->toBe(0);
});

test('calendar_offsets_keep_local_wall_clock_across_dst', function (): void {
    // Europe/Bratislava springs forward on 2027-03-28: the day before still means 20:00 local.
    $event = remindedEvent($this->attendee, '2027-03-28 20:00', ['P1D']);

    expect(ReminderSchedule::dueAt($event, 'P1D')?->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-03-27 20:00')
        // 23 hours of real time across the gap.
        ->and(ReminderSchedule::dueAt($event, 'P1D')?->diffInHours($event->starts_at))->toBe(23.0);
});

test('time_offsets_are_absolute', function (): void {
    $event = remindedEvent($this->attendee, '2027-03-28 05:00', ['PT4H']);

    // 04:00 local → 01:00 local across the 02:00 → 03:00 gap: four real hours.
    expect(ReminderSchedule::dueAt($event, 'PT4H')?->diffInHours($event->starts_at))->toBe(4.0)
        ->and(ReminderSchedule::dueAt($event, 'P1DT2H')?->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-03-27 03:00');
});

test('all_day_reminders_land_at_a_civil_hour', function (): void {
    $festival = Events::make('Festival')->organizedBy(User::factory()->create())->timezone('Europe/Bratislava')
        ->allDay('2027-01-16', through: '2027-01-17')->reminders(['P1D', 'PT1H'])->publish();

    expect(ReminderSchedule::dueAt($festival, 'P1D')?->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-01-15 09:00')
        ->and(ReminderSchedule::dueAt($festival, 'PT1H')?->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-01-16 08:00');

    config()->set('events.reminders.all_day_at', '18:30');
    expect(ReminderSchedule::dueAt($festival, 'P1D')?->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2027-01-15 18:30');

    config()->set('events.reminders.all_day_at', '25:00');
    expect(fn () => ReminderSchedule::dueAt($festival, 'P1D'))->toThrow(InvalidEventsConfigurationException::class, 'events.reminders.all_day_at');
});

it('reads offsets from the config unless the event has its own', function (): void {
    config()->set('events.reminders.offsets', ['PT1H']);
    $event = Events::make('Talk')->organizedBy(User::factory()->create())->startsAt('2027-01-03 20:00')->publish();
    Events::for($event)->rsvp($this->attendee)->going();

    expect(ReminderSchedule::offsets($event))->toBe(['PT1H'])
        ->and(sweepAt('2027-01-03 19:00:00'))->toBe(1);

    Events::for($event)->update(new UpdateEventData(reminders: ['PT30M']));
    expect(ReminderSchedule::offsets($event->fresh() ?? $event))->toBe(['PT30M'])
        ->and(fn () => Events::for($event)->update(new UpdateEventData(reminders: ['P40D'])))->toThrow(InvalidEventException::class);

    config()->set('events.reminders.offsets', ['soon']);
    expect(fn () => EventsConfig::reminderOffsets())->toThrow(InvalidEventsConfigurationException::class, 'events.reminders.offsets');
});
