<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * `{reason?}` for postpone and cancel.
 *
 * @internal
 */
final class ReasonRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
