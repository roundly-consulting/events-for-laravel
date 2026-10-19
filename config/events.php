<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;

return [

    /*
    |--------------------------------------------------------------------------
    | Key types
    |--------------------------------------------------------------------------
    |
    | `primary_key_type` is the events table's own key (bigint, uuid or ulid). Lifecycle,
    | tags, media and slug history point at it, so keep it equal to `lifecycle.key_type` and
    | `tags.key_type`. `key_type` is the key of YOUR models the package points at — organizers,
    | attendees, co-hosts, invitees, venue owners, actors — so it must equal your users' key
    | type (and `lifecycle.actor_key_type`). Set both before migrating.
    |
    */

    'primary_key_type' => env('EVENTS_PRIMARY_KEY_TYPE', 'bigint'),

    'key_type' => env('EVENTS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | `events` is a common table name (analytics, event sourcing). Rename any of these
    | before migrating if your app already has one; every index has its own short name.
    |
    */

    'tables' => [
        'events' => 'events',
        'venues' => 'event_venues',
        'tiers' => 'event_admission_tiers',
        'rsvps' => 'event_rsvps',
        'hosts' => 'event_hosts',
        'invitations' => 'event_invitations',
        'reminder_deliveries' => 'event_reminder_deliveries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap a model by extending the packaged class and naming your subclass here.
    |
    */

    'models' => [
        'event' => Event::class,
        'venue' => Venue::class,
        'tier' => AdmissionTier::class,
        'rsvp' => Rsvp::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    |
    | The status workflow: a lifecycle-for-laravel definition implementing
    | RoundlyConsulting\Events\Contracts\EventLifecycle, whose states implement
    | RoundlyConsulting\Events\Contracts\EventState. Extend the shipped one to add a step
    | (an approval before publishing, say).
    |
    */

    'lifecycle' => EventLifecycle::class,

    /*
    |--------------------------------------------------------------------------
    | Organizers and attendees
    |--------------------------------------------------------------------------
    |
    | Your models that organize events (they implement Contracts\Organizer, e.g. through
    | Concerns\OrganizesEvents) and that RSVP (Concerns\AttendsEvents), by the short alias the
    | JSON API accepts (`{"type": "user", "id": 1}`) — never a class name over the wire.
    | Organizers listed here also decide who manages an event (the `managedBy()` scope).
    |
    */

    'organizers' => [
        'user' => 'App\\Models\\User',
    ],

    'attendees' => [
        'user' => 'App\\Models\\User',
    ],

    /*
    |--------------------------------------------------------------------------
    | Slugs
    |--------------------------------------------------------------------------
    |
    | One slug per locale, stable once set. With `history` on, a changed slug keeps working
    | and redirects (needs sluggable's slug_history table: publish `sluggable-migrations`).
    |
    */

    'slugs' => [
        'history' => env('EVENTS_SLUG_HISTORY', 'off'),
        'max_length' => 120,
    ],

];
