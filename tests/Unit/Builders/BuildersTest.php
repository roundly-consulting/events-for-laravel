<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('builds a venue with every setter', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user);   // the owner must allow the actor

    $builder = Events::venues()->make(['en' => 'Hall', 'sk' => 'Sála'])
        ->address(street: 'Main 1', city: 'Košice', region: 'KE', postalCode: '040 01', country: 'sk')
        ->formattedAddress('Main 1, Košice')
        ->coordinates(new Coordinates(48.72, 21.26))
        ->timezone('Europe/Bratislava')
        ->capacity(300)
        ->ownedBy($team)
        ->meta(['floor' => 1])
        ->by($user);

    $data = $builder->toData();
    expect($data->owner?->is($team))->toBeTrue()
        ->and($data->actor?->is($user))->toBeTrue()
        ->and($data->address->formatted)->toBe('Main 1, Košice');

    $venue = $builder->create();
    expect($venue->formattedAddress())->toBe('Main 1, Košice')
        ->and($venue->region)->toBe('KE')
        ->and($venue->meta)->toBe(['floor' => 1])
        ->and($venue->owner?->is($team))->toBeTrue()
        ->and($venue->toArray())->not->toHaveKey('owner_type');
});

it('edits an event with every setter', function (): void {
    $event = Scenarios::event(capacity: 10, publish: false);
    $venue = Venue::factory()->create();

    $editor = Events::for($event)->edit()
        ->title('Renamed')
        ->description('About it')
        ->startsAt('2027-02-11 19:00')
        ->endsAt('2027-02-11 22:00')
        ->doorsOpenAt('2027-02-11 18:30')
        ->timezone('Europe/Bratislava')
        ->at($venue)
        ->online('https://meet.example.com/e')
        ->attendanceMode(AttendanceMode::Mixed)
        ->visibility(Visibility::Unlisted)
        ->capacity(20)
        ->maxGuests(2)
        ->waitlist(false, autoPromote: false)
        ->rsvpWindow('2027-01-15 09:00', '2027-02-10 12:00')
        ->guestList(GuestList::Attendees)
        ->showCounts(false)
        ->reminders(['P1D'])
        ->meta(['k' => 'v']);

    expect($editor->toData()->duration)->toBe(NotGiven::Value);
    $saved = $editor->save();

    expect($saved->title)->toBe('Renamed')
        ->and($saved->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-02-11 19:00')
        ->and($saved->doors_open_at?->setTimezone('Europe/Bratislava')->format('H:i'))->toBe('18:30')
        ->and($saved->attendance_mode)->toBe(AttendanceMode::Mixed)
        ->and($saved->visibility)->toBe(Visibility::Unlisted)
        ->and($saved->waitlist)->toBeFalse()
        ->and($saved->guest_list)->toBe(GuestList::Attendees);

    // A duration, an all-day range, a cleared description.
    Events::for($event)->edit()->duration('90 minutes')->save();
    expect($event->fresh()?->starts_at->diffInMinutes($event->fresh()?->ends_at))->toBe(90.0);

    Events::for($event)->edit()->allDay('2027-02-12', through: '2027-02-13')->description(null)->doorsOpenAt(null)->save();
    expect($event->fresh()?->all_day)->toBeTrue()
        ->and($event->fresh()?->ends_on)->toBe('2027-02-13')
        ->and($event->fresh()?->description)->toBeNull();
});

it('builds ticket types with every setter', function (): void {
    $event = Scenarios::event();

    $tier = Events::for($event)->tiers()->make('Crew')
        ->price(Money::ofMajor('5', 'EUR'))
        ->free()
        ->hidden()
        ->position(9)
        ->description(['en' => 'Backstage'])
        ->create();

    expect($tier->isFree())->toBeTrue()
        ->and($tier->hidden)->toBeTrue()
        ->and($tier->position)->toBe(9)
        ->and($tier->description)->toBe('Backstage')
        ->and($tier->seatsLeft())->toBeNull()
        ->and($tier->event?->is($event))->toBeTrue();

    expect(Events::for($event)->tiers()->make('Capped')->capacity(3)->create()->seatsLeft())->toBe(3);
});

it('names the organizer and its public page', function (): void {
    $user = User::factory()->create(['name' => 'Ada']);
    $team = Team::factory()->create(['name' => 'Laravel SK']);
    $nameless = User::factory()->create(['name' => '']);

    expect($user->eventOrganizerName())->toBe('Ada')
        ->and($user->eventOrganizerUrl())->toBeNull()
        ->and($team->eventOrganizerName())->toBe('Laravel SK')
        ->and($team->eventOrganizerUrl())->toBe('https://example.com/teams/'.$team->getKey())
        ->and($nameless->eventOrganizerName())->toBe((string) $nameless->getKey())
        // A user never manages events as another class.
        ->and(User::query()->managingEventsFor($team)->count())->toBe(0);
});
