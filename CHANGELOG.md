# Changelog

All notable changes to `events-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Publish-only migrations for venues, events, ticket types, RSVPs, co-hosts, invitations and reminder deliveries, with configurable table names (`events.tables.*`) and key types (`events.primary_key_type`, `events.key_type`).
- The `Event`, `Venue`, `AdmissionTier` and `Rsvp` models (swappable through `events.models.*`) with factories.
- UTC storage for every instant, an IANA timezone per event, and wall-clock input read in the event's timezone (DST gaps refused, overlaps take the earlier time).
- A swappable status workflow on lifecycle-for-laravel (`events.lifecycle`): draft, scheduled, postponed, rescheduled, cancelled and completed, completing automatically at `ends_at`.
- Per-locale event slugs and time scopes: `upcoming()`, `ongoing()`, `past()`, `between()`, `startingBetween()` and `onLocalDate()`.
- Reusable venues (`Events::venues()`): a translatable name, one address, coordinates, an IANA timezone, an optional owner, opt-in geocoding through geolocation-for-laravel (never storing the pipeline's default location) and a `near()` scope with the exact great-circle distance.
- The `Events` facade (with dependency injection and raw actions as equal styles): `Events::make()` builds and publishes an event in one fluent chain; `Events::for($event)` publishes, postpones, reschedules (keeping the announced date), cancels, completes, moves online, edits and deletes it; `Events::browse()`, `visibleTo()` and `findBySlug()` find what a viewer may see.
- Public, unlisted (link-only, by uuid) and private (invite-only) events, one visibility rule for the policy and the query scopes, and managers through the organizer, co-hosts and an admin ability.
- A poster in a media-library bucket with queued webp variants and a URL that never throws.
- Ticket types per event (`Events::for($event)->tiers()`) with keys that are never suffixed, prices in Money, capacity, sale windows and party-size limits.
- RSVPs (`Events::for($event)->rsvp($user)->going()`, `$user->rsvp($event)` through `AttendsEvents`): going, interested or not going, with guests within each ticket type's party limits, event and ticket type capacity, and a waitlist that promotes first fit in order — race-free under concurrent requests on PostgreSQL and MySQL, with no queue-jumping and an automatic cutoff before the start.
- Paid ticket types hold seats while a payment is pending: a host `CollectsAdmissionPayment` collector opens the checkout, your webhook confirms with `Events::rsvps()->confirmPayment()`, late and declined payments are refused for refund, and `Events::sweep()` releases lapsed holds.
- Managers' list tools (`attendees()`: add, remove, change ticket type or guests, confirm, release) and waitlist tools (`waitlist()`: list, promote, position), and `counts()`.
- Check-in at the door: unguessable 160-bit codes (encrypted, hash-indexed), a QR code that is never cached (`$rsvp->checkInQr()`), scoped and unscoped scans that are idempotent and refuse other events' codes, a check-in window, manual check-in and undo, and code rotation.
- Co-hosts (`hosts()`), organizer transfer, `Concerns\OrganizesEvents` and invite-only events: `invitations()->send()` mints a bearer token stored as a hash only, `Events::invitations()->accept()` / `decline()` answer from the link.
- Categories and labels through tags-for-laravel: one curated, global category tree (`Events::categories()`, `->category()` / `->categorize()`, `inCategory()` with sub-categories) and free-form labels kept out of tags' JSON API (`->labels()`, `labelled()`), optionally per organizer.
- Near me: `near($point, km: 25)`, `withDistance()` and `orderByDistance()` (events without a venue last), the same great-circle distance in SQL and PHP on MySQL and PostgreSQL.
- Calendars and search engines: `.ics` files per event and for lists (`Events::for($event)->ics()`, `Events::ics()`), a public feed and a personal one (`Events::feeds()`, `$user->eventFeed()`), schema.org JSON-LD (`Events::jsonLd()`, `@eventJsonLd($event)`) and URL templates (`events.urls.*`, or your own `Contracts\EventUrls`). Join links reach only managers and going attendees.
- An opt-in JSON API (`EVENTS_ROUTES=on`): events, status verbs, poster, ticket types, your own RSVP and its QR, the guest list, door scans, the waitlist, co-hosts, invitations, calendar files and venues — with `filter[…]` and `sort` on the event list (time, near-me, category, labels, organizer, venue, mode, free, available, search, status, your own), role-aware resources, per-route rate limits and `{message, code}` errors. Related people render through swappable `events.resources.*` resources, never as class names.
- A recording, still-performing `Events::fake()` with paired assertions, and Pest expectations (`toBeGoingTo`, `toBeWaitlistedFor`, `toBeCheckedInTo`).
- English and Slovak translations.
