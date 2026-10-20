<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    Scenarios::freeze();
    config()->set('events.urls.event', 'https://example.com/events/{slug}');
    $this->organizer = User::factory()->create(['name' => 'Jazz Club']);
    $this->venue = Venue::factory()->create([
        'name' => 'Blue Note', 'street' => 'Hlavná 1', 'city' => 'Bratislava', 'region' => 'BA', 'postal_code' => '811 01',
        'country_code' => 'SK', 'latitude' => 48.1439, 'longitude' => 17.1097, 'timezone' => 'Europe/Bratislava',
    ]);
});

afterEach(fn () => Scenarios::thaw());

/**
 * @param  Closure(Event): mixed  $then
 */
function jsonLdEvent(User $organizer, ?Venue $venue = null, ?string $onlineUrl = null, ?Closure $then = null): Event
{
    $builder = Events::make(['en' => 'Jazz Night', 'sk' => 'Jazzový večer'])
        ->description(['en' => '<p>A live <b>quartet</b>.</p>'])
        ->organizedBy($organizer)
        ->timezone('Europe/Bratislava')
        ->startsAt('2027-02-12 20:00')
        ->duration('PT3H')
        ->doorsOpenAt('2027-02-12 19:00');

    if ($venue !== null) {
        $builder->at($venue);
    }

    if ($onlineUrl !== null) {
        $builder->online($onlineUrl);
    }

    $event = $builder->publish();

    if ($then !== null) {
        $then($event);
    }

    return $event->fresh() ?? $event;
}

test('json_ld_shape', function (string $case, Closure $build, Closure $assert): void {
    $assert($build($this), $this);
})->with([
    'scheduled, in person, ticket types' => [
        'scheduled',
        function ($test): Event {
            return jsonLdEvent($test->organizer, $test->venue, then: function (Event $event): void {
                Events::for($event)->tiers()->add('Free entry', key: 'free');
                Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), capacity: 1);
                Events::for($event)->tiers()->make('Crew')->hidden()->create();
                Events::for($event)->tiers()->make('Early bird')->price(Money::ofMajor('9.50', 'EUR'))->onSale(from: '2027-01-20 10:00')->create();
                Events::for($event)->labels()->attach(['Live', 'Late night']);
            });
        },
        function (Event $event): void {
            $data = Events::jsonLd($event);

            expect($data['@context'])->toBe('https://schema.org')
                ->and($data['@type'])->toBe('Event')
                ->and($data['name'])->toBe('Jazz Night')
                ->and($data['description'])->toBe('A live quartet.')
                ->and($data['startDate'])->toBe('2027-02-12T20:00:00+01:00')
                ->and($data['endDate'])->toBe('2027-02-12T23:00:00+01:00')
                ->and($data['doorTime'])->toBe('2027-02-12T19:00:00+01:00')
                ->and($data['eventStatus'])->toBe('https://schema.org/EventScheduled')
                ->and($data['eventAttendanceMode'])->toBe('https://schema.org/OfflineEventAttendanceMode')
                ->and($data['location'])->toBe([
                    '@type' => 'Place',
                    'name' => 'Blue Note',
                    'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Hlavná 1', 'addressLocality' => 'Bratislava', 'addressRegion' => 'BA', 'postalCode' => '811 01', 'addressCountry' => 'SK'],
                    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 48.1439, 'longitude' => 17.1097],
                ])
                ->and(array_column($data['offers'], 'name'))->toBe(['Free entry', 'Supporter', 'Early bird'])
                ->and(array_column($data['offers'], 'price'))->toBe(['0', '15.00', '9.50'])
                ->and(array_column($data['offers'], 'priceCurrency'))->toBe(['EUR', 'EUR', 'EUR'])
                ->and($data['offers'][2]['availability'])->toBe('https://schema.org/PreOrder')
                ->and($data['offers'][2]['validFrom'])->toBe('2027-01-20T10:00:00+01:00')
                ->and($data['offers'][0]['availability'])->toBe('https://schema.org/InStock')
                ->and($data['offers'][0]['url'])->toBe('https://example.com/events/jazz-night')
                ->and($data['organizer'])->toBe(['@type' => 'Organization', 'name' => 'Jazz Club'])
                ->and($data['keywords'])->toBe('Live, Late night')
                ->and($data['url'])->toBe('https://example.com/events/jazz-night');

            // Translated in the current locale.
            app()->setLocale('sk');
            expect(Events::jsonLd($event)['name'] ?? null)->toBe('Jazzový večer');
        },
    ],
    'postponed, online' => [
        'postponed',
        fn ($test): Event => jsonLdEvent($test->organizer, onlineUrl: 'https://meet.example.com/secret', then: fn (Event $event) => Events::for($event)->postpone('Storm')),
        function (Event $event): void {
            $data = Events::jsonLd($event);

            expect($data['eventStatus'])->toBe('https://schema.org/EventPostponed')
                ->and($data['eventAttendanceMode'])->toBe('https://schema.org/OnlineEventAttendanceMode')
                // The page, never the join link.
                ->and($data['location'])->toBe(['@type' => 'VirtualLocation', 'url' => 'https://example.com/events/jazz-night'])
                ->and(json_encode($data))->not->toContain('secret')
                // A tierless event offers free entry.
                ->and($data['offers'])->toBe([['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock', 'url' => 'https://example.com/events/jazz-night']]);

            config()->set('events.seo.json_ld.expose_online_url', 'on');
            expect(Events::jsonLd($event)['location']['url'] ?? null)->toBe('https://meet.example.com/secret');
        },
    ],
    'rescheduled, mixed' => [
        'rescheduled',
        fn ($test): Event => jsonLdEvent($test->organizer, $test->venue, 'https://meet.example.com/x', fn (Event $event) => Events::for($event)->reschedule('2027-03-05 21:00')),
        function (Event $event): void {
            $data = Events::jsonLd($event);

            expect($data['eventStatus'])->toBe('https://schema.org/EventRescheduled')
                ->and($data['previousStartDate'])->toBe('2027-02-12T20:00:00+01:00')
                ->and($data['startDate'])->toBe('2027-03-05T21:00:00+01:00')
                ->and($data['eventAttendanceMode'])->toBe('https://schema.org/MixedEventAttendanceMode')
                ->and(array_column($data['location'], '@type'))->toBe(['Place', 'VirtualLocation']);
        },
    ],
    'cancelled' => [
        'cancelled',
        fn ($test): Event => jsonLdEvent($test->organizer, $test->venue, then: fn (Event $event) => Events::for($event)->cancel('Flood')),
        function (Event $event): void {
            $data = Events::jsonLd($event);

            // The dates are kept.
            expect($data['eventStatus'])->toBe('https://schema.org/EventCancelled')
                ->and($data['startDate'])->toBe('2027-02-12T20:00:00+01:00');
        },
    ],
    'moved online' => [
        'moved',
        fn ($test): Event => jsonLdEvent($test->organizer, $test->venue, then: fn (Event $event) => Events::for($event)->moveOnline('https://stream.example.com')),
        fn (Event $event) => expect(Events::jsonLd($event)['eventStatus'] ?? null)->toBe('https://schema.org/EventMovedOnline'),
    ],
    'completed, a person organizes' => [
        'completed',
        fn ($test): Event => jsonLdEvent($test->organizer, $test->venue, then: function (Event $event): void {
            Scenarios::freeze('2027-02-12 20:30:00');
            Events::for($event)->complete();
        }),
        function (Event $event): void {
            config()->set('events.seo.json_ld.organizer_type', 'Person');
            config()->set('events.seo.json_ld.type', 'MusicEvent');
            $data = Events::jsonLd($event);

            expect($data['eventStatus'])->toBe('https://schema.org/EventScheduled')
                ->and($data['@type'])->toBe('MusicEvent')
                ->and($data['organizer']['@type'])->toBe('Person');
        },
    ],
    'all day' => [
        'all-day',
        fn ($test): Event => Events::make('Festival')->organizedBy($test->organizer)->at($test->venue)->allDay('2027-07-16', through: '2027-07-18')->publish(),
        function (Event $event): void {
            $data = Events::jsonLd($event);

            expect($data['startDate'])->toBe('2027-07-16')
                ->and($data['endDate'])->toBe('2027-07-18')
                ->and(array_key_exists('doorTime', $data))->toBeFalse();
        },
    ],
]);

test('json_ld_visibility_rules', function (): void {
    $public = jsonLdEvent($this->organizer, $this->venue);
    $draft = Events::make('Draft')->organizedBy($this->organizer)->startsAt('2027-02-12 20:00')->create();
    $unlisted = jsonLdEvent($this->organizer, $this->venue, then: fn (Event $event) => Events::for($event)->update(new UpdateEventData(visibility: Visibility::Unlisted)));
    $private = jsonLdEvent($this->organizer, $this->venue, then: fn (Event $event) => Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private)));

    expect(Events::jsonLd($public))->toBeArray()
        ->and($public->toJsonLd())->toBe(Events::for($public)->jsonLd())
        ->and(Events::jsonLd($draft))->toBeNull()
        ->and(Events::jsonLd($unlisted))->toBeNull()
        ->and(Events::jsonLd($private))->toBeNull()
        ->and((string) $private->renderJsonLd())->toBe('');

    config()->set('events.seo.json_ld.unlisted', 'on');
    expect(Events::jsonLd($unlisted))->toBeArray()
        ->and(Events::jsonLd($private))->toBeNull();

    // The Blade directive renders the script tag, and nothing for a private event.
    $html = Blade::render('@eventJsonLd($event)', ['event' => $public]);
    expect($html)->toStartWith('<script type="application/ld+json">')
        ->and(trim(Blade::render('@eventJsonLd($event)', ['event' => $private])))->toBe('');
});

test('json_ld_cannot_break_out', function (): void {
    $event = Events::make('</script><script>alert(1)</script><!-- & \' "')->organizedBy($this->organizer)->startsAt('2027-02-12 20:00')->publish();

    $html = (string) $event->renderJsonLd();

    expect(substr_count(strtolower($html), '</script'))->toBe(1)
        ->and($html)->not->toContain('<!--')
        ->and($html)->toContain('\\u003C/script\\u003E')
        ->and($html)->toContain('\\u0026')
        ->and(json_decode((string) preg_replace('#^<script[^>]*>|</script>\s*$#', '', trim($html)), true)['name'] ?? null)->toBe('</script><script>alert(1)</script><!-- & \' "');
});

it('lists the poster in three shapes', function (): void {
    Storage::fake('local');
    $event = jsonLdEvent($this->organizer, $this->venue);
    Events::for($event)->poster(UploadedFile::fake()->image('poster.jpg', 1200, 900));

    $images = Events::jsonLd($event->fresh() ?? $event)['image'] ?? [];

    // Google's 1:1, 4:3 and 16:9 (the queued variants run inline on the sync queue).
    expect($images)->toHaveCount(3)
        ->and($images[0])->toContain('square')
        ->and($images[2])->toContain('wide');
});

it('marks sold out ticket types and a full tierless event', function (): void {
    $event = jsonLdEvent($this->organizer, $this->venue, then: function (Event $event): void {
        Events::for($event)->tiers()->add('Only one', capacity: 1, key: 'one');
        Events::for($event)->rsvp(User::factory()->create())->going(tier: 'one');
    });
    $full = Scenarios::event(capacity: 1, waitlist: false, organizer: $this->organizer);
    Events::for($full)->rsvp(User::factory()->create())->going();

    expect(Events::jsonLd($event)['offers'][0]['availability'] ?? null)->toBe('https://schema.org/SoldOut')
        ->and(Events::jsonLd($full->fresh() ?? $full)['offers'][0]['availability'] ?? null)->toBe('https://schema.org/SoldOut');
});

it('refuses bad structured-data settings', function (): void {
    $event = jsonLdEvent($this->organizer, $this->venue);

    config()->set('events.seo.json_ld.type', 'Event"><script>');
    expect(fn () => Events::jsonLd($event))->toThrow(InvalidEventsConfigurationException::class, 'events.seo.json_ld.type');

    config()->set('events.seo.json_ld.type', 'Event');
    config()->set('events.seo.json_ld.organizer_type', 'Robot');
    expect(fn () => Events::jsonLd($event))->toThrow(InvalidEventsConfigurationException::class, 'events.seo.json_ld.organizer_type');

    expect(AttendanceMode::Offline->schemaOrg())->toBe('https://schema.org/OfflineEventAttendanceMode');
});

test('json_ld_previous_start_date_of_a_rescheduled_all_day_event', function (): void {
    $event = Events::make('Summer fair')->organizedBy($this->organizer)->at($this->venue)
        ->allDay('2027-07-16', through: '2027-07-18')->publish();

    Events::for($event)->reschedule('2027-08-20');
    $jsonLd = Events::for($event->fresh() ?? $event)->jsonLd();

    // The announced first day, in the event's own calendar — not the new one again.
    expect($jsonLd['startDate'] ?? null)->toBe('2027-08-20')
        ->and($jsonLd['endDate'] ?? null)->toBe('2027-08-22')
        ->and($jsonLd['previousStartDate'] ?? null)->toBe('2027-07-16');
});
