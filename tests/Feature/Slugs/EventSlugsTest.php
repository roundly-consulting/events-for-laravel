<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;

test('slugs_are_stable_and_per_locale', function (): void {
    $first = Event::factory()->create(['title' => ['en' => 'Jazz Night', 'sk' => 'Jazzový večer']]);

    expect($first->getTranslation('slug', 'en'))->toBe('jazz-night')
        ->and($first->getTranslation('slug', 'sk'))->toBe('jazzovy-vecer');

    // Per-locale uniqueness with a sequential suffix.
    $second = Event::factory()->create(['title' => ['en' => 'Jazz Night', 'sk' => 'Iný večer']]);

    expect($second->getTranslation('slug', 'en'))->toBe('jazz-night-2')
        ->and($second->getTranslation('slug', 'sk'))->toBe('iny-vecer');

    // Stable once set: a renamed event keeps its links.
    $first->update(['title' => ['en' => 'Late Jazz Night', 'sk' => 'Neskorý jazzový večer']]);

    expect($first->fresh()?->getTranslation('slug', 'en'))->toBe('jazz-night');

    // Trashed events keep their slugs taken.
    $second->delete();
    $third = Event::factory()->create(['title' => ['en' => 'Jazz Night']]);

    expect($third->getTranslation('slug', 'en'))->toBe('jazz-night-3');
});

it('slugs a venue once from its fallback-locale name', function (): void {
    app()->setLocale('sk');

    $venue = Venue::factory()->create(['name' => ['en' => 'City Park', 'sk' => 'Mestský park']]);
    $twin = Venue::factory()->create(['name' => ['en' => 'City Park']]);

    expect($venue->slug)->toBe('city-park')
        ->and($twin->slug)->toBe('city-park-2')
        ->and($venue->name)->toBe('Mestský park');

    $venue->update(['name' => ['en' => 'Central Park']]);

    expect($venue->fresh()?->slug)->toBe('city-park');
});
