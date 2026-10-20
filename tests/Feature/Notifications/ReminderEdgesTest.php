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

test('configured_reminder_offsets_are_range_checked', function (array $offsets): void {
    config()->set('events.reminders.offsets', $offsets);

    expect(fn () => EventsConfig::reminderOffsets())->toThrow(InvalidEventsConfigurationException::class, 'events.reminders.offsets');
})->with([
    'past the sweep window' => [['P40D']],
    'below five minutes' => [['PT1M']],
    'more than five' => [['P1D', 'PT1H', 'PT2H', 'PT3H', 'PT4H', 'PT5H']],
]);

test('configured_reminder_offsets_in_range_are_read', function (): void {
    config()->set('events.reminders.offsets', ['P31D', 'PT5M']);

    expect(EventsConfig::reminderOffsets())->toBe(['P31D', 'PT5M']);
});

test('payment_holds_must_last', function (string $key): void {
    config()->set($key, 'PT0S');

    expect(fn () => $key === 'events.payments.hold_for' ? EventsConfig::holdFor() : EventsConfig::promotionHold())
        ->toThrow(InvalidEventsConfigurationException::class, $key);
})->with(['events.payments.hold_for', 'events.payments.promotion_hold']);

test('doctor_reports_unusable_durations_early', function (): void {
    config()->set('events.reminders.offsets', ['P40D']);
    config()->set('events.payments.hold_for', 'PT0S');

    expect(Artisan::call('events:doctor', ['--json' => true]))->toBe(1);
    $checks = array_column(json_decode(trim(Artisan::output()), true), 'check');

    expect($checks)->toContain('reminders', 'payments');
});
