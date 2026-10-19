<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Events\EventCreated;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Exceptions\InvalidVenueException;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->venue = Venue::factory()->create(['timezone' => 'Europe/Bratislava']);
});

/**
 * @return array<string, mixed>
 */
function storedEvent(Event $event): array
{
    return collect($event->fresh()?->getAttributes() ?? [])
        // The online link is encrypted: a fresh ciphertext every write. Compared decrypted.
        ->except(['id', 'uuid', 'slug', 'online_url', 'created_at', 'updated_at'])
        ->put('online_url', $event->fresh()?->online_url)
        ->all();
}

test('builders_match_dtos', function (): void {
    $built = Events::make(['en' => 'Jazz Night', 'sk' => 'Jazzový večer'])
        ->description(['en' => 'A quartet.'])
        ->organizedBy($this->user)
        ->at($this->venue)
        ->online('https://meet.example.com/abc')
        ->startsAt('2026-11-14 20:00')
        ->duration('3 hours')
        ->doorsOpenAt('2026-11-14 19:30')
        ->unlisted()
        ->capacity(120)
        ->maxGuests(2)
        ->waitlist(true, autoPromote: false)
        ->rsvpWindow(opensAt: '2026-11-01 09:00', closesAt: '2026-11-14 18:00')
        ->guestList(GuestList::Attendees)
        ->showCounts(false)
        ->reminders(['P1D', 'PT2H'])
        ->meta(['crm' => 7])
        ->create();

    $fromDto = Events::create(new CreateEventData(
        title: ['en' => 'Jazz Night', 'sk' => 'Jazzový večer'],
        organizer: $this->user,
        schedule: new ScheduleData(startsAt: '2026-11-14 20:00', duration: 'PT3H', doorsOpenAt: '2026-11-14 19:30'),
        description: ['en' => 'A quartet.'],
        venue: $this->venue->getKey(),
        onlineUrl: 'https://meet.example.com/abc',
        visibility: Visibility::Unlisted,
        capacity: 120,
        maxGuests: 2,
        waitlist: true,
        waitlistAutoPromote: false,
        rsvpOpensAt: '2026-11-01 09:00',
        rsvpClosesAt: '2026-11-14 18:00',
        guestList: GuestList::Attendees,
        showCounts: false,
        reminders: ['P1D', 'PT2H'],
        meta: ['crm' => 7],
    ));

    expect(storedEvent($built))->toEqual(storedEvent($fromDto))
        ->and($built->attendance_mode)->toBe(AttendanceMode::Mixed)
        ->and($built->starts_at->toIso8601String())->toBe('2026-11-14T19:00:00+00:00')
        ->and($built->doors_open_at?->toIso8601String())->toBe('2026-11-14T18:30:00+00:00')
        ->and($built->phase)->toBe(EventPhase::Draft);

    // The editor builds the same DTO the update path takes.
    $editor = Events::for($built)->edit()->title(['en' => 'Late Jazz'])->capacity(150)->waitlist(false);

    expect($editor->toData()->capacity)->toBe(150)
        ->and($editor->save()->capacity)->toBe(150)
        ->and($built->fresh()?->waitlist)->toBeFalse();
});

it('validates builder setters eagerly', function (Closure $setter, string $exception): void {
    expect(fn () => $setter(Events::make('X')))->toThrow($exception);
})->with([
    'timezone' => [fn ($builder) => $builder->timezone('Mars/Olympus'), InvalidEventScheduleException::class],
    'duration' => [fn ($builder) => $builder->duration('soon-ish'), InvalidEventScheduleException::class],
    'url' => [fn ($builder) => $builder->online('ftp://example.com'), InvalidEventException::class],
    'max guests' => [fn ($builder) => $builder->maxGuests(-1), InvalidEventException::class],
    'capacity' => [fn ($builder) => $builder->capacity(-5), InvalidEventException::class],
    'reminders' => [fn ($builder) => $builder->reminders(['PT1M']), InvalidEventException::class],
    'no organizer' => [fn ($builder) => $builder->startsAt('2027-01-01 10:00')->create(), InvalidEventException::class],
]);

test('builder_setter_order_does_not_change_the_instant', function (): void {
    $tokyo = Venue::factory()->create(['timezone' => 'Asia/Tokyo']);

    $timeFirst = Events::make('A')->organizedBy($this->user)->startsAt('2026-11-14 20:00')->at($tokyo)->create();
    $venueFirst = Events::make('B')->organizedBy($this->user)->at($tokyo)->startsAt('2026-11-14 20:00')->create();

    expect($timeFirst->starts_at->toIso8601String())->toBe('2026-11-14T11:00:00+00:00')
        ->and($venueFirst->starts_at->toIso8601String())->toBe($timeFirst->starts_at->toIso8601String());
});

test('carbon_and_offset_strings_are_instants', function (): void {
    $iso = Events::make('A')->organizedBy($this->user)->at($this->venue)->startsAt('2026-11-14T19:00:00.000Z')->create();
    $carbon = Events::make('B')->organizedBy($this->user)->at($this->venue)->startsAt(CarbonImmutable::parse('2026-11-14 14:00', 'America/New_York'))->create();

    expect($iso->starts_at->toIso8601String())->toBe('2026-11-14T19:00:00+00:00')
        ->and($carbon->starts_at->toIso8601String())->toBe('2026-11-14T19:00:00+00:00')
        ->and($iso->startsAtLocal()->format('H:i'))->toBe('20:00');
});

test('timezone_defaults_follow_the_chain', function (): void {
    $explicit = Events::make('A')->organizedBy($this->user)->at($this->venue)->timezone('Asia/Tokyo')->startsAt('2026-11-14 20:00')->create();
    $venue = Events::make('B')->organizedBy($this->user)->at($this->venue)->startsAt('2026-11-14 20:00')->create();

    config()->set('events.defaults.timezone', 'America/Chicago');
    $config = Events::make('C')->organizedBy($this->user)->startsAt('2026-11-14 20:00')->create();

    config()->set('events.defaults.timezone', null);
    config()->set('app.timezone', 'Europe/Lisbon');
    $app = Events::make('D')->organizedBy($this->user)->startsAt('2026-11-14 20:00')->create();

    expect([$explicit->timezone, $venue->timezone, $config->timezone, $app->timezone])
        ->toBe(['Asia/Tokyo', 'Europe/Bratislava', 'America/Chicago', 'Europe/Lisbon']);

    // Changing the venue's timezone later never moves its events.
    $this->venue->update(['timezone' => 'Asia/Tokyo']);
    expect($venue->fresh()?->timezone)->toBe('Europe/Bratislava')
        ->and($venue->fresh()?->starts_at->toIso8601String())->toBe($venue->starts_at->toIso8601String());
});

test('schedule_rules_are_enforced', function (Closure $build, string $message): void {
    expect(fn () => $build(Events::make('X')->organizedBy($this->user)->at($this->venue))->create())
        ->toThrow(InvalidEventScheduleException::class, $message);
})->with([
    'no start' => [fn ($b) => $b, 'The event needs a start time.'],
    'end before start' => [fn ($b) => $b->startsAt('2026-11-14 20:00')->endsAt('2026-11-14 19:00'), 'The event must end after it starts.'],
    'zero length' => [fn ($b) => $b->startsAt('2026-11-14 20:00')->endsAt('2026-11-14 20:00'), 'The event must end after it starts.'],
    'too long' => [fn ($b) => $b->startsAt('2026-11-01 20:00')->endsAt('2026-12-15 20:00'), 'An event may not last longer than P31D.'],
    'doors after start' => [fn ($b) => $b->startsAt('2026-11-14 20:00')->doorsOpenAt('2026-11-14 20:30'), 'Doors must open within 12 hours before the start.'],
    'doors too early' => [fn ($b) => $b->startsAt('2026-11-14 20:00')->doorsOpenAt('2026-11-14 07:00'), 'Doors must open within 12 hours before the start.'],
    'doors on all-day' => [fn ($b) => $b->allDay('2026-07-17')->doorsOpenAt('2026-07-17 08:00'), 'An all-day event has no doors time.'],
    'day range reversed' => [fn ($b) => $b->allDay('2026-07-19', through: '2026-07-17'), 'The last day must not come before the first day.'],
    'dst gap' => [fn ($b) => $b->startsAt('2026-03-29 02:30'), 'does not exist in Europe/Bratislava'],
    'rsvp window' => [fn ($b) => $b->startsAt('2026-11-14 20:00')->rsvpWindow(opensAt: '2026-11-10 10:00', closesAt: '2026-11-09 10:00'), 'The RSVP window must open before it closes.'],
]);

it('defaults the end to events.defaults.duration so ends_at is never null', function (): void {
    $event = Events::make('X')->organizedBy($this->user)->at($this->venue)->startsAt('2026-11-14 20:00')->create();

    expect($event->starts_at->diffInMinutes($event->ends_at))->toBe(120.0);

    config()->set('events.defaults.duration', 'PT45M');
    $short = Events::make('Y')->organizedBy($this->user)->at($this->venue)->startsAt('2026-11-14 20:00')->duration(CarbonInterval::minutes(90))->create();
    $default = Events::make('Z')->organizedBy($this->user)->at($this->venue)->startsAt('2026-11-14 20:00')->create();

    expect($short->starts_at->diffInMinutes($short->ends_at))->toBe(90.0)
        ->and($default->starts_at->diffInMinutes($default->ends_at))->toBe(45.0);
});

test('all_day_events_store_local_midnights', function (): void {
    $festival = Events::make('Summer Festival')->organizedBy($this->user)->at($this->venue)
        ->allDay('2026-07-17', through: '2026-07-19')
        ->create();

    // Local midnight in Bratislava (CEST, +02:00) of the first day, and of the day after the
    // last one (exclusive).
    expect($festival->all_day)->toBeTrue()
        ->and($festival->starts_at->toIso8601String())->toBe('2026-07-16T22:00:00+00:00')
        ->and($festival->ends_at->toIso8601String())->toBe('2026-07-19T22:00:00+00:00')
        ->and($festival->starts_on)->toBe('2026-07-17')
        ->and($festival->ends_on)->toBe('2026-07-19')
        ->and($festival->doors_open_at)->toBeNull();

    $single = Events::make('Picnic')->organizedBy($this->user)->at($this->venue)->allDay('2026-08-01')->create();

    expect($single->starts_on)->toBe('2026-08-01')
        ->and($single->ends_on)->toBe('2026-08-01')
        ->and($single->starts_at->diffInHours($single->ends_at))->toBe(24.0);

    // An all-day event spanning the October fall-back is 25 hours long, and still ends at midnight.
    $autumn = Events::make('Harvest')->organizedBy($this->user)->at($this->venue)->allDay('2026-10-25')->create();
    expect($autumn->starts_at->diffInHours($autumn->ends_at))->toBe(25.0)
        ->and($autumn->endsAtLocal()->format('Y-m-d H:i'))->toBe('2026-10-26 00:00');
});

test('all_day_events_survive_a_midnight_dst_gap', function (): void {
    // Havana springs forward at 00:00 on 2026-03-08: that day has no 00:00. The all-day event
    // starts at the first instant of the day (01:00 local) instead of being refused.
    $event = Events::make('Carnival')->organizedBy($this->user)->timezone('America/Havana')
        ->allDay('2026-03-08', through: '2026-03-09')
        ->create();

    expect($event->starts_at->toIso8601String())->toBe('2026-03-08T05:00:00+00:00')
        ->and($event->startsAtLocal()->format('Y-m-d H:i'))->toBe('2026-03-08 01:00')
        ->and($event->starts_on)->toBe('2026-03-08')
        ->and($event->ends_on)->toBe('2026-03-09');
});

test('attendance_mode_is_derived_and_validated', function (): void {
    $offline = Events::make('A')->organizedBy($this->user)->at($this->venue)->startsAt('2027-01-01 10:00')->create();
    $online = Events::make('B')->organizedBy($this->user)->online('https://meet.example.com/b')->startsAt('2027-01-01 10:00')->create();
    $mixed = Events::make('C')->organizedBy($this->user)->at($this->venue)->online('https://meet.example.com/c')->startsAt('2027-01-01 10:00')->create();
    $tba = Events::make('D')->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create();

    expect([$offline->attendance_mode, $online->attendance_mode, $mixed->attendance_mode, $tba->attendance_mode])
        ->toBe([AttendanceMode::Offline, AttendanceMode::Online, AttendanceMode::Mixed, AttendanceMode::Offline]);

    // An explicit mode must agree with the venue and link.
    $agreeing = Events::make('E')->organizedBy($this->user)->online('https://meet.example.com/e')->attendanceMode(AttendanceMode::Online)->startsAt('2027-01-01 10:00')->create();
    expect($agreeing->attendance_mode)->toBe(AttendanceMode::Online);

    expect(fn () => Events::make('F')->organizedBy($this->user)->at($this->venue)->attendanceMode(AttendanceMode::Online)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(InvalidEventException::class, 'The attendance mode "online" does not match the venue and online link.');

    // The join link is encrypted at rest and hidden from arrays.
    expect((string) DB::table('events')->where('id', $online->getKey())->value('online_url'))->not->toContain('meet.example.com')
        ->and($online->fresh()?->online_url)->toBe('https://meet.example.com/b')
        ->and($online->toArray())->not->toHaveKey('online_url');
});

it('validates content, venue and organizer input', function (): void {
    expect(fn () => Events::make('')->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(InvalidEventException::class, 'The event needs a title.')
        ->and(fn () => Events::make(str_repeat('x', 256))->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(InvalidEventException::class, 'The event title may not be longer than 255 characters.')
        ->and(fn () => Events::make('X')->description(str_repeat('y', 20_001))->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(InvalidEventException::class, 'The event description may not be longer than 20000 characters.')
        ->and(fn () => Events::make('X')->organizedBy($this->user)->at(999_999)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(VenueNotFoundException::class);

    // Unsupported locales are dropped; a string is the current locale.
    $event = Events::make(['en' => 'Hi', 'xx' => 'Nope'])->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create();
    expect($event->getTranslations('title'))->toBe(['en' => 'Hi']);
});

it('fires EventCreated after commit', function (): void {
    EventBus::fake([EventCreated::class]);

    $event = Events::make('X')->organizedBy($this->user)->startsAt('2027-01-01 10:00')->by($this->user)->create();

    EventBus::assertDispatched(EventCreated::class, static fn (EventCreated $created): bool => $created->event->is($event));
});

it('creates and publishes in one go', function (): void {
    $event = Events::make('X')->organizedBy($this->user)->at($this->venue)->startsAt('2027-01-01 10:00')->publish();

    expect($event->phase)->toBe(EventPhase::Scheduled)
        ->and($event->fresh()?->published_at)->not->toBeNull();
});

it('keeps manual slugs strict', function (): void {
    $first = Events::make('Jazz')->slug(['en' => 'jazz-special'])->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create();

    expect($first->getTranslation('slug', 'en'))->toBe('jazz-special')
        ->and(fn () => Events::make('Other')->slug(['en' => 'jazz-special'])->organizedBy($this->user)->startsAt('2027-01-01 10:00')->create())
        ->toThrow(InvalidEventException::class, 'The slug "jazz-special" is already taken.');
});

it('refuses partial or out-of-range venue coordinates in the builder', function (): void {
    expect(fn () => Events::venues()->make('V')->coordinates(91.0, 10.0))->toThrow(InvalidVenueException::class, 'The coordinates are out of range.')
        ->and(fn () => Events::venues()->make('V')->coordinates(48.0))->toThrow(InvalidVenueException::class, 'Give both the latitude and the longitude, or neither.');
});
