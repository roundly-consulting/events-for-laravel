<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\EventsServiceProvider;
use RoundlyConsulting\Events\Support\EventsConfig;

test('routes_are_off_by_default', function (): void {
    expect(config('events.routes.enabled'))->toBe('off')
        ->and(Route::has('events.index'))->toBeFalse()
        ->and(EventsConfig::routesEnabled())->toBeFalse();
});

test('blank_env_keeps_routes_off', function (mixed $value): void {
    config()->set('events.routes.enabled', $value);
    (new EventsServiceProvider(app()))->register();

    expect(config('events.routes.enabled'))->toBe('off')
        ->and(EventsConfig::routesEnabled())->toBeFalse();
})->with(['blank' => '', 'spaces' => '   ', 'null' => null]);

it('keeps the api off when a published routes array lacks the switch', function (): void {
    // mergeConfigFrom is shallow: a published `routes` replaces the package's whole array.
    config()->set('events.routes', ['prefix' => 'api']);
    (new EventsServiceProvider(app()))->register();

    expect(EventsConfig::routesEnabled())->toBeFalse();

    config()->set('events.routes.enabled', 'on');
    (new EventsServiceProvider(app()))->register();
    expect(EventsConfig::routesEnabled())->toBeTrue();
});
