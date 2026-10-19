<?php

declare(strict_types=1);

use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Policies\EventPolicy;
use RoundlyConsulting\Events\Policies\VenuePolicy;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Events');

// Deliberately non-final: the four swappable models (a host subclasses them), the manager
// (EventsFake extends it), the shipped lifecycle (a host extends it to add a step) and the two
// swappable policies.
ArchPresets::finalByDefault('RoundlyConsulting\Events', [
    Event::class,
    Venue::class,
    AdmissionTier::class,
    Rsvp::class,
    EventsManager::class,
    EventLifecycle::class,
    EventPolicy::class,
    VenuePolicy::class,
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
