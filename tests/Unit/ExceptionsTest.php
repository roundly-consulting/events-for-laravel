<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\EventsException;
use RoundlyConsulting\Events\Exceptions\EventTransitionDeniedException;

/**
 * Every domain exception factory, with placeholder arguments.
 *
 * @return list<EventsException>
 */
function everyEventsException(): array
{
    $exceptions = [];

    foreach (glob(__DIR__.'/../../src/Exceptions/*.php') ?: [] as $file) {
        $class = 'RoundlyConsulting\\Events\\Exceptions\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(EventsException::class) || $class === EventTransitionDeniedException::class) {
            continue;
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $arguments = array_map(static fn (ReflectionParameter $parameter): mixed => match ((string) $parameter->getType()) {
                'int' => 3,
                default => 'Ab',
            }, $method->getParameters());

            $exceptions[] = $class === CheckInRefusedException::class
                ? CheckInRefusedException::because(CheckInRefusedException::OUTSIDE_WINDOW)
                : $method->invoke(null, ...$arguments);
        }
    }

    return $exceptions;
}

test('exception_messages_name_no_classes', function (string $locale): void {
    app()->setLocale($locale);
    $exceptions = everyEventsException();

    expect(count($exceptions))->toBeGreaterThan(60);

    foreach ($exceptions as $exception) {
        expect($exception->getMessage())->not->toContain('\\')
            ->not->toContain('events::messages')
            ->not->toContain('RoundlyConsulting')
            ->and($exception->errorCode())->toMatch('/^[a-z_]+$/')
            ->and($exception->status())->toBeIn([403, 404, 409, 422, 429]);
    }
})->with(['en', 'sk']);

it('speaks slovak', function (): void {
    app()->setLocale('sk');

    expect(CheckInRefusedException::because('wrong_event')->getMessage())->toBe('Tento kód patrí inému podujatiu.');
});
