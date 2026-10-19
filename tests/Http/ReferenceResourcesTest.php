<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Tests\Fixtures\HostPersonResource;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
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
        ->and($bodies)->toContain('"type":"User"')
        ->and($bodies)->toContain('"type":"Team"');

    foreach (referenceRoles() as $role => [$path, $json]) {
        $rendered = $this->actingAs($organizer)->getJson("/api/events/{$event->uuid}{$path}")->json($json);
        expect($rendered)->toHaveKeys(['id', 'type'], $role)
            ->and($rendered['type'])->toBeIn(['User', 'Team']);
    }
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
