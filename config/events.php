<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Policies\EventPolicy;
use RoundlyConsulting\Events\Policies\VenuePolicy;

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
    | Defaults
    |--------------------------------------------------------------------------
    |
    | What a new event gets when the builder or DTO leaves a setting out. `timezone` falls back
    | to the venue's, then to `app.timezone`; `duration` (ISO-8601) applies without an end, so
    | ends_at is never null.
    |
    */

    'defaults' => [
        'timezone' => env('EVENTS_TIMEZONE'),
        'duration' => 'PT2H',
        'visibility' => 'public',
        'max_guests' => 0,
        'guest_list' => 'hidden',
        'show_counts' => true,
        'waitlist' => true,
    ],

    'schedule' => [
        // The longest event (ISO-8601). Longer runs wait for recurring series.
        'max_duration' => 'P31D',
    ],

    /*
    |--------------------------------------------------------------------------
    | Venues and geography
    |--------------------------------------------------------------------------
    |
    | Geocoding is opt-in: `->geocode()` on the builder, `Events::venues()->geocode()`, or
    | `geocode_on_create` to queue it for every new venue without coordinates. It never sets
    | the timezone (geocoding returns none): a venue without one takes `default_timezone`,
    | else the app's.
    |
    */

    'venues' => [
        'geocode_on_create' => env('EVENTS_GEOCODE_VENUES', 'off'),
        'default_timezone' => null,
    ],

    'geo' => [
        'max_radius_km' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket types
    |--------------------------------------------------------------------------
    |
    | A price given as a plain number (`->price('15')`) is read in this currency; blank uses
    | money-for-laravel's `money.default_currency`.
    |
    */

    'tiers' => [
        'default_currency' => env('EVENTS_DEFAULT_CURRENCY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Poster
    |--------------------------------------------------------------------------
    |
    | One image per event in a media-library bucket. Variants are queued (media generates
    | them synchronously by default); `posterUrl('card')` falls back to the original until a
    | variant exists, and to `fallback_url` without a poster. `disk` blank = media's default.
    |
    */

    'poster' => [
        'bucket' => 'poster',
        'disk' => null,
        'max_kb' => 10240,
        'min_width' => 600,
        'min_height' => 315,
        'fallback_url' => env('EVENTS_POSTER_FALLBACK'),
        'variants' => [
            'thumb' => [400, 300],
            'card' => [800, 450],
            'square' => [1200, 1200],
            'landscape' => [1200, 900],
            'wide' => [1920, 1080],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The policy decides who views and manages events. Managers are the organizer's approved
    | users, co-hosts' approved users, and holders of the `admin` ability (undefined = nobody).
    | `create` / `venues` name a Gate ability that may create events / venues; null lets any
    | authenticated user.
    |
    */

    'authorization' => [
        'register_policy' => true,
        'policy' => EventPolicy::class,
        'venue_policy' => VenuePolicy::class,
        'abilities' => [
            'create' => null,
            'admin' => 'events.admin',
            'venues' => null,
        ],
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
