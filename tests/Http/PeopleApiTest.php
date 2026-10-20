<?php

declare(strict_types=1);

use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze();
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();
    $this->event = Scenarios::event(capacity: 2, organizer: $this->organizer, maxGuests: 2);
});

afterEach(fn () => Scenarios::thaw());

function guest(): void
{
    app('auth')->forgetGuards();
}

it('answers, reads and withdraws the caller own rsvp', function (): void {
    $uri = "/api/events/{$this->event->uuid}/rsvp";

    $this->actingAs($this->attendee)->getJson($uri)->assertOk()->assertJsonPath('data', null);
    $this->actingAs($this->attendee)->putJson($uri, ['response' => 'going', 'guests' => 0, 'note' => 'Vegan'])->assertOk()
        ->assertJsonPath('data.status', 'going')
        ->assertJsonPath('data.note', 'Vegan')
        ->assertJsonPath('data.check_in_code', fn (?string $code): bool => is_string($code) && strlen($code) > 20)
        ->assertJsonPath('data.qr_url', fn (?string $url): bool => str_ends_with((string) $url, '/rsvp/qr.svg'));

    // The QR image: a bearer secret, never cached.
    $qr = $this->actingAs($this->attendee)->get($uri.'/qr.svg');
    $qr->assertOk();
    expect($qr->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($qr->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($this->attendee)->putJson($uri, ['response' => 'interested'])->assertOk()->assertJsonPath('data.status', 'interested');
    $this->actingAs($this->attendee)->deleteJson($uri)->assertOk()->assertJsonPath('data.status', 'not_going')->assertJsonPath('data.check_in_code', null);
    $this->actingAs($this->attendee)->get($uri.'/qr.svg')->assertNotFound();
    $this->actingAs(User::factory()->create())->deleteJson($uri)->assertNotFound()->assertJsonPath('code', 'rsvp_not_found');
});

it('fills seats, then the waitlist, and reports a full event', function (): void {
    Events::for($this->event)->update(new UpdateEventData(waitlist: false));
    $uri = "/api/events/{$this->event->uuid}/rsvp";

    $this->actingAs(User::factory()->create())->putJson($uri, ['response' => 'going', 'guests' => 1])->assertOk();
    $this->actingAs($this->attendee)->putJson($uri, ['response' => 'going'])->assertStatus(409)->assertJsonPath('code', 'event_full');
});

it('lists attendees as the guest list allows', function (): void {
    Events::for($this->event)->rsvp($this->attendee)->going();
    Events::for($this->event)->rsvp(User::factory()->create())->interested();
    $uri = "/api/events/{$this->event->uuid}/attendees";

    // Hidden (default): managers only.
    guest();
    $this->getJson($uri)->assertForbidden();
    $this->actingAs($this->attendee)->getJson($uri)->assertForbidden();

    $managers = $this->actingAs($this->organizer)->getJson($uri)->assertOk()->assertJsonCount(1, 'data');
    // References render the configured alias, the one bodies and paths take (was the basename until 1.1).
    expect($managers->json('data.0.attendee'))->toBe(['id' => $this->attendee->getKey(), 'type' => 'user'])
        ->and(array_key_exists('check_in_code', $managers->json('data.0')))->toBeFalse();
    $this->actingAs($this->organizer)->getJson($uri.'?status=interested')->assertOk()->assertJsonCount(1, 'data');

    // Public: anyone sees who is going (names only), never the interested.
    Events::for($this->event)->update(new UpdateEventData(guestList: GuestList::Public));
    guest();
    $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guests', 0)->assertJsonMissingPath('data.0.status');
    $this->getJson($uri.'?status=interested')->assertForbidden();
});

it('lets managers add, change, remove and check in attendees', function (): void {
    Events::for($this->event)->tiers()->add('VIP', key: 'vip');
    Events::for($this->event)->tiers()->add('Floor', key: 'floor');
    $as = $this->actingAs($this->organizer);
    $uri = "/api/events/{$this->event->uuid}/attendees";

    $rsvp = $as->postJson($uri, ['attendee' => ['type' => 'user', 'id' => $this->attendee->getKey()], 'tier' => 'vip'])->assertCreated()->json('data');
    $as->patchJson("{$uri}/{$rsvp['id']}", ['tier' => 'floor', 'guests' => 1])->assertOk()->assertJsonPath('data.tier.key', 'floor')->assertJsonPath('data.guests', 1);

    Scenarios::minutesBeforeStart($this->event, 30);
    $as->postJson("{$uri}/{$rsvp['id']}/check-in", ['guests' => 1])->assertOk()
        ->assertJsonPath('data.first_check_in', true)
        ->assertJsonPath('data.method', 'manual')
        ->assertJsonPath('data.attendee.type', 'user');
    $as->deleteJson("{$uri}/{$rsvp['id']}/check-in")->assertOk()->assertJsonPath('data.checked_in_at', null);
    $as->deleteJson("{$uri}/{$rsvp['id']}")->assertNoContent();

    // Another event's RSVP is a 404, a stranger a 403.
    $other = Scenarios::event(organizer: $this->organizer);
    $foreign = Events::for($other)->rsvp(User::factory()->create())->going();
    $as->deleteJson("{$uri}/{$foreign->getKey()}")->assertNotFound();
    $as->deleteJson("{$uri}/abc")->assertNotFound();
    $this->actingAs($this->attendee)->postJson($uri, ['attendee' => ['type' => 'user', 'id' => $this->attendee->getKey()]])->assertForbidden();
});

it('checks in at the door, scoped to the event', function (): void {
    $rsvp = Events::for($this->event)->rsvp($this->attendee)->going();
    $other = Scenarios::event(organizer: $this->organizer);
    $foreign = Events::for($other)->rsvp(User::factory()->create())->going();
    Scenarios::minutesBeforeStart($this->event, 30);
    $uri = "/api/events/{$this->event->uuid}/check-ins";

    $this->actingAs($this->organizer)->postJson($uri, ['code' => $rsvp->checkInCode()])->assertCreated()->assertJsonPath('data.first_check_in', true);
    $this->actingAs($this->organizer)->postJson($uri, ['code' => $rsvp->checkInCode()])->assertOk()->assertJsonPath('data.first_check_in', false);
    $this->actingAs($this->organizer)->postJson($uri, ['code' => $foreign->checkInCode()])->assertStatus(409)
        ->assertJsonPath('code', 'check_in_refused')->assertJsonPath('reason', 'wrong_event');
    $this->actingAs($this->organizer)->postJson($uri, ['code' => 'nope'])->assertNotFound()->assertJsonPath('code', 'invalid_code');
});

test('only_managers_check_in_over_the_api', function (): void {
    $rsvp = Events::for($this->event)->rsvp($this->attendee)->going();
    Scenarios::minutesBeforeStart($this->event, 30);

    $this->actingAs($this->attendee)->postJson("/api/events/{$this->event->uuid}/check-ins", ['code' => $rsvp->checkInCode()])->assertForbidden();
    $this->actingAs($this->attendee)->postJson("/api/events/{$this->event->uuid}/attendees/{$rsvp->getKey()}/check-in")->assertForbidden();
    expect($rsvp->fresh()?->checked_in_at)->toBeNull();
});

it('shows and promotes the waitlist', function (): void {
    Events::for($this->event)->rsvp(User::factory()->create())->going(guests: 1);
    $waiting = Events::for($this->event)->rsvp($this->attendee)->going();
    $uri = "/api/events/{$this->event->uuid}/waitlist";

    $this->actingAs($this->organizer)->getJson($uri)->assertOk()->assertJsonPath('data.0.id', $waiting->getKey())->assertJsonPath('data.0.waitlist_position', 1);
    $this->actingAs($this->attendee)->getJson($uri)->assertForbidden();

    Events::for($this->event)->update(new UpdateEventData(capacity: 3, waitlistAutoPromote: false));
    $this->actingAs($this->organizer)->postJson($uri.'/promote', ['limit' => 1])->assertOk()->assertJsonPath('data.0.status', 'going');
});

it('manages co-hosts by reference', function (): void {
    $host = User::factory()->create();
    $uri = "/api/events/{$this->event->uuid}/hosts";

    $this->actingAs($this->organizer)->postJson($uri, ['person' => ['type' => 'user', 'id' => $host->getKey()]])->assertCreated()->assertJsonPath('data.type', 'user');
    $this->getJson($uri)->assertOk()->assertJsonPath('data.0.id', $host->getKey());
    // A co-host may not add co-hosts.
    $this->actingAs($host)->postJson($uri, ['person' => ['type' => 'user', 'id' => $this->attendee->getKey()]])->assertForbidden();
    $this->actingAs($this->organizer)->deleteJson("{$uri}/user:{$host->getKey()}")->assertNoContent();
    // A guest account is no organizer.
    $this->actingAs($this->organizer)->postJson($uri, ['person' => ['type' => 'guest', 'id' => Guest::factory()->create()->getKey()]])
        ->assertStatus(422)->assertJsonPath('code', 'unknown_alias');
});

it('invites and answers invitations from the link', function (): void {
    Events::for($this->event)->update(new UpdateEventData(visibility: Visibility::Private));
    $invitee = User::factory()->create();
    $uri = "/api/events/{$this->event->uuid}/invitations";

    $this->actingAs($this->organizer)->postJson($uri, ['person' => ['type' => 'user', 'id' => $invitee->getKey()], 'message' => 'Come!'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.invitee.type', 'user')->assertJsonMissingPath('data.token');
    $this->actingAs($this->organizer)->getJson($uri)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($invitee)->getJson($uri)->assertForbidden();

    $token = Events::for($this->event)->invitations()->send($invitee)->plainToken;

    // Someone else signed in cannot answer for the invitee: the uniform 404.
    $this->actingAs($this->attendee)->postJson('/api/event-invitations/accept', ['token' => $token])->assertNotFound()->assertJsonPath('code', 'invalid_invitation');
    $this->actingAs($invitee)->postJson('/api/event-invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'going');

    $second = User::factory()->create();
    $declined = Events::for($this->event)->invitations()->send($second)->plainToken;
    $this->actingAs($second)->postJson('/api/event-invitations/decline', ['token' => $declined])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->actingAs($this->organizer)->deleteJson("{$uri}/user:{$invitee->getKey()}")->assertNoContent();
});

test('invitations_cannot_be_accepted_for_someone_else_over_the_api', function (): void {
    $invitee = User::factory()->create();
    $token = Events::for($this->event)->invitations()->send($invitee)->plainToken;

    $this->actingAs($this->attendee)->postJson('/api/event-invitations/accept', ['token' => $token])->assertNotFound();
    $this->actingAs($this->attendee)->postJson('/api/event-invitations/decline', ['token' => $token])->assertNotFound();
    expect(EventInvitation::query()->sole()->status->value)->toBe('pending')
        ->and(Events::for($this->event)->attendees()->find($this->attendee))->toBeNull()
        ->and(RsvpStatus::Going->value)->toBe('going');
});

test('a_signed_out_link_holder_cannot_answer_for_an_account_over_the_api', function (): void {
    Events::for($this->event)->update(new UpdateEventData(visibility: Visibility::Private));
    $invitee = User::factory()->create();
    $token = Events::for($this->event)->invitations()->send($invitee)->plainToken;

    guest();
    $accept = $this->postJson('/api/event-invitations/accept', ['token' => $token]);
    $decline = $this->postJson('/api/event-invitations/decline', ['token' => $token]);

    $accept->assertNotFound()->assertJsonPath('code', 'invalid_invitation')->assertJsonMissingPath('data.check_in_code');
    $decline->assertNotFound()->assertJsonPath('code', 'invalid_invitation');
    expect(EventInvitation::query()->sole()->status->value)->toBe('pending')
        ->and(Events::for($this->event)->attendees()->find($invitee))->toBeNull();

    // The token still works for its invitee, signed in.
    $this->actingAs($invitee)->postJson('/api/event-invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'going');
});

test('a guest model invitee answers over the api signed out', function (): void {
    Events::for($this->event)->update(new UpdateEventData(visibility: Visibility::Private));
    $token = Events::for($this->event)->invitations()->send(Guest::factory()->create())->plainToken;

    guest();
    $this->postJson('/api/event-invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'going');
});

test('attendee_update_is_all_or_nothing', function (): void {
    Events::for($this->event)->update(new UpdateEventData(capacity: 10));
    $a = Events::for($this->event)->tiers()->add('Standing', key: 'standing');
    Events::for($this->event)->tiers()->make('Seated')->key('seated')->perRsvp(max: 2)->create();
    $rsvp = Events::for($this->event)->rsvp($this->attendee)->going(tier: 'standing');

    // The ticket type change succeeds, the guests change is refused: nothing changes.
    $this->actingAs($this->organizer)->patchJson("/api/events/{$this->event->uuid}/attendees/{$rsvp->getKey()}", ['tier' => 'seated', 'guests' => 2])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_guest_count');

    expect($rsvp->fresh()?->tier_id)->toBe($a->getKey())
        ->and($rsvp->fresh()?->guests)->toBe(0);

    // Both valid: both apply.
    $this->actingAs($this->organizer)->patchJson("/api/events/{$this->event->uuid}/attendees/{$rsvp->getKey()}", ['tier' => 'seated', 'guests' => 1])
        ->assertOk()->assertJsonPath('data.guests', 1);
});

test('an_empty_attendee_patch_still_needs_manage_attendees', function (): void {
    $rsvp = Events::for($this->event)->rsvp($this->attendee)->going(note: 'Wheelchair access please');
    $interested = Events::for($this->event)->rsvp(User::factory()->create())->interested();
    $stranger = User::factory()->create();
    $uri = "/api/events/{$this->event->uuid}/attendees";

    // A body with nothing to change is still a manager's write: no RSVP view for anyone else.
    foreach ([$rsvp, $interested] as $record) {
        $this->actingAs($stranger)->patchJson("{$uri}/{$record->getKey()}", [])->assertForbidden()
            ->assertJsonPath('code', 'forbidden')->assertJsonMissingPath('data');
    }

    // A manager is told what is missing.
    $this->actingAs($this->organizer)->patchJson("{$uri}/{$rsvp->getKey()}", [])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['message', 'code', 'errors' => ['tier', 'guests']]);
});
