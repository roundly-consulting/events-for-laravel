<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use RoundlyConsulting\Events\Tests\TestCase;

/**
 * A ulid install: events and every package that points at them (lifecycle, tags, slug
 * history) keyed by ulid, as the alignment rule asks.
 */
abstract class UlidKeyTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'events.primary_key_type' => 'ulid',
            'lifecycle.key_type' => 'ulid',
            'tags.key_type' => 'ulid',
            'sluggable.key_type' => 'ulid',
        ];
    }
}
