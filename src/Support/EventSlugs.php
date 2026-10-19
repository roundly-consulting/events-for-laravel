<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;

/**
 * The event slug definition: one slug per locale from the title, stable once set (`IfEmpty`, so
 * a renamed event keeps its links), unique per locale with trashed events included, a sequential
 * suffix on collision, and a strict manual slug. History (and redirects from it) only with
 * `events.slugs.history` on.
 *
 * @internal
 */
final class EventSlugs
{
    public static function definition(): SlugDefinition
    {
        $history = EventsConfig::slugHistory();

        return SlugDefinition::for('slug')
            ->from('title')
            ->localized()
            ->perLocaleUniqueness()
            ->includeTrashed()
            ->maxLength(EventsConfig::slugMaxLength())
            ->sequentialSuffix()
            ->onUpdate(UpdatePolicy::IfEmpty)
            ->manual(ManualSlugPolicy::Strict)
            ->keepHistory($history)
            ->redirectFromHistory($history);
    }
}
