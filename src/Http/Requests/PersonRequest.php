<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Database\Eloquent\Model;

/**
 * A co-host or an invitee: `{type, id}` (an alias of `events.organizers` / `events.attendees`),
 * and for an invitation an optional `message`.
 *
 * @internal
 */
final class PersonRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::reference('person', required: true),
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function person(): Model
    {
        /** @var array<string, mixed> $reference */
        $reference = $this->validated('person');

        return self::resolvePerson($reference, 'person');
    }

    public function message(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) ? $message : null;
    }
}
