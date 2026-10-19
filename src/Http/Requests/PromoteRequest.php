<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * `{limit?}`: how many waitlisted parties to move up at most.
 *
 * @internal
 */
final class PromoteRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['limit' => ['nullable', 'integer', 'min:1', 'max:1000']];
    }

    public function limit(): ?int
    {
        $limit = $this->validated('limit');

        return $limit === null ? null : (int) $limit;
    }
}
