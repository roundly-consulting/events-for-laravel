<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use Illuminate\Foundation\Application;
use RoundlyConsulting\Events\Tests\TestCase;

/**
 * A host whose app timezone is America/New_York: the wall-clock bug class (an app-timezone
 * Carbon bound against UTC columns) shows up here as a 4–5 hour shift.
 */
abstract class NewYorkTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('app.timezone', 'America/New_York');
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        date_default_timezone_set('UTC');
    }
}
