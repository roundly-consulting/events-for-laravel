<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Notifications\EventReminderNotification;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\ReminderSchedule;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze('2027-01-01 08:00:00');
    Notification::fake();
});

afterEach(fn () => Scenarios::thaw());

function sweepRemindersAt(string $at): int
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));

    return Events::sweep()->reminders;
}

test('all_day_time_offset_reminders_are_sent', function (): void {
    $attendee = User::factory()->create();
    $festival = Events::make('Festival')->organizedBy(User::factory()->create())->timezone('Europe/Bratislava')
        ->allDay('2027-01-16', through: '2027-01-17')->reminders(['P1D', 'PT1H'])->publish();
    Events::for($festival)->rsvp($attendee)->going();

    // P1D: 09:00 the day before; PT1H: 08:00 on the day (07:00 UTC), after the local midnight start.
    expect(ReminderSchedule::dueAt($festival, 'PT1H')?->toIso8601String())->toBe('2027-01-16T07:00:00+00:00')
        ->and(sweepRemindersAt('2027-01-15 08:00:00'))->toBe(1)
        ->and(sweepRemindersAt('2027-01-16 06:59:00'))->toBe(0)
        ->and(sweepRemindersAt('2027-01-16 07:00:00'))->toBe(1)
        ->and(sweepRemindersAt('2027-01-16 07:05:00'))->toBe(0);

    Notification::assertSentToTimes($attendee, EventReminderNotification::class, 2);
});

test('an_all_day_reminder_is_never_sent_after_its_civil_hour', function (): void {
    config()->set('events.reminders.max_lateness', 'PT12H');
    $attendee = User::factory()->create();
    $festival = Events::make('Festival')->organizedBy(User::factory()->create())->timezone('Europe/Bratislava')
        ->allDay('2027-01-16')->reminders(['PT1H'])->publish();
    Events::for($festival)->rsvp($attendee)->going();

    // 09:00 local has passed: a reminder "an hour before" makes no sense any more.
    expect(sweepRemindersAt('2027-01-16 08:30:00'))->toBe(0);
});
