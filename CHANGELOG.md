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
- English and Slovak translations.
