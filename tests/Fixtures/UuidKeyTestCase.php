<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use RoundlyConsulting\Events\Tests\TestCase;

/**
 * A uuid install: events and every package that points at them (lifecycle, tags, slug
 * history) keyed by uuid, as the alignment rule asks.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'events.primary_key_type' => 'uuid',
            'lifecycle.key_type' => 'uuid',
            'tags.key_type' => 'uuid',
            'sluggable.key_type' => 'uuid',
        ];
    }
}
