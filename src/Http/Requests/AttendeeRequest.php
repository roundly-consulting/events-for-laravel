<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * A manager adds someone (`POST`, `{attendee: {type, id}, tier?, guests?, over_capacity?}`) or
 * changes an RSVP (`PATCH`, `{tier?, guests?}`).
 *
 * @internal
 */
final class AttendeeRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...($this->isMethod('POST') ? self::reference('attendee', required: true) : []),
            'tier' => ['nullable', 'string', 'max:64'],
            'guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'over_capacity' => ['nullable', 'boolean'],
        ];
    }

    public function attendee(): Model
    {
        /** @var array<string, mixed> $reference */
        $reference = $this->validated('attendee');

        return self::resolvePerson($reference, 'attendee');
    }

    public function tier(): ?string
    {
        $tier = $this->validated('tier');

        return is_string($tier) ? $tier : null;
    }

    public function guests(): ?int
    {
        $guests = $this->validated('guests');

        return $guests === null ? null : (int) $guests;
    }

    /**
     * A `PATCH` must name a ticket type, a guest count or both: a 422 like any validation failure.
     * The controller asks after the policy, so a caller who may not manage attendees learns nothing
     * from it.
     */
    public function assertChangesSomething(): void
    {
        if ($this->tier() !== null || $this->guests() !== null) {
            return;
        }

        $validator = $this->container->make(Factory::class)->make(
            [],
            ['tier' => ['required_without:guests'], 'guests' => ['required_without:tier']],
            $this->messages(),
            $this->attributes(),
        );

        if ($validator->fails()) {
            $this->failedValidation($validator);
        }
    }

    public function overCapacity(): bool
    {
        return (bool) $this->validated('over_capacity', false);
    }
}
