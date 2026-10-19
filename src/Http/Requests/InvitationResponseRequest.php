<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Events\Enums\RsvpResponse;

/**
 * `{token, response?}` from an invitation link.
 *
 * @internal
 */
final class InvitationResponseRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'response' => ['nullable', Rule::enum(RsvpResponse::class)],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }

    public function response(): RsvpResponse
    {
        $response = $this->validated('response');

        return is_string($response) ? RsvpResponse::from($response) : RsvpResponse::Going;
    }
}
