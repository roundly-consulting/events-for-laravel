<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Concerns\HasConfigurableKey;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;

/**
 * Where a method of the composed model comes from.
 */
function eventMethodSource(string $method): string
{
    $reflection = new ReflectionMethod(Event::class, $method);
    $declaring = $reflection->getDeclaringClass()->getName();
    $file = (string) $reflection->getFileName();

    foreach ([HasLifecycle::class, HasTags::class, HasTranslations::class, HasSlug::class, InteractsWithMedia::class, HasConfigurableKey::class] as $trait) {
        if ($file === (new ReflectionClass($trait))->getFileName()) {
            return $trait;
        }
    }

    return $declaring;
}

test('event_trait_composition', function (): void {
    $expected = [
        // lifecycle
        'transition' => HasLifecycle::class,
        'lifecycle' => HasLifecycle::class,
        // tags
        'tags' => HasTags::class,
        'tagOf' => HasTags::class,
        'tagsOf' => HasTags::class,
        'syncTags' => HasTags::class,
        'scopeWithAnyTags' => HasTags::class,
        // translatable
        'getTranslation' => HasTranslations::class,
        'setTranslation' => HasTranslations::class,
        // sluggable
        'findBySlug' => HasSlug::class,
        // media
        'media' => InteractsWithMedia::class,
        'addMedia' => InteractsWithMedia::class,
        'clearMediaBucket' => InteractsWithMedia::class,
        // events' own names, checked against all of the above
        'tagScope' => Event::class,
        'registerMediaBuckets' => Event::class,
        'getRouteKeyName' => Event::class,
        'resolveRouteBindingQuery' => Event::class,
        'category' => Event::class,
        'labels' => Event::class,
        'poster' => Event::class,
        'phase' => Event::class,
        'rsvps' => Event::class,
        'tiers' => Event::class,
        'scopeNear' => Event::class,
        'scopeInCategory' => Event::class,
    ];

    foreach ($expected as $method => $source) {
        expect(eventMethodSource($method))->toBe($source, $method);
    }

    // No events convenience method writes categories or labels on the model itself.
    expect(method_exists(Event::class, 'categorize'))->toBeFalse();

    // And it boots as one model: every trait's initializer ran.
    $event = new Event;
    expect($event->getTable())->toBe('events')
        ->and($event->getRouteKeyName())->toBe('uuid');
});
