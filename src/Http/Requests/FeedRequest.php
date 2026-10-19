<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use RoundlyConsulting\Events\DataTransferObjects\EventFeedQuery;
use RoundlyConsulting\Events\Support\MorphAliases;

/**
 * `GET /events/feed.ics?category=&label=&organizer=alias:key&venue=`.
 *
 * @internal
 */
final class FeedRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'organizer' => ['nullable', 'string', 'max:300', 'regex:/^[a-z][a-z0-9_]{0,63}:.+$/'],
            'venue' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toQuery(): EventFeedQuery
    {
        $data = $this->validated();
        $organizer = null;

        if (isset($data['organizer'])) {
            [$alias, $key] = MorphAliases::split((string) $data['organizer']);
            $organizer = MorphAliases::organizer($alias, $key);
        }

        return new EventFeedQuery(
            category: $data['category'] ?? null,
            label: $data['label'] ?? null,
            organizer: $organizer,
            venue: isset($data['venue']) ? (int) $data['venue'] : null,
        );
    }
}
