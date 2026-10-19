<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\EventFeedQuery;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\IcsParser;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze();
    config()->set('app.url', 'https://events.example.com');
    $this->organizer = User::factory()->create(['name' => 'Laravel Bratislava', 'email' => 'hello@example.com']);
});

afterEach(fn () => Scenarios::thaw());

/**
 * @return list<array<string, list<string>>>
 */
function icsEvents(string $ics): array
{
    return IcsParser::parse($ics)['events'];
}

test('ics_is_rfc5545', function (): void {
    Events::categories()->make('Music')->findOrCreate();
    $venue = Venue::factory()->create(['name' => 'Blue Note', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'latitude' => 48.1439, 'longitude' => 17.1097, 'timezone' => 'Europe/Bratislava']);
    $title = 'Jazz, Blues; and Soul — „Večer“ '.str_repeat('é', 40);
    $event = Events::make($title)
        ->description("Line one\nLine two, with a comma; and a semicolon \\ backslash")
        ->organizedBy($this->organizer)
        ->at($venue)
        ->startsAt('2027-11-14 20:00')
        ->duration('PT3H')
        ->category('music')
        ->labels(['Live'])
        ->publish();

    $calendar = (string) Events::for($event)->ics();
    $parsed = IcsParser::parse($calendar);
    $vevent = $parsed['events'][0];

    expect($parsed['calendar']['VERSION'])->toBe(['2.0'])
        ->and($parsed['calendar']['PRODID'][0])->toContain('Events for Laravel')
        ->and($parsed['events'])->toHaveCount(1)
        ->and($vevent['UID'])->toBe([$event->uuid.'@events.example.com'])
        // Publishing is the first announced change.
        ->and($vevent['SEQUENCE'])->toBe(['1'])
        ->and($vevent['DTSTART'])->toBe(['20271114T190000Z'])
        ->and($vevent['DTEND'])->toBe(['20271114T220000Z'])
        ->and($vevent['DTSTAMP'][0])->toMatch('/^\d{8}T\d{6}Z$/')
        ->and($vevent['LAST-MODIFIED'][0])->toMatch('/^\d{8}T\d{6}Z$/')
        ->and(IcsParser::text($vevent['SUMMARY'][0]))->toBe($title)
        ->and(IcsParser::text($vevent['DESCRIPTION'][0]))->toBe("Line one\nLine two, with a comma; and a semicolon \\ backslash")
        ->and(IcsParser::text($vevent['LOCATION'][0]))->toContain('Blue Note')
        ->and($vevent['GEO'])->toBe(['48.1439;17.1097'])
        ->and($vevent['STATUS'])->toBe(['CONFIRMED'])
        ->and($vevent['CATEGORIES'])->toBe(['Music,Live'])
        ->and($vevent['ORGANIZER;CN=Laravel Bratislava'])->toBe(['mailto:hello@example.com'])
        ->and(array_key_exists('ATTENDEE', $vevent))->toBeFalse();

    // A response: text/calendar, an attachment named by the slug.
    $response = Events::for($event)->ics()->toResponse(request());
    expect($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="')
        ->and($event->toIcs()->content)->toBe($response->getContent());
});

test('ics_text_cannot_break_out', function (): void {
    $evil = "Evil\r\nEND:VEVENT\r\nBEGIN:VEVENT\nSUMMARY:Injected\x00\x07;,\\";
    $organizer = User::factory()->create(['name' => "Mal\"lory: CEO; Inc,\r\nX-INJECT:1", 'email' => 'mallory@example.com']);
    $event = Events::make($evil)->description($evil)->organizedBy($organizer)->startsAt('2027-11-14 20:00')->publish();

    $calendar = (string) Events::for($event)->ics();
    $vevents = icsEvents($calendar);

    expect($vevents)->toHaveCount(1)
        ->and(substr_count($calendar, "\r\nBEGIN:VEVENT\r\n"))->toBe(1)
        ->and(substr_count($calendar, "\r\nEND:VEVENT\r\n"))->toBe(1)
        ->and($calendar)->not->toContain("\nSUMMARY:Injected")
        ->and($calendar)->not->toContain("\x00")
        // A title is one line; the description keeps its breaks — escaped, never raw.
        ->and(IcsParser::text($vevents[0]['SUMMARY'][0]))->toBe('Evil END:VEVENT BEGIN:VEVENT SUMMARY:Injected ;,'.chr(92))
        ->and(IcsParser::text($vevents[0]['DESCRIPTION'][0]))->toBe("Evil\nEND:VEVENT\nBEGIN:VEVENT\nSUMMARY:Injected;,".chr(92))
        ->and(array_keys($vevents[0]))->toContain('ORGANIZER;CN="Mallory: CEO; Inc,X-INJECT:1"')
        ->and(array_key_exists('X-INJECT', $vevents[0]))->toBeFalse();
});

test('ics_lists_skip_events_the_viewer_cannot_see', function (): void {
    $public = Scenarios::event(organizer: $this->organizer);
    $private = Scenarios::event(organizer: $this->organizer);
    Events::for($private)->update(new UpdateEventData(visibility: Visibility::Private));
    $draft = Scenarios::event(organizer: $this->organizer, publish: false);
    $invitee = User::factory()->create();
    EventInvitation::factory()->for($private)->invitee($invitee)->create();

    $uids = static fn (?User $viewer): array => array_map(
        static fn (array $vevent): string => explode('@', $vevent['UID'][0])[0],
        icsEvents((string) Events::ics(Event::query()->get(), $viewer)),
    );

    expect($uids(null))->toBe([$public->uuid])
        ->and($uids($invitee))->toEqualCanonicalizing([$public->uuid, $private->uuid])
        // A manager sees the draft, but drafts are never exported.
        ->and($uids($this->organizer))->toEqualCanonicalizing([$public->uuid, $private->uuid])
        ->and(Events::ics([$draft], $this->organizer)->content)->not->toContain('BEGIN:VEVENT');
});

test('feeds_never_include_private_or_unlisted', function (): void {
    Events::categories()->make('Music')->findOrCreate();
    $venue = Venue::factory()->create();
    $listed = Event::factory()->organizedBy($this->organizer)->at($venue)->published()->categorized('music')->labelled(['Live'])->create();
    $online = Event::factory()->organizedBy($this->organizer)->online('https://meet.example.com/secret-room')->published()->create();
    $unlisted = Event::factory()->published()->unlisted()->create();
    $private = Event::factory()->published()->private()->create();
    $draft = Event::factory()->create();
    $past = Event::factory()->published()->startingAt(Scenarios::now()->subDays(3))->create();
    $cancelled = Event::factory()->cancelled()->create();
    $longCancelled = Event::factory()->cancelled()->create(['cancelled_at' => Scenarios::now()->subDays(40)]);

    $feed = Events::feeds()->public();
    $uids = array_map(static fn (array $vevent): string => explode('@', $vevent['UID'][0])[0], icsEvents((string) $feed));
    $parsed = IcsParser::parse((string) $feed);

    expect($uids)->toEqualCanonicalizing([$listed->uuid, $online->uuid, $cancelled->uuid])
        ->and($parsed['calendar']['METHOD'])->toBe(['PUBLISH'])
        ->and($parsed['calendar']['X-WR-CALNAME'])->toBe([config('events.feeds.name')])
        ->and($parsed['calendar']['REFRESH-INTERVAL;VALUE=DURATION'])->toBe(['PT1H'])
        // A feed is anonymous: never a join link.
        ->and((string) $feed)->not->toContain('secret-room')
        ->and($feed->toResponse(request())->headers->get('Content-Disposition'))->toStartWith('inline;');

    $only = static fn (EventFeedQuery $query): array => array_map(static fn (array $vevent): string => explode('@', $vevent['UID'][0])[0], icsEvents((string) Events::feeds()->public($query)));

    expect($only(new EventFeedQuery(category: 'music')))->toBe([$listed->uuid])
        ->and($only(new EventFeedQuery(label: 'live')))->toBe([$listed->uuid])
        ->and($only(new EventFeedQuery(organizer: $this->organizer)))->toEqualCanonicalizing([$listed->uuid, $online->uuid])
        ->and($only(new EventFeedQuery(venue: $venue->getKey())))->toBe([$listed->uuid])
        ->and(IcsParser::parse((string) Events::feeds()->public(new EventFeedQuery(name: 'Jazz in Bratislava')))['calendar']['X-WR-CALNAME'])->toBe(['Jazz in Bratislava']);

    expect([$unlisted, $private, $draft, $past, $longCancelled])->each->toBeInstanceOf(Event::class);
});

test('personal_feed_excludes_private_by_default', function (): void {
    $attendee = User::factory()->create();
    $public = Event::factory()->online('https://meet.example.com/my-room')->published()->create();
    $private = Event::factory()->published()->private()->create();
    $interested = Event::factory()->published()->create();
    EventInvitation::factory()->for($private)->invitee($attendee)->create();
    Events::for($public)->rsvp($attendee)->going();
    Events::for($private)->rsvp($attendee)->going();
    Events::for($interested)->rsvp($attendee)->interested();

    $uids = static fn (string $ics): array => array_map(static fn (array $vevent): string => explode('@', $vevent['UID'][0])[0], icsEvents($ics));

    expect($uids((string) $attendee->eventFeed()))->toBe([$public->uuid])
        ->and($uids((string) Events::feeds()->for($attendee, includePrivate: true)))->toEqualCanonicalizing([$public->uuid, $private->uuid])
        // Theirs to see: the join link of an event they are going to.
        ->and((string) $attendee->eventFeed())->toContain('my-room');
});

test('all_day_events_export_dates_to_ics_and_json_ld', function (): void {
    $festival = Events::make('Festival')->organizedBy($this->organizer)->timezone('Europe/Bratislava')
        ->allDay('2027-07-16', through: '2027-07-18')->publish();

    $vevent = icsEvents((string) Events::for($festival)->ics())[0];
    $jsonLd = Events::jsonLd($festival);

    expect($vevent['DTSTART;VALUE=DATE'])->toBe(['20270716'])
        // ICS: the exclusive end day; JSON-LD: the inclusive last day.
        ->and($vevent['DTEND;VALUE=DATE'])->toBe(['20270719'])
        ->and($jsonLd['startDate'] ?? null)->toBe('2027-07-16')
        ->and($jsonLd['endDate'] ?? null)->toBe('2027-07-18');
});

test('calendar_status_and_sequence_follow_the_lifecycle', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);
    $vevent = static fn (): array => icsEvents((string) Events::for($event->fresh() ?? $event)->ics())[0];

    expect($vevent()['STATUS'])->toBe(['CONFIRMED']);

    Events::for($event)->postpone('Storm');
    expect($vevent()['STATUS'])->toBe(['TENTATIVE']);

    Events::for($event)->reschedule('2027-01-20 20:00');
    expect($vevent()['STATUS'])->toBe(['CONFIRMED'])
        ->and((int) $vevent()['SEQUENCE'][0])->toBeGreaterThan(0);

    $sequence = (int) $vevent()['SEQUENCE'][0];
    Events::for($event)->cancel('Flood');
    expect($vevent()['STATUS'])->toBe(['CANCELLED'])
        ->and((int) $vevent()['SEQUENCE'][0])->toBe($sequence + 1);
});
