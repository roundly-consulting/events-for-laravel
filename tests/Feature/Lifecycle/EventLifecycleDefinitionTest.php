<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventStatus;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;

test('event_lifecycle_definition_validates', function (): void {
    $report = app(DefinitionRegistry::class)->validate(EventLifecycle::class);

    expect($report->errors())->toBe([])
        ->and($report->warnings())->toBe([]);

    // The command agrees (its scheduler note is about the host's console kernel, not the definition).
    expect(Artisan::call('lifecycle:validate', ['definition' => [EventLifecycle::class]]))->toBe(0)
        ->and(Artisan::output())->toContain('EventLifecycle — 0 error(s), 0 warning(s)');
});

test('event_lifecycle_maps_every_verb_and_every_phase', function (): void {
    $lifecycle = new EventLifecycle;
    $definition = app(DefinitionRegistry::class)->get(EventLifecycle::class);

    foreach (EventVerb::cases() as $verb) {
        expect($definition->hasTransition((string) $lifecycle->transitionFor($verb)))->toBeTrue($verb->value);
    }

    expect(array_map(static fn (EventStatus $status): EventPhase => $status->phase(), EventStatus::cases()))
        ->toBe(EventPhase::cases())
        ->and($definition->initial)->toBe('draft')
        ->and($definition->terminalKeys())->toEqualCanonicalizing(['cancelled', 'completed']);
});

test('status_is_never_written_directly', function (): void {
    // Strict writes stay on: a direct status write throws.
    $event = Event::factory()->published()->create();
    $event->status = EventStatus::Cancelled;

    expect(fn () => $event->save())->toThrow(DirectStateWriteException::class);

    // And no package write path assigns an event's status: every change is a lifecycle
    // transition. (RSVPs and invitations have their own status columns; event code never
    // touches the event's.)
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../src'));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/\$event->status\s*=|\$event->(?:update|forceFill|fill)\(\[[^\]]*[\'"]status[\'"]/', $source) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

test('factory_states_arm_the_expiry', function (): void {
    $event = Event::factory()->published()->create();

    expect($event->phase)->toBe(EventPhase::Scheduled)
        ->and($event->published_at)->not->toBeNull()
        ->and(Lifecycles::for($event)->expiresAt()?->toIso8601String())->toBe($event->ends_at->toIso8601String());

    $postponed = Event::factory()->postponed()->create();

    expect($postponed->phase)->toBe(EventPhase::Postponed)
        ->and(Lifecycles::for($postponed)->expiresAt())->toBeNull();

    foreach (['rescheduled' => EventPhase::Rescheduled, 'cancelled' => EventPhase::Cancelled, 'completed' => EventPhase::Completed] as $state => $phase) {
        expect(Event::factory()->{$state}()->create()->phase)->toBe($phase);
    }
});
