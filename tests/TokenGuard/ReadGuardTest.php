<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Http\Middleware\UseConfiguredGuard;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * Each request runs as a fresh client: in a test the app (and the guard a previous request's
 * middleware chose) survives between requests.
 */
function freshClient(): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

test('read_routes_see_a_user_of_the_configured_guard', function (): void {
    $organizer = User::factory()->create();
    $invitee = User::factory()->create();
    $event = Events::make('Private dinner')->organizedBy($organizer)
        ->at(Venue::factory()->create(['timezone' => 'Europe/Bratislava']))
        ->startsAt('2027-02-10 20:00')->duration('2 hours')->visibility(Visibility::Private)->publish();
    Events::for($event)->invitations()->send($invitee);
    Events::for($event)->rsvp($invitee)->going();

    // The invitee's token opens their private event, with their own RSVP.
    $this->getJson("/api/events/{$event->uuid}", ['X-User' => (string) $invitee->getKey()])->assertOk()
        ->assertJsonPath('data.viewer.rsvp.status', 'going');

    // The organizer's token lists what they organize.
    freshClient();
    $this->getJson('/api/events?filter[mine]=organizing', ['X-User' => (string) $organizer->getKey()])->assertOk()
        ->assertJsonCount(1, 'data');

    // No token: still a guest, never refused.
    freshClient();
    $this->getJson("/api/events/{$event->uuid}")->assertNotFound();
    $this->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
});

test('read_routes_take_the_guards_auth_middleware_names', function (): void {
    expect(UseConfiguredGuard::guards())->toBe(['api-token']);

    config()->set('events.routes.auth_middleware', ['auth:api-token, web', Authenticate::class.':admin,web', 'throttle:60,1', 'verified']);
    expect(UseConfiguredGuard::guards())->toBe(['api-token', 'web', 'admin']);

    // Plain `auth` is the default guard, which reads already use.
    config()->set('events.routes.auth_middleware', ['auth']);
    expect(UseConfiguredGuard::guards())->toBe([]);
});
