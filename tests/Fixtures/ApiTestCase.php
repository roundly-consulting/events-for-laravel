<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use RoundlyConsulting\Events\Tests\TestCase;

/**
 * The JSON API on (and tags' too, to prove events' labels stay out of it): the switches are read
 * when the providers boot.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'events.routes.enabled' => 'on',
            'tags.routes.enabled' => 'on',
        ];
    }
}
