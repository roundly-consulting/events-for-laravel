<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Http\Middleware\RequireSignedInUser;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * Every route of the write group (route_list_is_pinned holds the full list).
 *
 * @return list<string>
 */
function writeRoutes(): array
{
    return [
        'events.store', 'events.update', 'events.destroy', 'events.restore',
        'events.publish', 'events.unpublish', 'events.postpone', 'events.reschedule', 'events.cancel', 'events.complete', 'events.move-online',
        'events.poster.update', 'events.poster.destroy',
        'events.tiers.store', 'events.tiers.reorder', 'events.tiers.update', 'events.tiers.destroy',
        'events.rsvp.show', 'events.rsvp.qr', 'events.rsvp.update', 'events.rsvp.destroy',
        'events.attendees.store', 'events.attendees.update', 'events.attendees.destroy', 'events.attendees.check-in', 'events.attendees.undo-check-in',
        'events.check-ins.store',
        'events.waitlist.index', 'events.waitlist.promote',
        'events.hosts.store', 'events.hosts.destroy',
        'events.invitations.index', 'events.invitations.store', 'events.invitations.destroy',
        'events.venues.store', 'events.venues.update', 'events.venues.destroy', 'events.venues.geocode',
    ];
}

/**
 * @return array<string, mixed>
 */
function writeSnapshot(): array
{
    return collect(['events', 'event_venues', 'event_rsvps', 'event_admission_tiers', 'event_hosts', 'event_invitations'])
        ->mapWithKeys(static fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()])
        ->all();
}

test('the write group is exactly the routes that require a signed-in user', function (): void {
    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (RoutingRoute $route): bool => in_array(RequireSignedInUser::class, $route->gatherMiddleware(), true))
        ->map(static fn (RoutingRoute $route): string => (string) $route->getName())
        ->sort()->values()->all();

    expect($guarded)->toBe(collect(writeRoutes())->sort()->values()->all());
});

test('api_writes_require_a_signed_in_user_even_without_auth_middleware', function (string $name): void {
    $organizer = User::factory()->create();
    $event = Scenarios::event(capacity: 1, organizer: $organizer);
    $tier = Events::for($event)->tiers()->add('Standard', key: 'standard');
    $attendee = User::factory()->create();
    $rsvp = Events::for($event)->rsvp($attendee)->going();
    Events::for($event)->rsvp(User::factory()->create())->going();   // waitlisted
    Events::for($event)->hosts()->add($cohost = User::factory()->create());
    Events::for($event)->invitations()->send($invitee = User::factory()->create());
    $venue = Venue::factory()->create();
    Scenarios::minutesBeforeStart($event, 30);
    $before = writeSnapshot();

    $route = Route::getRoutes()->getByName($name);
    $uri = strtr('/'.$route->uri(), [
        '{event}' => $event->uuid,
        '{tier}' => (string) $tier->getKey(),
        '{rsvp}' => (string) $rsvp->getKey(),
        '{host}' => 'user:'.$cohost->getKey(),
        '{invitee}' => 'user:'.$invitee->getKey(),
        '{venue}' => (string) $venue->getKey(),
    ]);
    $method = collect($route->methods())->reject(static fn (string $verb): bool => $verb === 'HEAD')->first();

    $response = $this->json($method, $uri, ['reason' => 'pwned', 'code' => $rsvp->checkInCode(), 'response' => 'going', 'name' => 'Hall', 'timezone' => 'UTC']);

    expect($response->status())->toBe(401)
        ->and(writeSnapshot())->toBe($before)
        ->and($event->fresh()?->phase)->toBe(EventPhase::Scheduled);
})->with(writeRoutes());

test('a_non_eloquent_guard_user_is_refused_on_writes', function (): void {
    $event = Scenarios::event(organizer: User::factory()->create());

    $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Ghost']))
        ->postJson("/api/events/{$event->uuid}/cancel", ['reason' => 'pwned'])
        ->assertStatus(401);

    expect($event->fresh()?->phase)->toBe(EventPhase::Scheduled);
});

test('reads still allow guests without auth middleware', function (): void {
    $event = Scenarios::event();

    $this->getJson("/api/events/{$event->uuid}")->assertOk();
    $this->getJson('/api/events')->assertOk();
});
