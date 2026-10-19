<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Tags\Rules\TagsRule;

/**
 * `PATCH /events/{event}` — only the fields sent change; `null` clears a nullable one. A new start
 * on an announced event goes through `POST /reschedule`. `?notify=0` writes silently.
 *
 * @internal
 */
final class UpdateEventRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event = $this->routeEvent();
        $labels = TagsRule::for(EventTagType::Label);

        return [
            'title' => ['sometimes', 'required', self::text(200)],
            'description' => ['sometimes', 'nullable', self::text(20_000)],
            'venue_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'online_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'attendance_mode' => ['sometimes', 'nullable', Rule::enum(AttendanceMode::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'timezone' => ['sometimes', 'timezone:all'],
            'starts_at' => ['sometimes', 'string', 'max:64'],
            'ends_at' => ['sometimes', 'string', 'max:64'],
            'duration' => ['sometimes', 'string', 'max:32'],
            'all_day_from' => ['sometimes', 'date_format:Y-m-d'],
            'all_day_through' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'doors_open_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'max_guests' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'waitlist' => ['sometimes', 'boolean'],
            'waitlist_auto_promote' => ['sometimes', 'boolean'],
            'rsvp_opens_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rsvp_closes_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'guest_list' => ['sometimes', Rule::enum(GuestList::class)],
            'show_counts' => ['sometimes', 'boolean'],
            'reminders' => ['sometimes', 'nullable', 'array', 'max:5'],
            'reminders.*' => ['string', 'max:32'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255', TagsRule::for(EventTagType::Category)],
            'labels' => ['sometimes', 'array', 'max:10', $event === null ? $labels : $labels->taggable($event)],
            'labels.*' => ['string', 'max:255'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): UpdateEventData
    {
        $data = $this->validated();
        $given = static fn (string $key): bool => array_key_exists($key, $data);

        return new UpdateEventData(
            title: $given('title') ? $data['title'] : NotGiven::Value,
            description: $given('description') ? $data['description'] : NotGiven::Value,
            startsAt: $given('starts_at') ? $data['starts_at'] : NotGiven::Value,
            endsAt: $given('ends_at') ? $data['ends_at'] : NotGiven::Value,
            duration: $given('duration') ? $data['duration'] : NotGiven::Value,
            allDayFrom: $given('all_day_from') ? $data['all_day_from'] : NotGiven::Value,
            allDayThrough: $given('all_day_through') ? $data['all_day_through'] : NotGiven::Value,
            doorsOpenAt: $given('doors_open_at') ? $data['doors_open_at'] : NotGiven::Value,
            timezone: $given('timezone') ? $data['timezone'] : NotGiven::Value,
            venue: $given('venue_id') ? ($data['venue_id'] === null ? null : (int) $data['venue_id']) : NotGiven::Value,
            onlineUrl: $given('online_url') ? $data['online_url'] : NotGiven::Value,
            attendanceMode: $given('attendance_mode') ? ($data['attendance_mode'] === null ? null : AttendanceMode::from($data['attendance_mode'])) : NotGiven::Value,
            visibility: $given('visibility') ? Visibility::from($data['visibility']) : NotGiven::Value,
            capacity: $given('capacity') ? ($data['capacity'] === null ? null : (int) $data['capacity']) : NotGiven::Value,
            maxGuests: $given('max_guests') ? (int) $data['max_guests'] : NotGiven::Value,
            waitlist: $given('waitlist') ? (bool) $data['waitlist'] : NotGiven::Value,
            waitlistAutoPromote: $given('waitlist_auto_promote') ? (bool) $data['waitlist_auto_promote'] : NotGiven::Value,
            rsvpOpensAt: $given('rsvp_opens_at') ? $data['rsvp_opens_at'] : NotGiven::Value,
            rsvpClosesAt: $given('rsvp_closes_at') ? $data['rsvp_closes_at'] : NotGiven::Value,
            guestList: $given('guest_list') ? GuestList::from($data['guest_list']) : NotGiven::Value,
            showCounts: $given('show_counts') ? (bool) $data['show_counts'] : NotGiven::Value,
            reminders: $given('reminders') ? ($data['reminders'] === null ? null : array_values($data['reminders'])) : NotGiven::Value,
        );
    }

    public function changesCategory(): bool
    {
        return array_key_exists('category', $this->validated());
    }

    public function category(): ?string
    {
        $category = $this->validated('category');

        return is_string($category) ? $category : null;
    }

    /**
     * @return list<string>|null null when the labels were not sent
     */
    public function labels(): ?array
    {
        $data = $this->validated();

        return array_key_exists('labels', $data) ? array_values($data['labels']) : null;
    }

    public function silent(): bool
    {
        return array_key_exists('notify', $this->validated()) && ! $this->validated('notify');
    }
}
