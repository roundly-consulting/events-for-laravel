<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Accessors;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Invitations\RespondToInvitationAction;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidInvitationException;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\InvitationTokens;
use SensitiveParameter;

/**
 * `Events::invitations()` — answering an invitation from its link, by token. Pass the request's
 * user as `$by`: when the invitee is a user account, only that user may answer.
 */
final readonly class Invitations
{
    public function __construct(
        private EventsManager $manager,
        private InvitationTokens $tokens,
    ) {}

    /**
     * Accept: RSVP the invitee (going by default, or interested).
     */
    public function accept(#[SensitiveParameter] string $token, RsvpResponse $as = RsvpResponse::Going, ?Model $by = null): Rsvp
    {
        return $this->manager->perform(
            EventOperation::RespondToInvitation,
            RespondToInvitationAction::class,
            static fn (RespondToInvitationAction $action): Rsvp => $action->execute($token, $as, $by),
            ['response' => $as, 'actor' => $by],
        );
    }

    public function decline(#[SensitiveParameter] string $token, ?Model $by = null): EventInvitation
    {
        $invitation = $this->tokens->invitation($token);

        $this->manager->perform(
            EventOperation::RespondToInvitation,
            RespondToInvitationAction::class,
            static fn (RespondToInvitationAction $action): Rsvp => $action->execute($token, RsvpResponse::NotGoing, $by),
            ['event' => $invitation->event, 'response' => RsvpResponse::NotGoing, 'actor' => $by],
        );

        return $invitation->refresh();
    }

    /**
     * The live invitation behind a token, or null.
     */
    public function find(#[SensitiveParameter] string $token): ?EventInvitation
    {
        try {
            return $this->tokens->invitation($token);
        } catch (InvalidInvitationException) {
            return null;
        }
    }
}
