<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * `GET /events/{event}/attendees?status=&checked_in=&tier=&per_page=`.
 *
 * @internal
 */
final class ListAttendeesRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(RsvpStatus::class)],
            'checked_in' => ['nullable', 'boolean'],
            'tier' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.EventsConfig::maxPerPage()],
        ];
    }

    public function status(): ?RsvpStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? RsvpStatus::from($status) : null;
    }

    public function checkedIn(): ?bool
    {
        $value = $this->validated('checked_in');

        return $value === null ? null : (bool) $value;
    }

    public function tier(): ?string
    {
        $tier = $this->validated('tier');

        return is_string($tier) ? $tier : null;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
