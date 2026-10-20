<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Invitations\RevokeInvitationAction;
use RoundlyConsulting\Events\Actions\Invitations\SendInvitationAction;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Models\EventInvitation;

/**
 * `Events::for($event)->invitations()` — on a private event, the guest list's door. Writes check
 * `invite` when the handle names an actor.
 */
final readonly class EventInvitations
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    /**
     * Invite (or re-invite, with a new token) — the returned invitation carries the token once, as
     * `plainToken`, for the accept link.
     */
    public function send(Model $invitee, ?string $message = null): EventInvitation
    {
        $this->handle->authorize('invite');

        return $this->handle->manager()->perform(
            EventOperation::SendInvitation,
            SendInvitationAction::class,
            fn (SendInvitationAction $action): EventInvitation => $action->execute($this->handle->event(), $invitee, $message, $this->handle->actor()),
            $this->handle->context(['invitee' => $invitee]),
        );
    }

    /**
     * Revoke an invitation; on a private event an invitee who is not going yet loses their place.
     */
    public function revoke(Model $invitee): self
    {
        $this->handle->authorize('invite');
        $this->handle->manager()->perform(
            EventOperation::RevokeInvitation,
            RevokeInvitationAction::class,
            fn (RevokeInvitationAction $action): EventInvitation => $action->execute($this->handle->event(), $invitee, $this->handle->actor()),
            $this->handle->context(['invitee' => $invitee]),
        );

        return $this;
    }

    /**
     * Every invitation (`invite` with an actor).
     *
     * @return Collection<int, EventInvitation>
     */
    public function all(): Collection
    {
        $this->handle->authorize('invite');

        return EventInvitation::query()
            ->where('event_id', $this->handle->event()->getKey())
            ->with('invitee')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, EventInvitation>
     */
    public function pending(): Collection
    {
        return $this->all()->filter(static fn (EventInvitation $invitation): bool => $invitation->status === InvitationStatus::Pending)->values();
    }
}
