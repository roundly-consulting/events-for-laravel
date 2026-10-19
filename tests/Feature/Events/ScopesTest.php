<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * @return list<int|string>
 */
function ids(Builder $query): array
{
    return $query->orderBy('id')->pluck('id')->all();
}

test('discovery_scopes_filter_as_documented', function (): void {
    $organizer = User::factory()->create();
    $attendee = User::factory()->create();
    $hall = Venue::factory()->create();

    $atHall = Event::factory()->organizedBy($organizer)->at($hall)->published()->capacity(2)->create(['going_count' => 5]);
    $online = Event::factory()->online()->published()->create();
    $mixed = Event::factory()->published()->create(['online_url' => 'https://meet.example.com/m', 'attendance_mode' => AttendanceMode::Mixed]);
    $paid = Event::factory()->published()->create(['going_count' => 9]);
    Events::for($paid)->tiers()->add('Paid', price: Money::ofMajor('5', 'EUR'));
    $full = Event::factory()->published()->capacity(1)->create(['seats_taken' => 1]);
    $draft = Event::factory()->create();

    Events::for($atHall)->rsvp($attendee)->going();
    Events::for($online)->rsvp($attendee)->interested();

    expect(ids(Event::query()->atVenue($hall)))->toBe([$atHall->getKey()])
        ->and(ids(Event::query()->atVenue($hall->getKey())))->toBe([$atHall->getKey()])
        ->and(ids(Event::query()->online()))->toBe([$online->getKey()])
        ->and(ids(Event::query()->inPerson()->whereKey([$atHall->getKey(), $online->getKey(), $mixed->getKey()])))->toBe([$atHall->getKey(), $mixed->getKey()])
        ->and(ids(Event::query()->organizedBy($organizer)))->toBe([$atHall->getKey()])
        ->and(ids(Event::query()->attendedBy($attendee)))->toBe([$atHall->getKey()])
        ->and(ids(Event::query()->interestedBy($attendee)))->toBe([$online->getKey()])
        ->and(Event::query()->free()->pluck('id')->contains($paid->getKey()))->toBeFalse()
        ->and(Event::query()->withSeatsAvailable()->pluck('id')->contains($full->getKey()))->toBeFalse()
        ->and(Event::query()->withSeatsAvailable()->pluck('id')->contains($online->getKey()))->toBeTrue()
        ->and(Event::query()->popular()->first()?->is($paid))->toBeTrue()
        ->and(ids(Event::query()->inPhase(EventPhase::Draft)))->toBe([$draft->getKey()])
        ->and(Event::query()->active()->count())->toBe(5)
        ->and(Event::query()->published()->count())->toBe(5)
        ->and(Event::query()->listed()->count())->toBe(6);
});

test('search_escapes_like_wildcards', function (): void {
    $jazz = Event::factory()->create(['title' => ['en' => 'Jazz Night', 'sk' => 'Jazzový večer'], 'description' => ['en' => 'Quartet']]);
    $percent = Event::factory()->create(['title' => ['en' => '100% Techno']]);
    Event::factory()->create(['title' => ['en' => '1000 Techno fans']]);

    expect(ids(Event::query()->search('jazz')))->toBe([$jazz->getKey()])
        ->and(ids(Event::query()->search('NIGHT')))->toBe([$jazz->getKey()])
        ->and(ids(Event::query()->search('večer')))->toBe([$jazz->getKey()])
        ->and(ids(Event::query()->search('quartet')))->toBe([$jazz->getKey()])
        // A wildcard in the term matches itself, never "anything".
        ->and(ids(Event::query()->search('100%')))->toBe([$percent->getKey()])
        ->and(ids(Event::query()->search('_')))->toBe([])
        // A blank term adds no constraint.
        ->and(Event::query()->search('  ')->count())->toBe(3);
});
