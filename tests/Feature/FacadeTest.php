<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\Actions\ExampleEventsAction;
use RoundlyConsulting\Events\DataTransferObjects\ExampleEventsData;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Testing\EventsFake;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — pinned from day one.
 * Grow these tests with the API: every facade and sub-accessor method through the facade,
 * one DI resolution of the manager, cross-scope refusals, and a passing plus a failing case
 * for every fake assert.
 */
it('pins the facade contract', function (): void {
    expect(Events::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the example through the facade', function (): void {
    expect(Events::example(new ExampleEventsData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example through an injected manager', function (): void {
    $manager = app(EventsManager::class);

    expect($manager)->toBe(app(EventsManager::class))
        ->and($manager->example(new ExampleEventsData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example action directly', function (): void {
    expect(app(ExampleEventsAction::class)->execute(new ExampleEventsData('Ada')))
        ->toBe('Hello, Ada!');
});

it('resolves the action through the container so a host override applies', function (): void {
    app()->bind(ExampleEventsAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Events::example(new ExampleEventsData('Ada'));
})->throws(RuntimeException::class, 'overridden');

it('records facade and injected calls under the fake without running the action', function (): void {
    app()->bind(ExampleEventsAction::class, static fn (): never => throw new RuntimeException('ran'));

    $fake = Events::fake();

    Events::example(new ExampleEventsData('Ada'));
    app(EventsManager::class)->example(new ExampleEventsData('Grace'));

    expect(app(EventsManager::class))->toBeInstanceOf(EventsFake::class);

    $fake->assertExampleCalled();
    $fake->assertExampleCalled(static fn (ExampleEventsData $data): bool => $data->name === 'Ada');
    Events::assertExampleCalled(static fn (ExampleEventsData $data): bool => $data->name === 'Grace');
});

it('fails assertExampleCalled when nothing was called', function (): void {
    Events::fake()->assertExampleCalled();
})->throws(ExpectationFailedException::class, 'Expected example() to be called, but it was not.');

it('fails assertExampleCalled when no call matches', function (): void {
    $fake = Events::fake();

    Events::example(new ExampleEventsData('Ada'));

    $fake->assertExampleCalled(static fn (ExampleEventsData $data): bool => $data->name === 'Grace');
})->throws(ExpectationFailedException::class, 'no call matched');

it('passes assertNothingCalled on an untouched fake', function (): void {
    Events::fake()->assertNothingCalled();
});

it('fails assertNothingCalled after a call', function (): void {
    $fake = Events::fake();

    Events::example(new ExampleEventsData('Ada'));

    $fake->assertNothingCalled();
})->throws(ExpectationFailedException::class, 'example() was called 1 time(s)');

it('registers no global alias: Events is one letter from Laravel\'s Event facade (OQ-4)', function (): void {
    /** @var array{extra: array{laravel: array<string, mixed>}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel'])->not->toHaveKey('aliases')
        ->and(class_exists('Illuminate\\Support\\Facades\\'.class_basename(Events::class)))->toBeFalse();
});
