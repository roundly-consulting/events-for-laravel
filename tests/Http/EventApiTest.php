<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze();
    $this->organizer = User::factory()->create(['name' => 'Ada']);
    Events::categories()->make('Music')->findOrCreate();
    Events::categories()->make('Jazz')->under(Events::categories()->find('music'))->findOrCreate();
});

afterEach(fn () => Scenarios::thaw());

it('creates and announces an event with a new venue, ticket types and tags', function (): void {
    $response = $this->actingAs($this->organizer)->postJson('/api/events', [
        'title' => ['en' => 'Jazz Night', 'sk' => 'Jazzový večer'],
        'description' => ['en' => 'A live quartet.'],
        'venue' => ['name' => 'Blue Note', 'city' => 'Bratislava', 'country' => 'sk', 'coordinates' => '48.1439,17.1097', 'timezone' => 'Europe/Bratislava'],
        'starts_at' => '2027-02-12 20:00',
        'duration' => 'PT3H',
        'capacity' => 120,
        'category' => 'jazz',
        'labels' => ['Live', 'Late night'],
        'tiers' => [['name' => 'Free entry', 'key' => 'free'], ['name' => 'Supporter', 'price' => '15', 'currency' => 'EUR', 'capacity' => 30]],
        'publish' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Jazz Night')
        ->assertJsonPath('data.phase', EventPhase::Scheduled->value)
        ->assertJsonPath('data.starts_at', '2027-02-12T20:00:00+01:00')
        ->assertJsonPath('data.venue.name', 'Blue Note')
        ->assertJsonPath('data.venue.coordinates.latitude', 48.1439)
        ->assertJsonPath('data.category.slug', 'jazz')
        ->assertJsonPath('data.category.path', ['Music'])
        ->assertJsonPath('data.labels.0.slug', 'live')
        ->assertJsonPath('data.tiers.1.price.decimal', '15.00')
        ->assertJsonPath('data.organizer', ['id' => $this->organizer->getKey(), 'type' => 'User'])
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.viewer.can.manage', true);

    expect(Event::query()->count())->toBe(1)
        ->and(Venue::query()->count())->toBe(1);
});

it('refuses invalid input with one error shape', function (): void {
    $this->actingAs($this->organizer)->postJson('/api/events', ['title' => ''])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['message', 'code', 'errors' => ['title', 'starts_at']]);

    // A curated miss is the package's 422, never a class name.
    $this->actingAs($this->organizer)->postJson('/api/events', ['title' => 'X', 'starts_at' => '2027-02-12 20:00', 'category' => 'polka'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('lets guests read but never write', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);

    $this->getJson("/api/events/{$event->uuid}")->assertOk()->assertJsonMissingPath('data.status')->assertJsonPath('data.viewer.rsvp', null);
    $this->postJson('/api/events', ['title' => 'X', 'starts_at' => '2027-02-12 20:00'])->assertUnauthorized();
    $this->postJson("/api/events/{$event->uuid}/cancel")->assertUnauthorized();
});

it('changes an event, its category and labels in one request', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);

    $this->actingAs($this->organizer)->patchJson("/api/events/{$event->uuid}?notify=0", [
        'title' => 'Renamed',
        'capacity' => 50,
        'category' => 'music',
        'labels' => ['Outdoor'],
        'notify' => false,
    ])->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.capacity', 50)
        ->assertJsonPath('data.category.slug', 'music')
        ->assertJsonPath('data.labels.0.slug', 'outdoor');

    // A new start on an announced event goes through reschedule.
    $this->actingAs($this->organizer)->patchJson("/api/events/{$event->uuid}", ['starts_at' => '2027-03-01 20:00'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_event');

    // Clearing the category; a stranger may not change anything.
    $this->actingAs($this->organizer)->patchJson("/api/events/{$event->uuid}", ['category' => null])->assertOk()->assertJsonPath('data.category', null);
    $this->actingAs(User::factory()->create())->patchJson("/api/events/{$event->uuid}", ['title' => 'Mine'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('runs every status verb', function (): void {
    $event = Events::make('Draft')->organizedBy($this->organizer)->startsAt('2027-02-12 20:00')->create();
    $as = $this->actingAs($this->organizer);

    $as->postJson("/api/events/{$event->uuid}/publish")->assertOk()->assertJsonPath('data.phase', 'scheduled');
    $as->postJson("/api/events/{$event->uuid}/unpublish")->assertOk()->assertJsonPath('data.phase', 'draft');
    $as->postJson("/api/events/{$event->uuid}/publish")->assertOk();
    $as->postJson("/api/events/{$event->uuid}/postpone", ['reason' => 'Storm'])->assertOk()->assertJsonPath('data.phase', 'postponed');
    $as->postJson("/api/events/{$event->uuid}/reschedule", ['starts_at' => '2027-03-05 21:00', 'reason' => 'New date'])->assertOk()
        ->assertJsonPath('data.phase', 'rescheduled')
        ->assertJsonPath('data.schema_status', 'EventRescheduled')
        ->assertJsonPath('data.previous_starts_at', '2027-02-12T20:00:00+00:00');
    $as->postJson("/api/events/{$event->uuid}/move-online", ['url' => 'https://stream.example.com/x'])->assertOk()
        ->assertJsonPath('data.attendance_mode', 'online')
        ->assertJsonPath('data.online_url', 'https://stream.example.com/x');
    $as->postJson("/api/events/{$event->uuid}/cancel", ['reason' => 'Flood'])->assertOk()->assertJsonPath('data.phase', 'cancelled');
    // A cancelled event cannot complete: the lifecycle's denial, with its codes.
    $as->postJson("/api/events/{$event->uuid}/complete")->assertStatus(422)->assertJsonPath('code', 'transition_denied')->assertJsonStructure(['denials']);

    $other = Scenarios::event(organizer: $this->organizer);
    Scenarios::freeze($other->starts_at->addMinutes(30)->format('Y-m-d H:i:s'));
    $as->postJson("/api/events/{$other->uuid}/complete")->assertOk()->assertJsonPath('data.phase', 'completed');
});

it('deletes and restores an event, hiding it meanwhile', function (): void {
    $event = Events::make('Draft')->organizedBy($this->organizer)->startsAt('2027-02-12 20:00')->create();

    $this->actingAs($this->organizer)->deleteJson("/api/events/{$event->uuid}")->assertNoContent();
    $this->actingAs($this->organizer)->getJson("/api/events/{$event->uuid}")->assertNotFound()->assertJsonPath('code', 'event_not_found');
    $this->actingAs(User::factory()->create())->postJson("/api/events/{$event->uuid}/restore")->assertNotFound();
    $this->actingAs($this->organizer)->postJson("/api/events/{$event->uuid}/restore")->assertOk()->assertJsonPath('data.id', $event->uuid);
});

it('sets and removes the poster', function (): void {
    Storage::fake('local');
    $event = Scenarios::event(organizer: $this->organizer);

    $this->actingAs($this->organizer)->put("/api/events/{$event->uuid}/poster", ['poster' => UploadedFile::fake()->image('poster.jpg', 1200, 900)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.poster.variants.card', fn (?string $url): bool => $url !== null);

    $this->actingAs($this->organizer)->put("/api/events/{$event->uuid}/poster", ['poster' => UploadedFile::fake()->image('tiny.jpg', 100, 100)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->actingAs($this->organizer)->deleteJson("/api/events/{$event->uuid}/poster")->assertOk()->assertJsonPath('data.poster', null);
});

it('manages ticket types, hidden ones for managers only', function (): void {
    $event = Scenarios::event(organizer: $this->organizer);
    $as = $this->actingAs($this->organizer);

    $crew = $as->postJson("/api/events/{$event->uuid}/tiers", ['name' => 'Crew', 'key' => 'crew', 'hidden' => true])->assertCreated()->assertJsonPath('data.hidden', true)->json('data');
    $paid = $as->postJson("/api/events/{$event->uuid}/tiers", ['name' => 'Paid', 'price' => '9.50'])->assertCreated()->json('data');
    $as->patchJson("/api/events/{$event->uuid}/tiers/{$paid['id']}", ['capacity' => 10])->assertOk()->assertJsonPath('data.capacity', 10);
    $as->patchJson("/api/events/{$event->uuid}/tiers/crew", ['name' => 'Crew & friends'])->assertOk()->assertJsonPath('data.name', 'Crew & friends');
    $as->postJson("/api/events/{$event->uuid}/tiers/reorder", ['order' => [$paid['id'], $crew['id']]])->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson("/api/events/{$event->uuid}/tiers")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.hidden');
    $this->actingAs($this->organizer)->getJson("/api/events/{$event->uuid}/tiers")->assertJsonCount(2, 'data');

    // Another event's tier is not found.
    $other = Scenarios::event(organizer: $this->organizer);
    $as->patchJson("/api/events/{$other->uuid}/tiers/{$paid['id']}", ['capacity' => 1])->assertNotFound();
    $as->deleteJson("/api/events/{$event->uuid}/tiers/crew")->assertNoContent();
});

it('lets team organizers create through the reference', function (): void {
    $team = Team::factory()->create();
    $team->members()->attach($this->organizer);

    $this->actingAs($this->organizer)->postJson('/api/events', [
        'title' => 'Meetup', 'starts_at' => '2027-02-12 18:00', 'organizer' => ['type' => 'team', 'id' => $team->getKey()],
    ])->assertCreated()->assertJsonPath('data.organizer', ['id' => $team->getKey(), 'type' => 'Team']);

    $this->actingAs(User::factory()->create())->postJson('/api/events', [
        'title' => 'Meetup', 'starts_at' => '2027-02-12 18:00', 'organizer' => ['type' => 'team', 'id' => $team->getKey()],
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_organizer');
});
