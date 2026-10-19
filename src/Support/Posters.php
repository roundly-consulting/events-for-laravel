<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Support\Collection;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Poster reads that never throw: media-library's own URL getter throws for a variant that is not
 * generated yet, so a pending variant falls back to the original, and an event without a poster
 * to `events.poster.fallback_url` (posts' `MediaUrl::of` semantics).
 *
 * @internal
 */
final class Posters
{
    /**
     * The poster — from the eager-loaded `media` relation when a list loaded it (no query per
     * row), else one query.
     */
    public static function of(Event $event): ?Media
    {
        $bucket = EventsConfig::posterBucket();

        if ($event->relationLoaded('media')) {
            $media = $event->getRelation('media');

            if ($media instanceof Collection) {
                $poster = $media
                    ->filter(static fn (mixed $item): bool => $item instanceof Media && $item->bucket_name === $bucket)
                    ->sortBy([['order_column', 'asc'], ['id', 'asc']])
                    ->first();

                return $poster instanceof Media ? $poster : null;
            }
        }

        return $event->getFirstMedia($bucket);
    }

    public static function url(Event $event, string $variant = ''): ?string
    {
        $poster = self::of($event);

        if ($poster === null) {
            return EventsConfig::posterFallbackUrl();
        }

        if ($variant !== '' && ! $poster->hasGeneratedVariant($variant)) {
            $variant = '';
        }

        return $poster->getUrl($variant);
    }

    public static function clear(Event $event): void
    {
        $event->clearMediaBucket(EventsConfig::posterBucket());
        $event->unsetRelation('media');
    }
}
