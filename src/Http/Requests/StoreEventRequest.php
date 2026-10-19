<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Geolocation\Rules\CoordinatesRule;
use RoundlyConsulting\Money\Rules\CurrencyCode;
use RoundlyConsulting\Tags\Rules\TagsRule;

/**
 * `POST /events` — a draft (or, with `publish: true`, announced at once). Times are wall clock
 * in the event's timezone unless they carry an offset.
 *
 * @internal
 */
final class StoreEventRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', self::text(200)],
            'description' => ['nullable', self::text(20_000)],
            ...self::reference('organizer'),
            'venue_id' => ['nullable', 'integer', 'min:1', 'prohibits:venue'],
            'venue' => ['nullable', 'array'],
            'venue.name' => ['required_with:venue', self::text(200)],
            'venue.street' => ['nullable', 'string', 'max:255'],
            'venue.city' => ['nullable', 'string', 'max:255'],
            'venue.region' => ['nullable', 'string', 'max:255'],
            'venue.postal_code' => ['nullable', 'string', 'max:32'],
            'venue.country' => ['nullable', 'string', 'size:2'],
            'venue.coordinates' => ['nullable', new CoordinatesRule],
            'venue.timezone' => ['nullable', 'timezone:all'],
            'online_url' => ['nullable', 'string', 'max:2048'],
            'attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            'visibility' => ['nullable', Rule::enum(Visibility::class)],
            'timezone' => ['nullable', 'timezone:all'],
            'starts_at' => ['required_without:all_day_from', 'nullable', 'string', 'max:64'],
            'ends_at' => ['nullable', 'string', 'max:64'],
            'duration' => ['nullable', 'string', 'max:32'],
            'all_day_from' => ['nullable', 'date_format:Y-m-d'],
            'all_day_through' => ['nullable', 'date_format:Y-m-d'],
            'doors_open_at' => ['nullable', 'string', 'max:64'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'max_guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'waitlist' => ['nullable', 'boolean'],
            'waitlist_auto_promote' => ['nullable', 'boolean'],
            'rsvp_opens_at' => ['nullable', 'string', 'max:64'],
            'rsvp_closes_at' => ['nullable', 'string', 'max:64'],
            'guest_list' => ['nullable', Rule::enum(GuestList::class)],
            'show_counts' => ['nullable', 'boolean'],
            'reminders' => ['nullable', 'array', 'max:5'],
            'reminders.*' => ['string', 'max:32'],
            'category' => ['nullable', 'string', 'max:255', TagsRule::for(EventTagType::Category)],
            'labels' => ['nullable', 'array', 'max:10', TagsRule::for(EventTagType::Label)->within($this->tagScope())],
            'labels.*' => ['string', 'max:255'],
            'tiers' => ['nullable', 'array', 'max:50'],
            'tiers.*.name' => ['required', self::text(200)],
            'tiers.*.key' => ['nullable', 'string', 'max:64'],
            'tiers.*.price' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'tiers.*.currency' => ['nullable', 'string', new CurrencyCode],
            'tiers.*.capacity' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'tiers.*.hidden' => ['nullable', 'boolean'],
            'poster_draft' => ['nullable', 'string', 'max:255'],
            'publish' => ['nullable', 'boolean'],
        ];
    }

    public function organizer(Model $user): Model
    {
        /** @var array<string, mixed>|null $reference */
        $reference = $this->validated('organizer');

        return $reference === null ? $user : self::resolvePerson($reference, 'organizer');
    }

    /**
     * The inline venue, if the request creates one.
     */
    public function venueData(Model $user): ?VenueData
    {
        /** @var array<string, mixed>|null $venue */
        $venue = $this->validated('venue');

        if ($venue === null) {
            return null;
        }

        return new VenueData(
            name: $venue['name'],
            address: new AddressData(
                street: $venue['street'] ?? null,
                city: $venue['city'] ?? null,
                region: $venue['region'] ?? null,
                postalCode: $venue['postal_code'] ?? null,
                country: $venue['country'] ?? null,
            ),
            coordinates: self::coordinates($venue['coordinates'] ?? null),
            timezone: $venue['timezone'] ?? null,
            // Like POST /event-venues without an owner: the caller's own venue.
            owner: $user,
            actor: $user,
        );
    }

    public function toData(Model $user, Model $organizer, ?int $venue): CreateEventData
    {
        $data = $this->validated();

        return new CreateEventData(
            title: $data['title'],
            organizer: $organizer,
            schedule: new ScheduleData(
                startsAt: $data['starts_at'] ?? null,
                endsAt: $data['ends_at'] ?? null,
                duration: $data['duration'] ?? null,
                allDayFrom: $data['all_day_from'] ?? null,
                allDayThrough: $data['all_day_through'] ?? null,
                doorsOpenAt: $data['doors_open_at'] ?? null,
                timezone: $data['timezone'] ?? null,
            ),
            description: $data['description'] ?? null,
            venue: $venue ?? (isset($data['venue_id']) ? (int) $data['venue_id'] : null),
            onlineUrl: $data['online_url'] ?? null,
            attendanceMode: isset($data['attendance_mode']) ? AttendanceMode::from($data['attendance_mode']) : null,
            visibility: isset($data['visibility']) ? Visibility::from($data['visibility']) : null,
            capacity: isset($data['capacity']) ? (int) $data['capacity'] : null,
            maxGuests: isset($data['max_guests']) ? (int) $data['max_guests'] : null,
            waitlist: isset($data['waitlist']) ? (bool) $data['waitlist'] : null,
            waitlistAutoPromote: isset($data['waitlist_auto_promote']) ? (bool) $data['waitlist_auto_promote'] : null,
            rsvpOpensAt: $data['rsvp_opens_at'] ?? null,
            rsvpClosesAt: $data['rsvp_closes_at'] ?? null,
            guestList: isset($data['guest_list']) ? GuestList::from($data['guest_list']) : null,
            showCounts: isset($data['show_counts']) ? (bool) $data['show_counts'] : null,
            reminders: isset($data['reminders']) ? array_values($data['reminders']) : null,
            tiers: array_map(static fn (array $tier): TierData => new TierData(
                name: $tier['name'],
                key: $tier['key'] ?? null,
                price: self::price($tier['price'] ?? null, $tier['currency'] ?? null),
                capacity: isset($tier['capacity']) ? (int) $tier['capacity'] : null,
                hidden: (bool) ($tier['hidden'] ?? false),
            ), array_values($data['tiers'] ?? [])),
            posterDraft: $data['poster_draft'] ?? null,
            category: $data['category'] ?? null,
            labels: array_values($data['labels'] ?? []),
            actor: $user,
        );
    }

    public function publishes(): bool
    {
        return (bool) $this->validated('publish', false);
    }

    /**
     * Labels live in the organizer's catalogue when `events.tags.scope = organizer`.
     */
    private function tagScope(): ?Model
    {
        if (! EventsConfig::tagsScopedByOrganizer()) {
            return null;
        }

        $reference = $this->input('organizer');
        $user = $this->user();

        try {
            return is_array($reference) ? self::resolvePerson($reference, 'organizer') : ($user instanceof Model ? $user : null);
        } catch (\Throwable) {
            return null;
        }
    }
}
