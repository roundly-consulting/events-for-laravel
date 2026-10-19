<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Enums\InvitationStatus;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * A pending invitation without a usable token (tokens are only minted by `invitations()->send()`);
 * name the invitee with `->invitee($model)`.
 *
 * @extends Factory<EventInvitation>
 */
final class EventInvitationFactory extends Factory
{
    /** @var class-string<EventInvitation> */
    protected $model = EventInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => EventModels::event()::factory(),
            'status' => InvitationStatus::Pending,
        ];
    }

    public function invitee(Model $invitee): self
    {
        return $this->state(['invitee_type' => $invitee->getMorphClass(), 'invitee_id' => $invitee->getKey()]);
    }

    public function status(InvitationStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
