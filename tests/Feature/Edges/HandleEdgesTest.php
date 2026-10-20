<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('venues_by_actor_create_checks_the_policy', function (): void {
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    expect(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Planted', timezone: 'Europe/Bratislava', owner: $team)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Shared', timezone: 'Europe/Bratislava')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Mine', timezone: 'Europe/Bratislava', owner: $outsider, actor: User::factory()->create())))
        ->toThrow(AuthorizationException::class);

    $mine = Events::venues()->by($outsider)->create(new VenueData(name: 'Mine', timezone: 'Europe/Bratislava', owner: $outsider));
    expect($mine->owner_id)->toBe($outsider->getKey());
});

test('handle_reads_with_an_actor_check_the_policy', function (): void {
    $organizer = User::factory()->create();
    $event = Scenarios::event(capacity: 10, organizer: $organizer);
    Events::for($event)->tiers()->add('Public', key: 'public');
    Events::for($event)->tiers()->add('Crew', hidden: true, key: 'crew');
    $attendee = User::factory()->create();
    Events::for($event)->rsvp($attendee)->going(tier: 'public');
    Events::for($event)->invitations()->send(User::factory()->create());
    $outsider = User::factory()->create();
    $as = Events::for($event)->by($outsider);

    foreach ([
        fn () => $as->attendees()->going(),
        fn () => $as->attendees()->interested(),
        fn () => $as->attendees()->checkedIn(),
        fn () => $as->attendees()->find($attendee),
        fn () => $as->invitations()->all(),
        fn () => $as->invitations()->pending(),
        fn () => $as->waitlist()->position($attendee),
    ] as $read) {
        expect($read)->toThrow(AuthorizationException::class);
    }

    // What the actor may see: their own RSVP, the visible ticket types, the hosts of a visible event.
    expect(Events::for($event)->by($attendee)->attendees()->find($attendee)?->attendee_id)->toBe($attendee->getKey())
        ->and(Events::for($event)->by($attendee)->waitlist()->position($attendee))->toBeNull()
        ->and($as->tiers()->all()->pluck('key')->all())->toBe(['public'])
        ->and($as->hosts()->all())->toHaveCount(0)
        ->and(Events::for($event)->by($organizer)->tiers()->all()->pluck('key')->all())->toBe(['public', 'crew'])
        ->and(Events::for($event)->by($organizer)->attendees()->going())->toHaveCount(1)
        ->and(Events::for($event)->tiers()->all())->toHaveCount(2);

    // A public guest list may be read by anyone who sees the event.
    Events::for($event)->update(new UpdateEventData(guestList: GuestList::Public));
    expect($as->attendees()->going())->toHaveCount(1)
        ->and(fn () => Events::for(Scenarios::event(publish: false))->by($outsider)->hosts()->all())->toThrow(AuthorizationException::class);
});

test('a_draft_made_online_is_scheduled_not_moved_online', function (): void {
    EventBus::fake([EventMovedOnline::class, EventUpdated::class]);
    $draft = Scenarios::event(publish: false);
    Events::for($draft)->moveOnline('https://meet.example.com/abc');
    Events::for($draft)->publish();
    $fresh = $draft->fresh();

    expect($fresh?->moved_online_at)->toBeNull()
        ->and($fresh?->online_url)->toBe('https://meet.example.com/abc')
        ->and(Events::jsonLd($fresh ?? $draft)['eventStatus'] ?? null)->toBe('https://schema.org/EventScheduled');
    EventBus::assertNotDispatched(EventMovedOnline::class);
    EventBus::assertDispatched(EventUpdated::class, static fn (EventUpdated $updated): bool => in_array('online_url', $updated->changed, true));

    // An announced event that moves is still "moved online".
    Events::for($fresh ?? $draft)->moveOnline('https://meet.example.com/xyz');
    expect(Events::jsonLd($draft->fresh() ?? $draft)['eventStatus'] ?? null)->toBe('https://schema.org/EventMovedOnline');
});
