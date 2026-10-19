<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * A door scan (`{code, guests?}`) or a manual check-in (`{guests?}`).
 *
 * @internal
 */
final class CheckInRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->route('rsvp') === null ? 'required' : 'prohibited', 'string', 'max:512'],
            'guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function guests(): ?int
    {
        $guests = $this->validated('guests');

        return $guests === null ? null : (int) $guests;
    }
}
