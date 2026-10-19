<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

/**
 * `{order: [tier id, …]}`.
 *
 * @internal
 */
final class ReorderTiersRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1', 'max:200'],
            'order.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function order(): array
    {
        return array_map('intval', array_values((array) $this->validated('order')));
    }
}
