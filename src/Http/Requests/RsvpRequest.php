<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Events\Enums\RsvpResponse;

/**
 * `PUT /events/{event}/rsvp` — the caller's own answer: `{response, guests?, tier?, note?}`.
 *
 * @internal
 */
final class RsvpRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'response' => ['required', Rule::enum(RsvpResponse::class)],
            'guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tier' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function response(): RsvpResponse
    {
        return RsvpResponse::from((string) $this->validated('response'));
    }

    public function guests(): int
    {
        return (int) $this->validated('guests', 0);
    }

    public function tier(): ?string
    {
        $tier = $this->validated('tier');

        return is_string($tier) ? $tier : null;
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
