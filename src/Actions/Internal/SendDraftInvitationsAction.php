<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Events\InvitationSent;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\InvitationTokens;
use RoundlyConsulting\Events\Support\LockedEvent;

/**
 * Sends the invitations of a draft once it is published: nobody can open a draft, so its notices
 * wait for this. Each pending invitation gets a fresh token (only hashes are stored, so the old
 * one cannot be mailed again) and `InvitationSent` fires again — the package's notice and any
 * host mail listener send the new link. Returns how many were sent.
 *
 * @internal
 */
final readonly class SendDraftInvitationsAction
{
    public function __construct(
        private InvitationTokens $tokens,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event): int
    {
        return EventTransaction::run($event, function () use ($event): int {
            LockedEvent::lock($event);

            $invitations = EventInvitation::query()
                ->where('event_id', $event->getKey())
                ->where('status', InvitationStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($invitations as $invitation) {
                $token = $this->tokens->issue();
                $invitation->token_hash = $this->tokens->hash($token);
                $invitation->save();
                $invitation->setRelation('event', $event);

                $this->events->dispatch(new InvitationSent($invitation, $token, $invitation->message));
            }

            return $invitations->count();
        });
    }
}
