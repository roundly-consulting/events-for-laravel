<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->organizer = User::factory()->create(['name' => 'Organizer']);
    $this->invitee = User::factory()->create(['name' => 'Invitee']);
    $this->attendee = User::factory()->create(['name' => 'Attendee']);
    $this->stranger = User::factory()->create(['name' => 'Stranger']);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $adminId = $this->admin->getKey();
    Gate::define('events.admin', static fn (User $user): bool => $user->getKey() === $adminId);
});

/**
 * Every visibility × phase, each with an invitee and an interested attendee.
 *
 * @return list<Event>
 */
function visibilityMatrix(User $organizer, User $invitee, User $attendee): array
{
    $events = [];

    foreach (['public', 'unlisted', 'private'] as $visibility) {
        foreach (['draft' => null, 'published' => 'published', 'cancelled' => 'cancelled', 'completed' => 'completed'] as $state) {
            $factory = Event::factory()->organizedBy($organizer)->state(['visibility' => $visibility]);
            $event = ($state === null ? $factory : $factory->{$state}())->create();

            EventInvitation::factory()->for($event)->invitee($invitee)->create();
            Rsvp::factory()->for($event)->by($attendee)->status(RsvpStatus::Interested)->create();

            $events[] = $event;
        }
    }

    return $events;
}

test('visibility_scope_matches_policy', function (): void {
    $events = visibilityMatrix($this->organizer, $this->invitee, $this->attendee);
    $declined = User::factory()->create();
    EventInvitation::factory()->for($events[10])->invitee($declined)->status(InvitationStatus::Revoked)->create();

    foreach ([null, $this->organizer, $this->invitee, $this->attendee, $this->stranger, $this->admin, $declined] as $viewer) {
        $byPolicy = collect($events)->filter(static fn (Event $event): bool => Gate::forUser($viewer)->allows('view', $event))->pluck('id')->sort()->values()->all();
        $byScope = Events::visibleTo($viewer)->pluck('id')->sort()->values()->all();

        expect($byScope)->toBe($byPolicy, 'viewer: '.($viewer?->name ?? 'guest'));
    }

    // Spot checks of the rule itself.
    expect(Events::visibleTo(null)->count())->toBe(6) // public + unlisted, announced
        ->and(Events::visibleTo($this->invitee)->count())->toBe(9) // + private, announced
        ->and(Events::visibleTo($this->organizer)->count())->toBe(12)
        ->and(Events::visibleTo($this->admin)->count())->toBe(12);
});

test('managers_scope_matches_policy', function (): void {
    $member = User::factory()->create(['name' => 'Member']);
    $coHost = User::factory()->create(['name' => 'Co-host']);
    $team = Team::factory()->create();
    $team->members()->attach($member);
    $hostTeam = Team::factory()->create();
    $hostMember = User::factory()->create(['name' => 'Host-team member']);
    $hostTeam->members()->attach($hostMember);

    $own = Event::factory()->organizedBy($this->organizer)->create();
    $teams = Event::factory()->organizedBy($team)->create();
    $coHosted = Event::factory()->organizedBy($this->stranger)->create();
    EventHost::factory()->for($coHosted)->host($coHost)->create();
    $teamCoHosted = Event::factory()->organizedBy($this->stranger)->published()->create();
    EventHost::factory()->for($teamCoHosted)->host($hostTeam)->create();
    $unrelated = Event::factory()->organizedBy($this->stranger)->create();

    $all = [$own, $teams, $coHosted, $teamCoHosted, $unrelated];

    foreach ([$this->organizer, $member, $coHost, $hostMember, $this->stranger, $this->admin, $this->attendee] as $user) {
        $byPolicy = collect($all)->filter(static fn (Event $event): bool => Gate::forUser($user)->allows('update', $event))->pluck('id')->sort()->values()->all();
        $byScope = Event::query()->managedBy($user)->pluck('id')->sort()->values()->all();

        expect($byScope)->toBe($byPolicy, 'user: '.$user->name);
    }

    expect(Event::query()->managedBy($member)->pluck('id')->all())->toBe([$teams->getKey()])
        ->and(Event::query()->managedBy($hostMember)->pluck('id')->all())->toBe([$teamCoHosted->getKey()])
        ->and(Event::query()->managedBy($this->admin)->count())->toBe(5)
        // Co-hosts manage the event, never its co-host list.
        ->and(Gate::forUser($coHost)->allows('manageHosts', $coHosted))->toBeFalse()
        ->and(Gate::forUser($this->stranger)->allows('manageHosts', $coHosted))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('transfer', $coHosted))->toBeTrue();

    // Undefined admin ability: nobody is an admin (fail closed).
    config()->set('events.authorization.abilities.admin', 'events.undefined');
    expect(Event::query()->managedBy($this->admin)->count())->toBe(0);
});

test('unlisted_events_are_link_only', function (): void {
    $unlisted = Event::factory()->organizedBy($this->organizer)->unlisted()->published()->create(['title' => ['en' => 'Secret Gig']]);
    $public = Event::factory()->organizedBy($this->organizer)->published()->create(['title' => ['en' => 'Open Gig']]);

    // Never listed, never slug-resolved for others…
    expect(Events::browse($this->stranger)->pluck('id')->all())->toBe([$public->getKey()])
        ->and(Events::findBySlug('secret-gig', $this->stranger))->toBeNull()
        ->and(Events::findBySlug('secret-gig'))->toBeNull()
        ->and(Events::findBySlug('open-gig')?->is($public))->toBeTrue();

    // …but the uuid link works, and the policy lets its holder view it.
    expect(Events::find($unlisted->uuid)?->is($unlisted))->toBeTrue()
        ->and(Gate::forUser($this->stranger)->allows('view', $unlisted))->toBeTrue();

    // Managers, invitees and attendees find it by slug.
    EventInvitation::factory()->for($unlisted)->invitee($this->invitee)->create();
    Rsvp::factory()->for($unlisted)->by($this->attendee)->status(RsvpStatus::Going)->create();

    expect(Events::findBySlug('secret-gig', $this->organizer)?->is($unlisted))->toBeTrue()
        ->and(Events::findBySlug('secret-gig', $this->invitee)?->is($unlisted))->toBeTrue()
        ->and(Events::findBySlug('secret-gig', $this->attendee)?->is($unlisted))->toBeTrue();

    // A draft is nobody's but the managers'.
    Event::factory()->organizedBy($this->organizer)->create(['title' => ['en' => 'Draft Gig']]);
    expect(Events::findBySlug('draft-gig', $this->stranger))->toBeNull()
        ->and(Events::findBySlug('draft-gig', $this->organizer))->not->toBeNull();
});

test('route_binding_uses_the_uuid', function (): void {
    $event = Event::factory()->published()->create();

    expect($event->getRouteKeyName())->toBe('uuid')
        ->and($event->getRouteKey())->toBe($event->uuid)
        ->and((new Event)->resolveRouteBinding($event->uuid)?->is($event))->toBeTrue()
        // The sequential id and the slug never bind.
        ->and((new Event)->resolveRouteBinding('01a155e7-70d9-727f-ab52-1bcbe1010883'))->toBeNull();

    // A malformed value is a 404 without a query (a PostgreSQL uuid column would answer 500).
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    foreach ([(string) $event->getKey(), (string) $event->slug, 'not-a-uuid'] as $value) {
        expect(fn () => (new Event)->resolveRouteBinding($value))->toThrow(ModelNotFoundException::class);
    }

    expect($queries)->toBe(0);
});

it('browses announced, listed events with what a list renders', function (): void {
    $public = Event::factory()->published()->create();
    $cancelled = Event::factory()->cancelled()->create();
    Event::factory()->create(); // draft
    Event::factory()->private()->published()->create();

    $browsed = Events::browse()->get();

    expect($browsed->pluck('id')->sort()->values()->all())->toBe(collect([$public->getKey(), $cancelled->getKey()])->sort()->values()->all())
        ->and($browsed->first()?->relationLoaded('venue'))->toBeTrue()
        ->and($browsed->first()?->relationLoaded('organizer'))->toBeTrue();
});
