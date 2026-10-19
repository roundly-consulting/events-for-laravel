<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

/**
 * The JSON API on with `events.routes.auth_middleware = []` — a host that relies on its own
 * middleware (or forgot it): writes must still never run without a signed-in user.
 */
abstract class NoAuthApiTestCase extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'events.routes.auth_middleware' => []];
    }
}
