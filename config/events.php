<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Http\Resources\EventActorResource;
use RoundlyConsulting\Events\Http\Resources\EventAttendeeResource;
use RoundlyConsulting\Events\Http\Resources\EventHostResource;
use RoundlyConsulting\Events\Http\Resources\EventInviteeResource;
use RoundlyConsulting\Events\Http\Resources\EventOrganizerResource;
use RoundlyConsulting\Events\Http\Resources\VenueOwnerResource;
use RoundlyConsulting\Events\Lifecycle\EventLifecycle;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Notifications\EventCancelledNotification;
use RoundlyConsulting\Events\Notifications\EventInvitationNotification;
use RoundlyConsulting\Events\Notifications\EventMovedOnlineNotification;
use RoundlyConsulting\Events\Notifications\EventPostponedNotification;
use RoundlyConsulting\Events\Notifications\EventReminderNotification;
use RoundlyConsulting\Events\Notifications\EventRescheduledNotification;
use RoundlyConsulting\Events\Notifications\EventUpdatedNotification;
use RoundlyConsulting\Events\Notifications\RsvpConfirmedNotification;
use RoundlyConsulting\Events\Notifications\WaitlistPromotedNotification;
use RoundlyConsulting\Events\Payments\NoPaymentCollector;
use RoundlyConsulting\Events\Policies\EventPolicy;
use RoundlyConsulting\Events\Policies\VenuePolicy;
use RoundlyConsulting\Events\Support\TemplateEventUrls;

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
        'default_radius_km' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Category & labels (tags-for-laravel)
    |--------------------------------------------------------------------------
    |
    | Events declares two tag types: `events:category` (one curated, global category tree) and
    | `events:label` (free-form, at most ten per event, kept out of tags' JSON API). Labels are
    | global by default; `organizer` gives every organizer their own label catalogue (then
    | `events.primary_key_type`, `events.key_type` and `tags.key_type` must be equal).
    |
    */

    'tags' => [
        'scope' => null,
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
    | Waitlist and payments
    |--------------------------------------------------------------------------
    |
    | Freed seats go to the waitlist automatically (first fit, in order) until
    | `stop_promoting_before` the start — late promotions fill seats with people who do not
    | come; managers can still promote by hand. A paid ticket type holds the seats for
    | `hold_for` (`promotion_hold` for a promoted attendee) while your payment collector — a
    | class implementing Contracts\CollectsAdmissionPayment — opens a checkout; confirm it from
    | your webhook with `Events::rsvps()->confirmPayment($reference)`.
    |
    */

    'waitlist' => [
        'stop_promoting_before' => 'PT1H',
    ],

    'payments' => [
        'collector' => NoPaymentCollector::class,
        'hold_for' => 'PT30M',
        'promotion_hold' => 'PT24H',
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in
    |--------------------------------------------------------------------------
    |
    | Codes scan from `opens_before` the start until `closes_after` the end. With `url`
    | (e.g. https://example.com/check-in/{code}) the QR code carries that link instead of the
    | bare code; check-in reads both.
    |
    */

    'check_in' => [
        'opens_before' => 'PT3H',
        'closes_after' => 'PT2H',
        'url' => env('EVENTS_CHECK_IN_URL'),
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
    | URLs
    |--------------------------------------------------------------------------
    |
    | Where your app shows an event, an invitation and an attendee's ticket — used in JSON-LD,
    | calendar files and notification buttons. Templates take `{uuid}`, `{slug}` and `{locale}`
    | (events), `{token}` (invitations) and `{uuid}` + `{rsvp}` (tickets); blank means no link.
    | Unlisted and private events are reached by uuid only: they use `event_link`, and never a
    | `{slug}` template. Bind your own `Contracts\EventUrls` in `resolver` to call `route()`.
    |
    */

    'urls' => [
        'resolver' => TemplateEventUrls::class,
        'event' => env('EVENTS_URL'),
        'event_link' => env('EVENTS_LINK_URL'),
        'invitation' => env('EVENTS_INVITATION_URL'),
        'rsvp' => env('EVENTS_RSVP_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search engines (schema.org JSON-LD)
    |--------------------------------------------------------------------------
    |
    | `@eventJsonLd($event)` renders structured data for public, announced events. Unlisted
    | events need `unlisted` on; private events never get it. The join link stays out unless
    | `expose_online_url` is on (the event page URL is used instead).
    |
    */

    'seo' => [
        'json_ld' => [
            'type' => 'Event',
            'unlisted' => 'off',
            'expose_online_url' => 'off',
            'organizer_type' => 'Organization',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar feeds (.ics)
    |--------------------------------------------------------------------------
    |
    | The public feed lists announced, listed public events and keeps cancelled ones for
    | `keep_cancelled_for`, so subscribed calendars receive the cancellation.
    |
    */

    'feeds' => [
        'keep_cancelled_for' => 'P30D',
        'max_events' => 500,
        'name' => env('APP_NAME', 'Events'),
    ],

    /*
    |--------------------------------------------------------------------------
    | How related host models render over the API
    |--------------------------------------------------------------------------
    |
    | Every person or team the API mentions (organizer, co-host, attendee, invitee, an actor,
    | a venue's owner) renders through its own resource, by default `{id, type}` with the
    | class basename — never a class name. Point a key at your own JsonResource to add a name
    | or an avatar; it receives the model.
    |
    */

    'resources' => [
        'organizer' => EventOrganizerResource::class,
        'host' => EventHostResource::class,
        'attendee' => EventAttendeeResource::class,
        'invitee' => EventInviteeResource::class,
        'actor' => EventActorResource::class,
        'venue_owner' => VenueOwnerResource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API (opt-in)
    |--------------------------------------------------------------------------
    |
    | Off unless `EVENTS_ROUTES=on`. Reads allow guests (visibility applies); writes add
    | `auth_middleware`. Rate limits are per minute.
    |
    */

    'routes' => [
        'enabled' => env('EVENTS_ROUTES') ?: 'off',
        'prefix' => 'api',
        'middleware' => ['api', 'throttle:events-api'],
        'auth_middleware' => ['auth'],
        'max_per_page' => 100,
        'rate_limits' => [
            'api' => 120,
            'rsvp' => 20,
            'check_in' => 300,
            'feeds' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Queued, sent after the commit, in each recipient's locale, with times in the event's own
    | timezone. Swap a type for your own class (it must extend the package's) or turn it off
    | with `false`. `audiences` lists who hears about an event-wide change, by RSVP status.
    | `Events::for($event)->silently()` writes without notices.
    |
    */

    'notifications' => [
        'enabled' => env('EVENTS_NOTIFICATIONS', 'on'),
        'channels' => ['mail'],
        'queue' => null,
        'notify_actor' => false,
        'types' => [
            'invited' => EventInvitationNotification::class,
            'confirmed' => RsvpConfirmedNotification::class,
            'waitlist_promoted' => WaitlistPromotedNotification::class,
            'reminder' => EventReminderNotification::class,
            'updated' => EventUpdatedNotification::class,
            'postponed' => EventPostponedNotification::class,
            'rescheduled' => EventRescheduledNotification::class,
            'cancelled' => EventCancelledNotification::class,
            'moved_online' => EventMovedOnlineNotification::class,
        ],
        'audiences' => [
            'reminder' => ['going'],
            'updated' => ['going', 'held'],
            'postponed' => ['going', 'held', 'waitlisted', 'interested'],
            'rescheduled' => ['going', 'held', 'waitlisted', 'interested'],
            'cancelled' => ['going', 'held', 'waitlisted', 'interested'],
            'moved_online' => ['going', 'held'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminders
    |--------------------------------------------------------------------------
    |
    | ISO-8601 offsets before the start (between PT5M and P31D), sent once each by
    | `events:sweep`. A day offset keeps the local wall clock across a DST change; all-day
    | events are reminded at `all_day_at` local time. A reminder later than `max_lateness` is
    | dropped. An event's own `reminders` replace these.
    |
    */

    'reminders' => [
        'offsets' => [],
        'max_lateness' => 'PT1H',
        'all_day_at' => '09:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The policy decides who views and manages events. Managers are the organizer's approved
    | users, co-hosts' approved users, and holders of the `admin` ability (undefined = nobody).
    | `create` / `venues` name a Gate ability that may create events / venues; null lets any
    | authenticated user. An owned venue is managed by the users its owner allows; a shared
    | venue (no owner, used by many organizers' events) by admins and holders of
    | `shared_venues` — null = admins only.
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
            'shared_venues' => null,
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
