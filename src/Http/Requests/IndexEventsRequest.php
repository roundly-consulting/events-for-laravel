<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * `GET /events` — the query-builder `filter[…]` / `sort` parameters are read by
 * `EventIndexQuery`; this validates the rest: `filter[mine]`, `tz` and `per_page`.
 *
 * @internal
 */
final class IndexEventsRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'filter.mine' => ['nullable', 'string', 'in:organizing,attending,interested'],
            'tz' => ['nullable', 'timezone:all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.EventsConfig::maxPerPage()],
        ];
    }

    public function mine(): ?string
    {
        $mine = $this->validated('filter.mine');

        return is_string($mine) ? $mine : null;
    }

    public function timezone(): string
    {
        $tz = $this->validated('tz');

        return is_string($tz) ? $tz : (string) config('app.timezone', 'UTC');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 15);
    }
}
