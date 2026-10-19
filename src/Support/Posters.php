<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

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
    public static function of(Event $event): ?Media
    {
        return $event->getFirstMedia(EventsConfig::posterBucket());
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
