<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

/**
 * The two tag types events declares in tags-for-laravel: one curated category tree (global, one
 * category per event) and free-form labels (private to tags' JSON API).
 */
enum EventTagType: string
{
    case Category = 'events:category';
    case Label = 'events:label';
}
