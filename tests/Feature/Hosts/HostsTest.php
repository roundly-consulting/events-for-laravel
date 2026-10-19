<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\Events\CoHostAdded;
use RoundlyConsulting\Events\Events\CoHostRemoved;
use RoundlyConsulting\Events\Events\OrganizerTransferred;
use RoundlyConsulting\Events\Exceptions\InvalidOrganizerException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('hosts_are_added_once_and_removed', function (): void {
    EventBus::fake([CoHostAdded::class, CoHostRemoved::class]);
    $organizer = User::factory()->create();
    $alice = User::factory()->create();
    $team = Team::factory()->create();
    $event = Scenarios::event(organizer: $organizer);

    Events::for($event)->by($organizer)->hosts()->add($alice)->add($alice)->add($team);

    expect(Events::for($event)->hosts()->all()->map(static fn ($host) => $host->host?->getKey())->all())->toBe([$alice->getKey(), $team->getKey()])
        ->and(Events::for($event)->hosts()->has($alice))->toBeTrue()
        ->and($alice->coHostedEvents()->count())->toBe(1);
    EventBus::assertDispatchedTimes(CoHostAdded::class, 2);

    // The organizer is never its own co-host.
    Events::for($event)->hosts()->add($organizer);
    expect(Events::for($event)->hosts()->has($organizer))->toBeFalse();

    Events::for($event)->hosts()->remove($alice)->remove($alice);
    expect(Events::for($event)->hosts()->has($alice))->toBeFalse();
    EventBus::assertDispatchedTimes(CoHostRemoved::class, 1);
});

test('hosts_manage_the_event', function (): void {
    $organizer = User::factory()->create();
    $alice = User::factory()->create();
    $event = Scenarios::event(organizer: $organizer, publish: false);

    expect(fn () => Events::for($event)->by($alice)->publish())->toThrow(AuthorizationException::class);

    Events::for($event)->hosts()->add($alice);
    Events::for($event)->by($alice)->publish();

    expect($event->fresh()?->phase->value)->toBe('scheduled')
        ->and(Event::query()->managedBy($alice)->pluck('id')->all())->toBe([$event->getKey()]);

    // Co-hosted at creation.
    $bob = User::factory()->create();
    $meetup = $organizer->organizeEvent('Meetup')->coHostedBy($alice, $bob)->startsAt('2027-03-01 18:00')->create();
    expect(Events::for($meetup)->hosts()->has($bob))->toBeTrue()
        ->and($organizer->organizedEvents()->count())->toBe(2);
});

test('hosts_cannot_add_hosts', function (): void {
    $organizer = User::factory()->create();
    $alice = User::factory()->create();
    $event = Scenarios::event(organizer: $organizer);
    Events::for($event)->hosts()->add($alice);

    expect(fn () => Events::for($event)->by($alice)->hosts()->add(User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(fn () => Events::for($event)->by($alice)->hosts()->remove($alice))->toThrow(AuthorizationException::class)
        ->and(fn () => Events::for($event)->by($alice)->transferTo($alice))->toThrow(AuthorizationException::class);
});

test('organize_as_requires_approval', function (): void {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member);

    // A member may organize as the team; an outsider may not.
    $event = Events::make('Team night')->organizedBy($team)->by($member)->startsAt('2027-03-01 18:00')->create();
    expect($event->organizer?->is($team))->toBeTrue();

    expect(fn () => Events::make('Hijack')->organizedBy($team)->by($outsider)->startsAt('2027-03-01 18:00')->create())
        ->toThrow(InvalidOrganizerException::class, 'You may not organize events as this organizer.');

    // Without an actor it is trusted code.
    expect(Events::make('Seeded')->organizedBy($team)->startsAt('2027-03-01 18:00')->create()->organizer?->is($team))->toBeTrue();

    // The create ability gates when set.
    config()->set('events.authorization.abilities.create', 'events.create');
    expect(fn () => Events::make('Gated')->organizedBy($member)->by($member)->startsAt('2027-03-01 18:00')->create())->toThrow(AuthorizationException::class);
});

it('transfers the organizer', function (): void {
    EventBus::fake([OrganizerTransferred::class]);
    $organizer = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach([$organizer->getKey(), $member->getKey()]);
    $event = Scenarios::event(organizer: $organizer);
    Events::for($event)->hosts()->add($team);

    // The new organizer must allow the actor; a co-host that becomes organizer stops co-hosting.
    Events::for($event)->by($organizer)->transferTo($team, keepAsHost: true);

    expect($event->fresh()?->organizer?->is($team))->toBeTrue()
        ->and(Events::for($event)->hosts()->has($team))->toBeFalse()
        ->and(Events::for($event)->hosts()->has($organizer))->toBeTrue();
    EventBus::assertDispatched(OrganizerTransferred::class);

    $stranger = User::factory()->create();
    expect(fn () => Events::for($event)->by($member)->transferTo($stranger))->toThrow(InvalidOrganizerException::class);

    // To the same organizer: nothing happens.
    Events::for($event)->transferTo($team);
    EventBus::assertDispatchedTimes(OrganizerTransferred::class, 1);
});
