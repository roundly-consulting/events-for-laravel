<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Seo;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\Posters;
use RoundlyConsulting\Events\Support\TierInput;

/**
 * schema.org `Event` structured data for search engines, in the current locale.
 *
 * Only for public events past the Draft phase; unlisted events need
 * `events.seo.json_ld.unlisted`; private events never (no markup for invite-only events).
 * Times carry the event's own UTC offset; all-day events are date-only with the inclusive last
 * day. The join link stays out unless `events.seo.json_ld.expose_online_url` is on.
 *
 * @internal reach it through `Events::jsonLd()`, `$event->toJsonLd()` or `@eventJsonLd($event)`
 */
final readonly class EventJsonLd
{
    private const string SCHEMA = 'https://schema.org/';

    public function __construct(
        private EventUrls $urls,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Event $event): ?array
    {
        if (! $this->emits($event)) {
            return null;
        }

        $url = $this->urls->event($event);
        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => EventsConfig::jsonLdType(),
            'name' => (string) $event->title,
            'description' => $this->description($event),
            'startDate' => $this->date($event, $event->starts_at, start: true),
            'endDate' => $this->date($event, $event->ends_at, start: false),
            'doorTime' => $event->all_day || $event->doors_open_at === null ? null : $event->doors_open_at->setTimezone($event->timezone)->toIso8601String(),
            'eventStatus' => self::SCHEMA.self::status($event),
            'previousStartDate' => $event->phase === EventPhase::Rescheduled && $event->previous_starts_at !== null
                ? $this->date($event, $event->previous_starts_at, start: true)
                : null,
            'eventAttendanceMode' => $event->attendance_mode->schemaOrg(),
            'location' => $this->location($event, $url),
            'image' => $this->images($event),
            'offers' => $this->offers($event, $url),
            'organizer' => $this->organizer($event),
            'keywords' => $this->keywords($event),
            'url' => $url,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        return $data;
    }

    private function emits(Event $event): bool
    {
        if ($event->phase === EventPhase::Draft) {
            return false;
        }

        return match ($event->visibility) {
            Visibility::Public => true,
            Visibility::Unlisted => EventsConfig::jsonLdForUnlisted(),
            Visibility::Private => false,
        };
    }

    private function description(Event $event): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $event->description)));

        return $text === '' ? null : mb_strimwidth($text, 0, 300, '…');
    }

    private function date(Event $event, CarbonImmutable $moment, bool $start): string
    {
        if ($event->all_day) {
            // A start is the moment's local day (the current start, or the announced one a
            // reschedule kept); the end is the inclusive last day.
            return $start ? $moment->setTimezone($event->timezone)->format('Y-m-d') : (string) $event->ends_on;
        }

        return $moment->setTimezone($event->timezone)->toIso8601String();
    }

    /**
     * The schema.org EventStatus name of the event's phase (also the API's `schema_status`).
     */
    public static function status(Event $event): string
    {
        return match ($event->phase) {
            EventPhase::Postponed => 'EventPostponed',
            EventPhase::Rescheduled => 'EventRescheduled',
            EventPhase::Cancelled => 'EventCancelled',
            default => $event->moved_online_at !== null ? 'EventMovedOnline' : 'EventScheduled',
        };
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function location(Event $event, ?string $url): ?array
    {
        $places = [];
        $venue = $event->venue;

        if ($venue instanceof Venue) {
            $places[] = $this->place($venue);
        }

        $virtual = EventsConfig::jsonLdExposesOnlineUrl() && $event->online_url !== null ? $event->online_url : $url;

        if ($event->online_url !== null && $virtual !== null) {
            $places[] = ['@type' => 'VirtualLocation', 'url' => $virtual];
        }

        return match (count($places)) {
            0 => null,
            1 => $places[0],
            default => $places,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function place(Venue $venue): array
    {
        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $venue->street,
            'addressLocality' => $venue->city,
            'addressRegion' => $venue->region,
            'postalCode' => $venue->postal_code,
            'addressCountry' => $venue->country_code,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $place = ['@type' => 'Place', 'name' => (string) $venue->name, 'address' => count($address) > 1 ? $address : $venue->formattedAddress()];
        $coordinates = $venue->coordinates;

        if ($coordinates !== null) {
            $place['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $coordinates->latitude, 'longitude' => $coordinates->longitude];
        }

        return $place;
    }

    /**
     * The poster in Google's 1:1, 4:3 and 16:9 shapes.
     *
     * @return list<string>
     */
    private function images(Event $event): array
    {
        if (Posters::of($event) === null) {
            return [];
        }

        $urls = [];

        foreach (['square', 'landscape', 'wide'] as $variant) {
            $url = Posters::url($event, $variant);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * One Offer per visible ticket type, or one free Offer for an event without any.
     *
     * @return list<array<string, mixed>>
     */
    private function offers(Event $event, ?string $url): array
    {
        $tiers = $event->tiers->filter(static fn (AdmissionTier $tier): bool => ! $tier->hidden);

        if ($tiers->isEmpty()) {
            return [array_filter([
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => TierInput::defaultCurrency(),
                'availability' => self::SCHEMA.($event->isFull() && ! $event->waitlist ? 'SoldOut' : 'InStock'),
                'url' => $url,
            ], static fn (mixed $value): bool => $value !== null)];
        }

        return array_values($tiers->map(fn (AdmissionTier $tier): array => array_filter([
            '@type' => 'Offer',
            'name' => (string) $tier->name,
            'price' => $tier->price === null || $tier->price->isZero() ? '0' : $tier->price->toDecimal(),
            'priceCurrency' => $tier->price?->currency()->code ?? TierInput::defaultCurrency(),
            'availability' => self::SCHEMA.$this->availability($event, $tier),
            'validFrom' => $tier->sale_starts_at?->setTimezone($event->timezone)->toIso8601String(),
            'validThrough' => $tier->sale_ends_at?->setTimezone($event->timezone)->toIso8601String(),
            'url' => $url,
        ], static fn (mixed $value): bool => $value !== null))->all());
    }

    private function availability(Event $event, AdmissionTier $tier): string
    {
        if ($tier->sale_starts_at !== null && $tier->sale_starts_at->greaterThan(Clock::now())) {
            return 'PreOrder';
        }

        // A sale that ended sells nothing any more.
        if ($tier->sale_ends_at !== null && ! $tier->sale_ends_at->greaterThan(Clock::now())) {
            return 'SoldOut';
        }

        $left = $tier->seatsLeft();

        return ($left !== null && $left <= 0) || $event->isFull() ? 'SoldOut' : 'InStock';
    }

    /**
     * @return array<string, string>|null
     */
    private function organizer(Event $event): ?array
    {
        $organizer = $event->organizer;

        if (! $organizer instanceof Organizer) {
            return null;
        }

        return array_filter([
            '@type' => EventsConfig::jsonLdOrganizerType(),
            'name' => $organizer->eventOrganizerName(),
            'url' => $organizer->eventOrganizerUrl(),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function keywords(Event $event): ?string
    {
        $names = $event->labels->map(static fn ($tag): string => (string) $tag->getTranslation('name', app()->getLocale()))->filter()->all();

        return $names === [] ? null : implode(', ', $names);
    }
}
