<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * `{url, keep_venue?}`.
 *
 * @internal
 */
final class MoveOnlineRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'keep_venue' => ['nullable', 'boolean'],
        ];
    }

    public function url(): string
    {
        return (string) $this->validated('url');
    }

    public function keepsVenue(): bool
    {
        return (bool) $this->validated('keep_venue', false);
    }
}
