<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use RoundlyConsulting\Events\Actions\Events\PublishEventAction;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

/*
 * Under Octane every request runs in a sandbox container cloned from the base application. The
 * manager is a singleton a host may resolve at boot (a provider calling `Events::…`), so it must
 * never hold on to the container it was built with.
 */

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('the_manager_resolves_actions_from_the_current_container', function (): void {
    $event = Scenarios::event(publish: false);
    $manager = app(EventsManager::class);
    Events::browse(); // the facade caches its root

    $original = Container::getInstance();
    $other = new Container;
    $other->bind(PublishEventAction::class, static fn (): never => throw new RuntimeException('current container'));

    Container::setInstance($other);

    try {
        expect(fn () => Events::for($event)->publish())->toThrow(RuntimeException::class, 'current container')
            ->and(fn () => $manager->for($event)->publish())->toThrow(RuntimeException::class, 'current container')
            ->and($manager->container())->toBe($other);
    } finally {
        Container::setInstance($original);
    }

    Events::for($event)->publish();
    expect($event->fresh()?->phase)->toBe(EventPhase::Scheduled);
});

test('the fake resolves from the current container too', function (): void {
    $fake = Events::fake();

    expect($fake->container())->toBe(Container::getInstance())
        ->and(app(EventsManager::class))->toBe($fake);
});
