<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

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

    public function overCapacity(): bool
    {
        return (bool) $this->validated('over_capacity', false);
    }
}
