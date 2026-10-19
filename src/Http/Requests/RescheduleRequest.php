<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * `{starts_at, ends_at?, reason?}` — wall clock in the event's timezone unless an offset is given.
 *
 * @internal
 */
final class RescheduleRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'max:64'],
            'ends_at' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function startsAt(): string
    {
        return (string) $this->validated('starts_at');
    }

    public function endsAt(): ?string
    {
        $value = $this->validated('ends_at');

        return is_string($value) ? $value : null;
    }

    public function reason(): ?string
    {
        $value = $this->validated('reason');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
