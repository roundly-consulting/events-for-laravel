<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\EventsServiceProvider;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Policies\EventPolicy;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze();
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->event = Scenarios::event(capacity: 10, organizer: $this->organizer);
    Events::for($this->event)->rsvp($this->attendee)->going();
});

afterEach(fn () => Scenarios::thaw());

test('managers_hold_every_management_ability', function (): void {
    foreach (['update', 'delete', 'restore', 'publish', 'unpublish', 'postpone', 'reschedule', 'cancel', 'complete', 'transition', 'moveOnline', 'manageTiers', 'invite', 'manageAttendees', 'checkIn', 'viewWaitlist', 'viewInterested', 'manageHosts', 'transfer'] as $ability) {
        expect(Gate::forUser($this->organizer)->allows($ability, $this->event))->toBeTrue($ability)
            ->and(Gate::forUser($this->stranger)->allows($ability, $this->event))->toBeFalse($ability);
    }

    expect(Gate::allows('viewAny', Event::class))->toBeTrue();
});

test('guest_list_rules_are_enforced', function (): void {
    $gate = static fn (?User $user, string $ability, Event $event): bool => Gate::forUser($user)->allows($ability, $event);

    // Hidden (default): managers only.
    expect($gate($this->organizer, 'viewAttendees', $this->event))->toBeTrue()
        ->and($gate($this->attendee, 'viewAttendees', $this->event))->toBeFalse();

    Events::for($this->event)->update(new UpdateEventData(guestList: GuestList::Attendees));
    expect($gate($this->attendee, 'viewAttendees', $this->event->fresh() ?? $this->event))->toBeTrue()
        ->and($gate($this->stranger, 'viewAttendees', $this->event->fresh() ?? $this->event))->toBeFalse();

    Events::for($this->event)->update(new UpdateEventData(guestList: GuestList::Public, showCounts: false));
    $fresh = $this->event->fresh() ?? $this->event;
    expect($gate($this->stranger, 'viewAttendees', $fresh))->toBeTrue()
        // Interest is managers' only; counts follow show_counts.
        ->and($gate($this->attendee, 'viewInterested', $fresh))->toBeFalse()
        ->and($gate($this->stranger, 'viewCounts', $fresh))->toBeFalse()
        ->and($gate($this->organizer, 'viewCounts', $fresh))->toBeTrue()
        // The join link: managers and going attendees.
        ->and($gate($this->attendee, 'viewOnlineUrl', $fresh))->toBeTrue()
        ->and($gate($this->stranger, 'viewOnlineUrl', $fresh))->toBeFalse();

    expect(fn () => Events::for($fresh)->by($this->stranger)->counts())->toThrow(AuthorizationException::class)
        ->and(Events::for($fresh)->by($this->organizer)->counts()->going)->toBe(1);
});

test('rsvp_policy_follows_phase_and_invitation', function (): void {
    $gate = static fn (?User $user, Event $event): bool => Gate::forUser($user)->allows('rsvp', $event);

    expect($gate($this->stranger, $this->event))->toBeTrue()
        ->and($gate(null, $this->event))->toBeFalse();

    $draft = Scenarios::event(organizer: $this->organizer, publish: false);
    expect($gate($this->stranger, $draft))->toBeFalse()
        ->and($gate($this->organizer, $draft))->toBeTrue();

    $private = Scenarios::event(organizer: $this->organizer);
    Events::for($private)->update(new UpdateEventData(visibility: Visibility::Private));
    EventInvitation::factory()->for($private)->invitee($this->attendee)->create();
    expect($gate($this->stranger, $private->fresh() ?? $private))->toBeFalse()
        ->and($gate($this->attendee, $private->fresh() ?? $private))->toBeTrue()
        ->and($gate($this->organizer, $private->fresh() ?? $private))->toBeTrue();

    Events::for($private)->cancel();
    expect($gate($this->organizer, $private->fresh() ?? $private))->toBeFalse();
});

test('undefined_abilities_deny', function (): void {
    // events.admin is not defined: nobody is an admin.
    expect(Gate::forUser($this->stranger)->allows('update', $this->event))->toBeFalse();

    // A create ability gates creation when set, and is denied while undefined.
    expect(Gate::forUser($this->stranger)->allows('create', Event::class))->toBeTrue()
        ->and(Gate::forUser(null)->allows('create', Event::class))->toBeFalse();
    config()->set('events.authorization.abilities.create', 'events.create');
    expect(Gate::forUser($this->stranger)->allows('create', Event::class))->toBeFalse();
    Gate::define('events.create', static fn (User $user): bool => true);
    expect(Gate::forUser($this->stranger)->allows('create', Event::class))->toBeTrue();
});

it('authorizes venues', function (): void {
    $team = Team::factory()->create();
    $team->members()->attach($this->organizer);
    $owned = Venue::factory()->ownedBy($team)->create();
    $shared = Venue::factory()->create();
    $admin = User::factory()->create();
    Gate::define('events.admin', static fn (User $user): bool => $user->is($admin));

    expect(Gate::allows('viewAny', Venue::class))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $owned))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('update', $owned))->toBeTrue()
        ->and(Gate::forUser($this->stranger)->allows('delete', $owned))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('restore', $owned))->toBeTrue()
        ->and(Gate::forUser($this->stranger)->allows('geocode', $shared))->toBeTrue()
        ->and(Gate::forUser(null)->allows('update', $shared))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', Venue::class))->toBeFalse();

    config()->set('events.authorization.abilities.venues', 'events.venues');
    expect(Gate::forUser($this->stranger)->allows('create', Venue::class))->toBeFalse()
        ->and(fn () => Events::venues()->make('X')->by($this->stranger)->create())->toThrow(AuthorizationException::class)
        ->and(fn () => Events::venues()->by($this->stranger)->update($owned, new UpdateVenueData(capacity: 1)))->toThrow(AuthorizationException::class);
});

it('falls back to the configured policy when none is registered', function (): void {
    // The host turned registration off and registered nothing: by() still never silently allows.
    // (Laravel would otherwise auto-discover the package's own policies by their names.)
    $gate = app(Illuminate\Contracts\Auth\Access\Gate::class);
    $reflection = new ReflectionProperty($gate, 'policies');
    $reflection->setValue($gate, []);
    Gate::guessPolicyNamesUsing(static fn (): array => []);

    expect(EventAuthorization::allows($this->organizer, 'update', $this->event))->toBeTrue()
        ->and(EventAuthorization::allows($this->stranger, 'update', $this->event))->toBeFalse()
        ->and(EventAuthorization::allows($this->organizer, 'create', Venue::class))->toBeTrue()
        ->and(EventAuthorization::allows($this->organizer, 'noSuchAbility', $this->event))->toBeFalse();

    config()->set('events.authorization.policy', null);
    expect(EventAuthorization::allows($this->organizer, 'update', $this->event))->toBeFalse();
});

it('registers nothing for a missing policy class, never failing the boot', function (): void {
    $register = new ReflectionMethod(EventsServiceProvider::class, 'registerPolicies');
    $provider = new EventsServiceProvider(app());

    config()->set('events.authorization.policy', 'App\\Policies\\Missing');
    $register->invoke($provider);

    config()->set('events.authorization.register_policy', 'maybe');
    $register->invoke($provider);

    config()->set('events.authorization.register_policy', 'off');
    $register->invoke($provider);

    expect(Gate::getPolicyFor(Event::class))->toBeInstanceOf(EventPolicy::class);
});
