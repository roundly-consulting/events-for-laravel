<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function rawEvent(Event $event): array
{
    return (array) DB::table('events')->where('id', $event->getKey())->first();
}

test('storage_is_utc_whatever_the_app_timezone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));
    expect(date_default_timezone_get())->toBe('America/New_York');

    $event = Event::factory()->startingAt(CarbonImmutable::parse('2026-06-10 20:00', 'Europe/Bratislava'))->create();
    $raw = rawEvent($event);

    // The instant the organizer meant, as UTC — not New York's wall clock.
    expect(substr((string) $raw['starts_at'], 0, 19))->toBe('2026-06-10 18:00:00')
        ->and(substr((string) $raw['ends_at'], 0, 19))->toBe('2026-06-10 20:00:00')
        ->and(substr((string) $raw['created_at'], 0, 19))->toBe('2026-06-01 12:00:00')
        ->and(substr((string) $raw['updated_at'], 0, 19))->toBe('2026-06-01 12:00:00')
        ->and($event->fresh()?->starts_at->toIso8601String())->toBe('2026-06-10T18:00:00+00:00');

    // Lifecycle writes its stamps with the model's freshTimestampString(): UTC too.
    Lifecycles::for($event)->asSystem()->apply('publish');
    expect(substr((string) rawEvent($event)['published_at'], 0, 19))->toBe('2026-06-01 12:00:00');

    // Soft deletes.
    $event->delete();
    expect(substr((string) rawEvent($event)['deleted_at'], 0, 19))->toBe('2026-06-01 12:00:00');
});

test('lifecycle_guards_and_expiry_read_utc_under_new_york', function (): void {
    // 23:00 UTC is 19:00 in New York (EDT): an event ending 23:30 UTC has not ended yet.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 23:00:00', 'UTC'));

    $event = Event::factory()->startingAt(CarbonImmutable::parse('2026-06-01 22:30', 'UTC'), hours: 1)->create();

    // publish is notAfter(ends_at): read through the UTC cast, so it is still allowed.
    Lifecycles::for($event)->asSystem()->apply('publish');
    $event->refresh();

    expect($event->phase->value)->toBe('scheduled')
        // The expiry is the event's own ends_at, as an instant.
        ->and(Lifecycles::for($event)->expiresAt()?->utc()->toIso8601String())->toBe('2026-06-01T23:30:00+00:00');

    // complete is notBefore(starts_at): allowed once started — at 23:00 UTC, not at 22:30 New York.
    expect(Lifecycles::for($event)->asSystem()->can('complete'))->toBeTrue();
});

test('time_scopes_compare_in_utc', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));

    $past = Event::factory()->startingAt(Clock::now()->subHours(3), hours: 2)->create();
    $ongoing = Event::factory()->startingAt(Clock::now()->subHour(), hours: 2)->create();
    $upcoming = Event::factory()->startingAt(Clock::now()->addHour(), hours: 2)->create();

    // New York is four hours behind: an app-timezone Carbon bound as a string would shift every
    // boundary by four hours and swap these sets.
    expect(Event::query()->upcoming()->pluck('id')->all())->toBe([$upcoming->getKey()])
        ->and(Event::query()->ongoing()->pluck('id')->all())->toBe([$ongoing->getKey()])
        ->and(Event::query()->past()->pluck('id')->all())->toBe([$past->getKey()])
        ->and(Event::query()->between(Clock::now()->subMinutes(30), Clock::now()->addMinutes(30))->pluck('id')->all())->toBe([$ongoing->getKey()])
        ->and(Event::query()->startingBetween(Clock::now(), Clock::now()->addHours(2))->pluck('id')->all())->toBe([$upcoming->getKey()]);

    // A naked string is read in events.defaults.timezone (else app.timezone, here New York):
    // 09:00–09:30 New York is 13:00–13:30 UTC, which only the upcoming event overlaps.
    expect(Event::query()->between('2026-06-01 09:00', '2026-06-01 09:30')->pluck('id')->all())->toBe([$upcoming->getKey()]);

    config()->set('events.defaults.timezone', 'UTC');
    expect(Event::query()->between('2026-06-01 11:30', '2026-06-01 11:45')->pluck('id')->all())->toBe([$ongoing->getKey()]);
});

test('on_local_date_uses_each_events_calendar', function (): void {
    // 23:30 on the 17th in Bratislava is 21:30 UTC; 20:00 on the 17th in Los Angeles is 03:00
    // UTC on the 18th. Both happen on the 17th in their own calendars.
    $bratislava = Event::factory()->startingAt(CarbonImmutable::parse('2026-07-17 23:30', 'Europe/Bratislava'), hours: 1)->create(['timezone' => 'Europe/Bratislava']);
    $angeles = Event::factory()->startingAt(CarbonImmutable::parse('2026-07-17 20:00', 'America/Los_Angeles'), hours: 2)->create(['timezone' => 'America/Los_Angeles']);

    expect(Event::query()->onLocalDate('2026-07-17')->orderBy('id')->pluck('id')->all())->toBe([$bratislava->getKey(), $angeles->getKey()])
        // The Bratislava event runs past midnight local time.
        ->and(Event::query()->onLocalDate('2026-07-18')->pluck('id')->all())->toBe([$bratislava->getKey()])
        ->and(Event::query()->onLocalDate(CarbonImmutable::parse('2026-07-16'))->pluck('id')->all())->toBe([]);
});

test('local_dates_follow_the_timezone', function (): void {
    $event = Event::factory()->startingAt(CarbonImmutable::parse('2026-07-17 23:30', 'Europe/Bratislava'), hours: 2)->create(['timezone' => 'Europe/Bratislava']);

    expect($event->starts_on)->toBe('2026-07-17')
        ->and($event->ends_on)->toBe('2026-07-18')
        ->and($event->startsAtLocal()->format('Y-m-d H:i T'))->toBe('2026-07-17 23:30 CEST')
        ->and($event->endsAtLocal()->format('H:i'))->toBe('01:30');

    // Changing the timezone (instants kept) recomputes the local dates.
    $event->update(['timezone' => 'America/New_York']);

    expect($event->fresh()?->starts_on)->toBe('2026-07-17')
        ->and($event->fresh()?->startsAtLocal()->format('H:i'))->toBe('17:30');

    // An event ending exactly at local midnight does not occupy the next day.
    $midnight = Event::factory()->startingAt(CarbonImmutable::parse('2026-07-20 22:00', 'Europe/Bratislava'), hours: 2)->create(['timezone' => 'Europe/Bratislava']);
    expect($midnight->ends_on)->toBe('2026-07-20');
});

test('immutable_dates_still_store_utc_timestamps', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'));
    // A host that made every date immutable (`Date::use(CarbonImmutable::class)`).
    Date::use(CarbonImmutable::class);

    try {
        $event = Scenarios::event();
        $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();
        $draft = Scenarios::event(publish: false);
        Events::for($draft)->delete();

        expect(substr((string) rawEvent($event)['created_at'], 0, 19))->toBe('2026-06-01 12:00:00')
            ->and(substr((string) rawEvent($draft)['deleted_at'], 0, 19))->toBe('2026-06-01 12:00:00')
            ->and(Rsvp::query()->find($rsvp->getKey())?->created_at?->toIso8601String())->toBe('2026-06-01T12:00:00+00:00');
    } finally {
        Date::useDefault();
    }
});
