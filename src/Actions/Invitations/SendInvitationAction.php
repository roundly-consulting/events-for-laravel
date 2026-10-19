<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Invitations;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Events\InvitationSent;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\InvitationTokens;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Invites someone — on a private event, their admission; on a public or unlisted one, an "invite
 * a friend" notice. Idempotent per invitee: sending again mints a new token and the old one dies
 * (a revoked or declined invitation becomes pending again). The token is returned once on the
 * invitation (`plainToken`) and carried by `InvitationSent` for the accept link; only its hash is
 * stored. It expires at the start.
 */
final readonly class SendInvitationAction
{
    public const int MESSAGE_MAX = 1000;

    public function __construct(
        private InvitationTokens $tokens,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, Model $invitee, ?string $message = null, ?Model $actor = null): EventInvitation
    {
        KeyTypes::assertMorphable($invitee);
        KeyTypes::assertMorphable($actor);

        if ($message !== null && mb_strlen($message) > self::MESSAGE_MAX) {
            throw InvalidEventException::messageTooLong(self::MESSAGE_MAX);
        }

        return EventTransaction::run($event, function () use ($event, $invitee, $message, $actor): EventInvitation {
            LockedEvent::lock($event);

            $invitation = EventInvitation::query()
                ->where('event_id', $event->getKey())
                ->where('invitee_type', $invitee->getMorphClass())
                ->where('invitee_id', $invitee->getKey())
                ->lockForUpdate()
                ->first() ?? new EventInvitation([
                    'event_id' => $event->getKey(),
                    'invitee_type' => $invitee->getMorphClass(),
                    'invitee_id' => $invitee->getKey(),
                ]);

            $token = $this->tokens->issue();

            if ($invitation->status !== InvitationStatus::Accepted) {
                $invitation->status = InvitationStatus::Pending;
                $invitation->responded_at = null;
            }

            $invitation->token_hash = $this->tokens->hash($token);
            $invitation->message = $message;
            $invitation->expires_at = $event->starts_at;
            $invitation->invited_by_type = $actor?->getMorphClass();
            $invitation->invited_by_id = $actor?->getKey();
            $invitation->save();
            $invitation->setRelation('invitee', $invitee);
            $invitation->plainToken = $token;

            $this->events->dispatch(new InvitationSent($invitation, $token, $message));

            return $invitation;
        });
    }
}
