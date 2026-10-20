<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Http\Resources\EventActorResource;
use RoundlyConsulting\Events\Http\Resources\EventAttendeeResource;
use RoundlyConsulting\Events\Http\Resources\EventHostResource;
use RoundlyConsulting\Events\Http\Resources\EventInviteeResource;
use RoundlyConsulting\Events\Http\Resources\EventOrganizerResource;
use RoundlyConsulting\Events\Http\Resources\VenueOwnerResource;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Tests\Fixtures\HostPersonResource;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Models\UuidUser;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * An event that mentions every kind of host model: an organizer, a co-host, an invitee, an
 * attendee checked in by hand (the actor) and a venue owned by a team.
 *
 * @return array{0: User, 1: Event}
 */
function referenceScenario(): array
{
    $organizer = User::factory()->create(['name' => 'Ada']);
    $venue = Venue::factory()->ownedBy(Team::factory()->create(['name' => 'Laravel SK']))->create();
    $event = Scenarios::event(organizer: $organizer);
    Events::for($event)->update(new UpdateEventData(venue: $venue));
    Events::for($event)->hosts()->add(User::factory()->create(['name' => 'Co Host']));
    Events::for($event)->invitations()->send(User::factory()->create(['name' => 'Invited']));
    $rsvp = Events::for($event)->rsvp(User::factory()->create(['name' => 'Going']))->going();
    Scenarios::minutesBeforeStart($event, 30);
    Events::for($event)->by($organizer)->attendees()->checkIn($rsvp);

    return [$organizer, $event];
}

/**
 * Where each role renders: the endpoint and the JSON path.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function referenceRoles(): array
{
    return [
        'organizer' => ['', 'data.organizer'],
        'host' => ['/hosts', 'data.0'],
        'attendee' => ['/attendees', 'data.0.attendee'],
        'invitee' => ['/invitations', 'data.0.invitee'],
        'actor' => ['/attendees', 'data.0.checked_in_by'],
        'venue_owner' => ['', 'data.venue.owner'],
    ];
}

test('resources_leak_no_class_names', function (): void {
    // No morph map: the stored types are full class names, and still none goes out.
    [$organizer, $event] = referenceScenario();
    $bodies = '';

    $responses = [];

    foreach (['', '/attendees', '/invitations', '/hosts', '/waitlist', '/tiers'] as $path) {
        $responses[] = $this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->assertOk()->json();
    }

    $responses[] = $this->actingAs($organizer)->getJson('/api/event-venues')->json();
    $responses[] = $this->actingAs($organizer)->getJson('/api/events')->json();
    // Re-encoded without escaped slashes: any backslash left would be a class name.
    $bodies = (string) json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($bodies)->not->toContain('\\')
        ->and($bodies)->not->toContain('Fixtures')
        ->and($bodies)->not->toContain('_type')
        // The configured aliases (was the basename until 1.1).
        ->and($bodies)->toContain('"type":"user"')
        ->and($bodies)->toContain('"type":"team"');

    foreach (referenceRoles() as $role => [$path, $json]) {
        $rendered = $this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->json($json);
        expect($rendered)->toHaveKeys(['id', 'type'], $role)
            ->and($rendered['type'])->toBeIn(['user', 'team']);
    }
});

test('reference_resources_render_the_configured_alias', function (): void {
    [$organizer, $event] = referenceScenario();

    // The alias request bodies and `alias:key` paths take — what a client reads, it can send back.
    foreach (referenceRoles() as $role => [$path, $json]) {
        expect($this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->json($json.'.type'))
            ->toBe($role === 'venue_owner' ? 'team' : 'user', $role);
    }

    $host = $this->actingAs($organizer)->getJson("/api/events/{$event->uuid}/hosts")->json('data.0');
    $this->actingAs($organizer)->deleteJson("/api/events/{$event->uuid}/hosts/{$host['type']}:{$host['id']}")->assertNoContent();

    // Every default resource: an attendees-only class takes its alias, a class in neither map its basename.
    $guest = Guest::factory()->create();
    $unmapped = new UuidUser(['id' => '0192f0c4-7c2a-7000-8000-000000000001']);

    foreach ([EventOrganizerResource::class, EventHostResource::class, EventAttendeeResource::class, EventInviteeResource::class, EventActorResource::class, VenueOwnerResource::class] as $resource) {
        expect((new $resource($guest))->resolve(request())['type'])->toBe('guest', $resource)
            ->and((new $resource($unmapped))->resolve(request())['type'])->toBe('UuidUser', $resource);
    }

    // A class listed in both maps under different aliases: the organizers' wins, as in a path.
    config()->set('events.attendees', ['member' => User::class, 'guest' => Guest::class]);
    expect((new EventAttendeeResource($organizer))->resolve(request())['type'])->toBe('user');
});

foreach (array_keys(referenceRoles()) as $role) {
    test("{$role}_resource_swap_is_honoured", function () use ($role): void {
        config()->set("events.resources.{$role}", HostPersonResource::class);
        [$organizer, $event] = referenceScenario();
        [$path, $json] = referenceRoles()[$role];

        $rendered = $this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->assertOk()->json($json);

        expect($rendered['host'] ?? null)->toBeTrue()
            ->and($rendered['name'] ?? null)->toBeString();
    });

    test("a_bad_{$role}_resource_config_throws_naming_the_key", function () use ($role): void {
        $method = lcfirst(str_replace('_', '', ucwords($role, '_'))).'Resource';

        foreach ([stdClass::class, 'App\\Http\\Resources\\Missing', 42, JsonResource::class] as $value) {
            config()->set("events.resources.{$role}", $value);
            expect(fn () => EventsConfig::{$method}())->toThrow(InvalidConfigurationException::class, "events.resources.{$role}");
        }
    });

    test("a_blank_{$role}_resource_falls_back", function () use ($role): void {
        config()->set("events.resources.{$role}", '');
        [$organizer, $event] = referenceScenario();
        [$path, $json] = referenceRoles()[$role];

        expect($this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->json($json))->toHaveKeys(['id', 'type'])->not->toHaveKey('host');

        config()->set("events.resources.{$role}", null);
        expect($this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->json($json))->toHaveKeys(['id', 'type']);
    });
}
