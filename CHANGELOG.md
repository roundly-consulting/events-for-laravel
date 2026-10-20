# Changelog

All notable changes to `events-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- `PATCH /events/{event}/attendees/{rsvp}` checks `manageAttendees` before anything else: an empty body no longer returns the managers' view of the RSVP to any signed-in user (it is a 403, and a manager gets a 422 naming the missing `tier` / `guests`).
- A checkout requested for a hold that was refreshed during the collector call (a new party size or ticket type, so a new amount) is discarded and reported with `RsvpPaymentRequestDiscarded` instead of being attached to the new hold — paying the old, smaller checkout no longer confirms the larger party.
- Hosts that use immutable dates (`Date::use(CarbonImmutable::class)`) can save the package's models again: their UTC timestamps no longer throw a `TypeError`.

## 1.0.0 - 2026-10-20

Initial public release.

### Added

- Publish-only migrations for venues, events, ticket types, RSVPs, co-hosts, invitations and reminder deliveries, with configurable table names (`events.tables.*`) and key types (`events.primary_key_type`, `events.key_type`).
- The `Event`, `Venue`, `AdmissionTier` and `Rsvp` models (swappable through `events.models.*`) with factories.
- UTC storage for every instant, an IANA timezone per event, and wall-clock input read in the event's timezone (DST gaps refused, overlaps take the earlier time). A timezone change keeps a timed event's instants and an all-day event's days; `allDayThrough` alone moves an all-day event's last day.
- A swappable status workflow on lifecycle-for-laravel (`events.lifecycle`): draft, scheduled, postponed, rescheduled, cancelled and completed, completing automatically at `ends_at`.
- Per-locale event slugs and time scopes: `upcoming()`, `ongoing()`, `past()`, `between()`, `startingBetween()` and `onLocalDate()`.
- Reusable venues (`Events::venues()`, with `by($actor)` checking the venue policy on every write): a translatable name, one address, coordinates, an IANA timezone, an optional owner (whose users manage it; shared venues are managed by admins and holders of `events.authorization.abilities.shared_venues`), opt-in geocoding through geolocation-for-laravel (never storing the pipeline's default location) and a `near()` scope with the exact great-circle distance. A new name, address or coordinates raise the calendar `SEQUENCE` of the venue's announced events.
- The `Events` facade (with dependency injection and raw actions as equal styles): `Events::make()` builds and publishes an event in one fluent chain; `Events::for($event)` publishes, postpones, reschedules (keeping the announced date), cancels, completes, moves online, edits and deletes it; `Events::browse()`, `visibleTo()` and `findBySlug()` find what a viewer may see.
- Public, unlisted (link-only, by uuid) and private (invite-only) events, one visibility rule for the policy and the query scopes, and managers through the organizer, co-hosts and an admin ability.
- A poster in a media-library bucket with queued webp variants and a URL that never throws.
- Ticket types per event (`Events::for($event)->tiers()`) with keys that are never suffixed, prices in Money, capacity, sale windows and party-size limits.
- RSVPs (`Events::for($event)->rsvp($user)->going()`, `$user->rsvp($event)` through `AttendsEvents`): going, interested or not going, with guests within each ticket type's party limits, event and ticket type capacity, and a waitlist that promotes first fit in order — race-free under concurrent requests on PostgreSQL and MySQL, with no queue-jumping and an automatic cutoff before the start. Every release promotes in the same transaction (a lapsed hold a webhook releases included), and seats freed while postponed move up on the reschedule. The ticket type an RSVP holds stays usable by its holder after its sale ends; checked-in guests keep their seats.
- Paid ticket types hold seats while a payment is pending: a host `CollectsAdmissionPayment` collector opens the checkout, your webhook confirms with `Events::rsvps()->confirmPayment()`, late and declined payments are refused for refund (from the start on, always — holds end at an earlier new start too), a going party on a paid ticket type changes by hand only, a seat that becomes going without a payment drops the old checkout (its webhook is not found), `RsvpPaymentRequestDiscarded` reports a checkout made for a hold that changed meanwhile so you can void it, `$rsvp->paymentIdempotencyKey()` lets your provider dedupe repeated requests for one hold, and `Events::sweep()` releases lapsed holds.
- Managers' list tools (`attendees()`: add, remove, change ticket type or guests, confirm, release) and waitlist tools (`waitlist()`: list, promote, position), and `counts()`.
- Check-in at the door: unguessable 160-bit codes (encrypted, hash-indexed), a QR code that is never cached (`$rsvp->checkInQr()`), scoped and unscoped scans that are idempotent and refuse other events' codes, a check-in window, manual check-in and undo, and code rotation.
- Co-hosts (`hosts()`), organizer transfer (organizer-scoped labels move along), `Concerns\OrganizesEvents` and invite-only events: `invitations()->send()` mints a bearer token stored as a hash only and expiring at the event's current start, `Events::invitations()->accept()` / `decline()` answer from the link (an account invitee signed in as themselves; `Events::invitations()->asSystem()` for trusted code) under the same lock a revocation takes, and invitations sent on a draft go out when it is published.
- Categories and labels through tags-for-laravel: one curated, global category tree (`Events::categories()`, `->category()` / `->categorize()`, `inCategory()` with sub-categories) and free-form labels kept out of tags' JSON API (`->labels()`, `labelled()`), optionally per organizer.
- Near me: `near($point, km: 25)`, `withDistance()` and `orderByDistance()` (events without a venue last), the same great-circle distance in SQL and PHP on MySQL and PostgreSQL.
- Calendars and search engines: `.ics` files per event and for lists (`Events::for($event)->ics()`, `Events::ics()`), a public feed and a personal one (`Events::feeds()`, `$user->eventFeed()`), schema.org JSON-LD (`Events::jsonLd()`, `@eventJsonLd($event)`; an ended sale is `SoldOut` with `validThrough`) and URL templates (`events.urls.*`, or your own `Contracts\EventUrls`). Join links reach only managers and going attendees.
- An opt-in JSON API (`EVENTS_ROUTES=on`): events, status verbs, poster, ticket types, your own RSVP and its QR, the guest list, door scans, the waitlist, co-hosts, invitations, calendar files and venues — with `filter[…]` and `sort` on the event list (time, near-me, category, labels, organizer, venue, mode, free, available, search, status, your own), role-aware resources (hidden counts stay hidden in ticket types and the popularity sort; guest list filters are managers'), public filters that are no existence oracle, `PATCH` that changes only the fields sent (address parts included), per-route rate limits and `{message, code}` errors. Related people render through swappable `events.resources.*` resources, never as class names.
- Notices in English and Slovak (invitation, seat confirmed, waitlist promotion, reminder, change, postponed, rescheduled, cancelled, moved online): queued (invitations encrypted), sent after the commit, re-checked at delivery, swappable or off per type, and silent with `Events::for($event)->silently()`.
- Reminders at ISO-8601 offsets between `PT5M` and `P31D`, at most five, per event or in config (`P1D` keeps the local wall clock across DST; all-day events count back from a civil hour on their first day), sent exactly once by `events:sweep`.
- Commands: `events:install`, `events:doctor` (also checking reminder offsets, hold durations and organizer types outside `events.organizers`), `events:sweep`, `events:repair` (counters, orphaned RSVPs) and `events:geocode`, plus an `about` section.
- A recording, still-performing `Events::fake()` with an assertion pair for every operation (plus `assertPerformed()` / `assertNotPerformed()`; nothing recorded for a rolled-back transaction), and Pest expectations (`toBeGoingTo`, `toBeWaitlistedFor`, `toBeCheckedInTo`).
- English and Slovak translations.
