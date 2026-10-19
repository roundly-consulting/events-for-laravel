<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * The venue slug: one plain slug from the fallback-locale name, unique (trashed venues
 * included), a sequential suffix on collision, stable once set.
 *
 * @internal
 */
final class VenueSlugs
{
    public static function definition(): SlugDefinition
    {
        return SlugDefinition::for('slug')
            ->from('name')
            ->sourceLocale(Translatable::fallbackLocale())
            ->includeTrashed()
            ->maxLength(160)
            ->sequentialSuffix()
            ->onUpdate(UpdatePolicy::IfEmpty);
    }
}
