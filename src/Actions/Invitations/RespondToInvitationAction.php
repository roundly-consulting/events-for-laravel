<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Invitations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Rsvps\RespondToEventAction;
use RoundlyConsulting\Events\DataTransferObjects\RsvpRequest;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Events\InvitationAccepted;
use RoundlyConsulting\Events\Events\InvitationDeclined;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\InvitationTokens;
use RoundlyConsulting\Events\Support\SeatBook;
use SensitiveParameter;

/**
 * Answers an invitation from its link: accepting RSVPs the invitee (going or interested), declining
 * answers not going. The token is a bearer secret used once; the attendee is always the
 * invitation's own invitee, never the caller. When the invitee is an account (`Authenticatable`),
 * `$by` must **be** that invitee — signed out or signed in as someone else, a forwarded mail
 * cannot answer for them (and so never sees their check-in code). A non-account invitee (a host's
 * Guest model) answers with the token alone. `$system` is trusted PHP — imports, seeders —
 * answering with no user.
 *
 * Unknown, malformed, revoked, expired, used tokens and someone else's link all read the same
 * (`InvalidInvitationException`, a uniform 404): no oracle.
 */
final readonly class RespondToInvitationAction
{
    public function __construct(
        private InvitationTokens $tokens,
        private RespondToEventAction $rsvps,
        private Dispatcher $events,
    ) {}

    public function execute(#[SensitiveParameter] string $token, RsvpResponse $response = RsvpResponse::Going, ?Model $by = null, bool $system = false): Rsvp
    {
        $invitation = $this->tokens->invitation($token);
        $invitee = $invitation->invitee;

        // Checked before any write, so the token stays usable for the real invitee.
        if ($invitee === null || ($invitee instanceof Authenticatable && ! $system && ($by === null || ! $by->is($invitee)))) {
            throw InvalidInvitationException::invalid();
        }

        $event = $invitation->event;

        // One transaction with the answer, the invitation re-read under its lock (after the
        // event's and its ticket types'): a revocation lands before (refused) or after (it wins),
        // never in between to be overwritten.
        $rsvp = EventTransaction::run($event, function () use ($event, $invitation, $invitee, $response): Rsvp {
            SeatBook::open($event);
            $current = EventInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($current === null || ! in_array($current->status, [InvitationStatus::Pending, InvitationStatus::Accepted], true)
                || $current->token_hash !== $invitation->token_hash) {
                throw InvalidInvitationException::invalid();
            }

            $rsvp = $this->rsvps->execute($event, new RsvpRequest($invitee, $response));

            $current->status = $response === RsvpResponse::NotGoing ? InvitationStatus::Declined : InvitationStatus::Accepted;
            $current->responded_at = Clock::now();
            $current->token_hash = null;
            $current->save();
            $invitation->setRawAttributes($current->getAttributes(), true);

            return $rsvp;
        });

        $this->events->dispatch($response === RsvpResponse::NotGoing
            ? new InvitationDeclined($invitation)
            : new InvitationAccepted($invitation, $rsvp));

        return $rsvp;
    }
}
