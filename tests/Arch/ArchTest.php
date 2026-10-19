<?php

declare(strict_types=1);

use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Notifications;
use RoundlyConsulting\Events\Policies\EventPolicy;
use RoundlyConsulting\Events\Policies\VenuePolicy;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Events');

// Deliberately non-final: the four swappable models (a host subclasses them), the manager
// (EventsFake extends it), the shipped lifecycle (a host extends it to add a step) and the two
// swappable policies and the notification types.
ArchPresets::finalByDefault('RoundlyConsulting\Events', [
    Event::class,
    Venue::class,
    AdmissionTier::class,
    Rsvp::class,
    EventsManager::class,
    EventLifecycle::class,
    EventPolicy::class,
    VenuePolicy::class,
    // A host swaps a notification type with a subclass (events.notifications.types).
    Notifications\EventInvitationNotification::class,
    Notifications\RsvpConfirmedNotification::class,
    Notifications\WaitlistPromotedNotification::class,
    Notifications\EventReminderNotification::class,
    Notifications\EventUpdatedNotification::class,
    Notifications\EventPostponedNotification::class,
    Notifications\EventRescheduledNotification::class,
    Notifications\EventCancelledNotification::class,
    Notifications\EventMovedOnlineNotification::class,
]);

ArchPresets::swappableModelsAreNotFinal([
    Event::class => 'events.models.event',
    Venue::class => 'events.models.venue',
    AdmissionTier::class => 'events.models.tier',
    Rsvp::class => 'events.models.rsvp',
]);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Events');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', [
    'events.models.event',
    'events.models.venue',
    'events.models.tier',
    'events.models.rsvp',
]);

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', __DIR__.'/../../src');

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

// The Dependency Policy as a test: php/ext/illuminate/roundly only.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Events');

arch('controllers read input through validated form requests only')
    ->expect('RoundlyConsulting\\Events\\Http\\Controllers')
    ->not->toUse(['request', 'input'])
    ->and('RoundlyConsulting\\Events\\Http\\Controllers')
    ->not->toUse(['Illuminate\\Support\\Facades\\Request']);

test('controllers never call all() or input() on a request', function (): void {
    foreach (glob(__DIR__.'/../../src/Http/Controllers/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);

        // Every request a controller receives is named $request.
        expect($code)->not->toMatch('/\\$request->(all|input|get|query|post|only|except|collect)\\(/', basename($file));
    }
});
