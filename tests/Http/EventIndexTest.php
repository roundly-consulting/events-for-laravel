<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    Scenarios::freeze();
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();
    Events::categories()->make('Music')->findOrCreate();
    Events::categories()->make('Jazz')->under(Events::categories()->find('music'))->findOrCreate();

    $near = Venue::factory()->create(['latitude' => 48.15, 'longitude' => 17.11]);
    $far = Venue::factory()->create(['latitude' => 48.72, 'longitude' => 21.26]);

    $this->events = [
        'jazz' => Event::factory()->organizedBy($this->organizer)->at($near)->published()->categorized('jazz')->labelled(['Live', 'Outdoor'])->create(['title' => ['en' => 'Jazz, Blues & Soul'], 'going_count' => 9]),
        'talk' => Event::factory()->at($far)->published()->labelled(['Live'])->startingAt(Scenarios::now()->addDays(10))->create(['title' => ['en' => 'Laravel talk']]),
        'online' => Event::factory()->online()->published()->startingAt(Scenarios::now()->addDays(2))->create(),
        'past' => Event::factory()->at($near)->published()->startingAt(Scenarios::now()->subDays(5))->create(),
        'full' => Event::factory()->at($far)->published()->capacity(1)->create(['seats_taken' => 1]),
        'postponed' => Event::factory()->at($near)->postponed()->create(),
    ];
    Events::for($this->events['talk'])->tiers()->add('Paid', price: Money::ofMajor('5', 'EUR'));
    Events::for($this->events['online'])->rsvp($this->attendee)->going();
    Events::for($this->events['talk'])->rsvp($this->attendee)->interested();
    $this->draft = Event::factory()->organizedBy($this->organizer)->create();
});

afterEach(fn () => Scenarios::thaw());

/**
 * @return list<string>
 */
function apiUuids(object $test, string $query, ?User $as = null): array
{
    app('auth')->forgetGuards();
    $test = $as === null ? $test : $test->actingAs($as);

    return array_column($test->getJson('/api/events?per_page=100&'.$query)->assertOk()->json('data'), 'id');
}

test('scope_and_api_filter_parity', function (): void {
    $here = new Coordinates(48.1439, 17.1097);
    $uuids = static fn ($query): array => $query->orderBy('starts_at')->pluck('uuid')->all();
    $browse = static fn () => Events::browse();

    $cases = [
        'filter[when]=upcoming' => $browse()->upcoming(),
        'filter[when]=past' => $browse()->past(),
        'filter[when]=ongoing' => $browse()->ongoing(),
        'filter[category]=music' => $browse()->inCategory('music'),
        'filter[labels]=live,outdoor' => $browse()->labelled(['live', 'outdoor'], all: true),
        'filter[near]=48.1439,17.1097,25' => $browse()->near($here, km: 25),
        'filter[mode]=online' => $browse()->withAttendanceMode(AttendanceMode::Online),
        'filter[free]=true' => $browse()->free(),
        'filter[available]=1' => $browse()->withSeatsAvailable(),
        'filter[search]=Jazz, Blues' => $browse()->search('Jazz, Blues'),
        'filter[status]=postponed' => $browse()->inPhase(EventPhase::Postponed),
        'filter[venue]='.$this->events['jazz']->venue_id => $browse()->atVenue($this->events['jazz']->venue_id),
        'filter[organizer]=user:'.$this->organizer->getKey() => $browse()->organizedBy($this->organizer),
        'filter[date]='.$this->events['jazz']->starts_on => $browse()->onLocalDate($this->events['jazz']->starts_on),
        'filter[from]=2027-01-05 00:00&filter[to]=2027-01-20 00:00' => $browse()->between('2027-01-05 00:00', '2027-01-20 00:00'),
    ];

    foreach ($cases as $query => $scope) {
        expect(apiUuids($this, $query))->toEqualCanonicalizing($uuids($scope), $query);
    }

    // An empty tag filter adds no constraint.
    expect(apiUuids($this, 'filter[category]='))->toEqualCanonicalizing($uuids($browse()));
});

it('lists the viewer own events with filter[mine]', function (): void {
    expect(apiUuids($this, 'filter[mine]=organizing', $this->organizer))->toEqualCanonicalizing([$this->events['jazz']->uuid, $this->draft->uuid])
        ->and(apiUuids($this, 'filter[mine]=attending', $this->attendee))->toBe([$this->events['online']->uuid])
        ->and(apiUuids($this, 'filter[mine]=interested', $this->attendee))->toBe([$this->events['talk']->uuid])
        ->and(apiUuids($this, 'filter[mine]=organizing'))->toBe([]);

    $this->getJson('/api/events?filter[mine]=everything')->assertStatus(422);
});

it('sorts by start, popularity and distance', function (): void {
    expect(apiUuids($this, 'sort=-starts_at'))->toBe(Events::browse()->orderByDesc('starts_at')->orderByDesc('id')->pluck('uuid')->all())
        ->and(apiUuids($this, 'sort=starts_at'))->toBe(Events::browse()->orderBy('starts_at')->orderBy('id')->pluck('uuid')->all())
        ->and(apiUuids($this, 'sort=created_at'))->toBe(Events::browse()->orderBy('created_at')->orderBy('id')->pluck('uuid')->all())
        ->and(apiUuids($this, 'sort=-popularity')[0])->toBe($this->events['jazz']->uuid)
        // Nearest first: the three events at the near venue, then the far ones.
        ->and(array_slice(apiUuids($this, 'filter[near]=48.1439,17.1097,500&sort=distance'), 0, 3))->toEqualCanonicalizing([$this->events['jazz']->uuid, $this->events['past']->uuid, $this->events['postponed']->uuid])
        ->and(array_slice(apiUuids($this, 'filter[near]=48.1439,17.1097,500&sort=-distance'), 0, 2))->toEqualCanonicalizing([$this->events['talk']->uuid, $this->events['full']->uuid]);

    $this->getJson('/api/events?sort=distance')->assertStatus(400)->assertJsonPath('code', 'invalid_query');
    $this->getJson('/api/events?filter[near]=91,17')->assertStatus(422)->assertJsonPath('code', 'invalid_query');
    $this->getJson('/api/events?filter[near]=48,17,9000')->assertStatus(422);
    $this->getJson('/api/events?filter[when]=someday')->assertStatus(422);
    $this->getJson('/api/events?filter[mode]=telepathy')->assertStatus(422);
    $this->getJson('/api/events?filter[status]=sleeping')->assertStatus(422);
    $this->getJson('/api/events?filter[date]=tomorrow')->assertStatus(422);
    $this->getJson('/api/events?filter[free]=maybe')->assertStatus(422);
    $this->getJson('/api/events?filter[nope]=1')->assertStatus(400);
    $this->getJson('/api/events?per_page=1000')->assertStatus(422);
});

test('browse_has_no_n_plus_one', function (): void {
    $count = function (?User $viewer): int {
        app('auth')->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($viewer === null ? $this : $this->actingAs($viewer))->getJson('/api/events?per_page=100')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = [$count(null), $count($this->attendee)];

    $venue = Venue::factory()->create();
    Event::factory()->count(25)->at($venue)->published()->categorized('jazz')->labelled(['Live'])->create();
    Events::for(Event::query()->latest('id')->first() ?? $this->draft)->tiers()->add('Floor');

    expect([$count(null), $count($this->attendee)])->toBe($few);
});
