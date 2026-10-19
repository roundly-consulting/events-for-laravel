<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Builders;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Durations;
use RoundlyConsulting\Events\Support\EventInput;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\Timezones;
use RoundlyConsulting\Money\Money;
use SensitiveParameter;

/**
 * `Events::make($title)` — a fluent builder for a new event. `create()` builds a
 * {@see CreateEventData} and runs `Events::create()`, so builder and DTO run the same code;
 * `publish()` creates and publishes in one transaction.
 *
 * Times are stored as given and resolved on `create()`, once the timezone is known, so the order
 * of the setters never changes the instant: `->startsAt('2026-11-14 20:00')->at($venue)` reads
 * 20:00 in the venue's timezone. Setters validate eagerly where they can.
 */
final class EventBuilder
{
    /** @var array<string, string>|string|null */
    private array|string|null $description = null;

    /** @var array<string, string>|null */
    private ?array $slug = null;

    private ?Model $organizer = null;

    /** @var list<Model> */
    private array $coHosts = [];

    private Venue|int|null $venue = null;

    private ?string $onlineUrl = null;

    private ?AttendanceMode $attendanceMode = null;

    private ?string $timezone = null;

    private CarbonInterface|string|null $startsAt = null;

    private CarbonInterface|string|null $endsAt = null;

    private CarbonInterval|string|null $duration = null;

    private ?string $allDayFrom = null;

    private ?string $allDayThrough = null;

    private CarbonInterface|string|null $doorsOpenAt = null;

    private ?Visibility $visibility = null;

    private ?int $capacity = null;

    private ?int $maxGuests = null;

    private ?bool $waitlist = null;

    private ?bool $autoPromote = null;

    private CarbonInterface|string|null $rsvpOpensAt = null;

    private CarbonInterface|string|null $rsvpClosesAt = null;

    private ?GuestList $guestList = null;

    private ?bool $showCounts = null;

    /** @var list<string>|null */
    private ?array $reminders = null;

    /** @var list<TierData> */
    private array $tiers = [];

    private UploadedFile|string|null $poster = null;

    private ?string $posterDraft = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    private ?Model $actor = null;

    /**
     * @param  array<string, string>|string  $title
     */
    public function __construct(
        private readonly EventsManager $manager,
        private readonly array|string $title,
    ) {}

    /**
     * @param  array<string, string>|string  $description
     */
    public function description(array|string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Manual per-locale slugs (strict: a taken slug throws, never suffixed).
     *
     * @param  array<string, string>  $slug
     */
    public function slug(array $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function organizedBy(Model&Organizer $organizer): self
    {
        $this->organizer = $organizer;

        return $this;
    }

    public function coHostedBy(Model&Organizer ...$hosts): self
    {
        $this->coHosts = [...$this->coHosts, ...array_values($hosts)];

        return $this;
    }

    /**
     * The venue (a model or its id); its timezone is the event's unless `timezone()` says else.
     */
    public function at(Venue|int|null $venue): self
    {
        $this->venue = $venue;

        return $this;
    }

    /**
     * The join link (http or https). With a venue too, the event is hybrid.
     */
    public function online(?string $url): self
    {
        $this->onlineUrl = EventInput::onlineUrl($url);

        return $this;
    }

    /**
     * Normally derived from the venue and link; an explicit mode must agree with them.
     */
    public function attendanceMode(AttendanceMode $mode): self
    {
        $this->attendanceMode = $mode;

        return $this;
    }

    public function timezone(string $timezone): self
    {
        if (! Timezones::isValid($timezone)) {
            throw InvalidEventScheduleException::invalidTimezone($timezone);
        }

        $this->timezone = $timezone;

        return $this;
    }

    /**
     * A naked string is a wall-clock time in the event's timezone; a Carbon (or a string with an
     * offset or `Z`) is an instant.
     */
    public function startsAt(CarbonInterface|string $startsAt): self
    {
        $this->startsAt = $startsAt;
        $this->allDayFrom = null;

        return $this;
    }

    public function endsAt(CarbonInterface|string $endsAt): self
    {
        $this->endsAt = $endsAt;
        $this->duration = null;

        return $this;
    }

    /**
     * `'3 hours'`, `'PT3H'` or a CarbonInterval.
     */
    public function duration(CarbonInterval|string $duration): self
    {
        if (Durations::lenient($duration) === null) {
            throw InvalidEventScheduleException::invalidDuration(is_string($duration) ? $duration : $duration->spec());
        }

        $this->duration = $duration;
        $this->endsAt = null;

        return $this;
    }

    /**
     * An all-day event from `$from` through the inclusive `$through` (`Y-m-d`, local days).
     */
    public function allDay(string $from, ?string $through = null): self
    {
        $this->allDayFrom = $from;
        $this->allDayThrough = $through;
        $this->startsAt = null;
        $this->endsAt = null;
        $this->duration = null;

        return $this;
    }

    public function doorsOpenAt(CarbonInterface|string $doorsOpenAt): self
    {
        $this->doorsOpenAt = $doorsOpenAt;

        return $this;
    }

    public function visibility(Visibility $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * Link-only: reached by its uuid, never listed or searched.
     */
    public function unlisted(): self
    {
        return $this->visibility(Visibility::Unlisted);
    }

    /**
     * Invite-only.
     */
    public function private(): self
    {
        return $this->visibility(Visibility::Private);
    }

    public function capacity(?int $capacity): self
    {
        $this->capacity = EventInput::nonNegative($capacity, 'capacity');

        return $this;
    }

    /**
     * Guests an attendee may bring (a ticket type's own party limit replaces it).
     */
    public function maxGuests(int $guests): self
    {
        $this->maxGuests = EventInput::nonNegative($guests, 'max_guests');

        return $this;
    }

    public function waitlist(bool $on = true, bool $autoPromote = true): self
    {
        $this->waitlist = $on;
        $this->autoPromote = $autoPromote;

        return $this;
    }

    /**
     * When RSVPs open and close (closing defaults to the start).
     */
    public function rsvpWindow(CarbonInterface|string|null $opensAt = null, CarbonInterface|string|null $closesAt = null): self
    {
        $this->rsvpOpensAt = $opensAt;
        $this->rsvpClosesAt = $closesAt;

        return $this;
    }

    public function guestList(GuestList $guestList): self
    {
        $this->guestList = $guestList;

        return $this;
    }

    public function showCounts(bool $show = true): self
    {
        $this->showCounts = $show;

        return $this;
    }

    /**
     * Set the poster: an upload, a local file path, or a draft token from an upload-first form.
     */
    public function poster(UploadedFile|string|null $file = null, #[SensitiveParameter] ?string $draft = null): self
    {
        $this->poster = $file;
        $this->posterDraft = $draft;

        return $this;
    }

    /**
     * A ticket type: free without a price; its key derives from the name unless given.
     *
     * @param  array<string, string>|string  $name
     */
    public function tier(array|string $name, ?Money $price = null, ?int $capacity = null, bool $hidden = false, ?string $key = null): self
    {
        $this->tiers[] = new TierData(name: $name, key: $key, price: $price, capacity: $capacity, hidden: $hidden);

        return $this;
    }

    /**
     * Per-event reminder offsets before the start (ISO-8601, e.g. `['P1D', 'PT2H']`).
     *
     * @param  list<string>|null  $offsets
     */
    public function reminders(?array $offsets): self
    {
        $this->reminders = EventInput::reminders($offsets);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Check the policy for `$actor`, who must be allowed to organize as the organizer.
     */
    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function toData(): CreateEventData
    {
        return new CreateEventData(
            title: $this->title,
            organizer: $this->organizer ?? throw InvalidEventException::organizerRequired(),
            schedule: new ScheduleData(
                startsAt: $this->startsAt,
                endsAt: $this->endsAt,
                duration: $this->duration,
                allDayFrom: $this->allDayFrom,
                allDayThrough: $this->allDayThrough,
                doorsOpenAt: $this->doorsOpenAt,
                timezone: $this->timezone,
            ),
            description: $this->description,
            venue: $this->venue,
            onlineUrl: $this->onlineUrl,
            attendanceMode: $this->attendanceMode,
            visibility: $this->visibility,
            capacity: $this->capacity,
            maxGuests: $this->maxGuests,
            waitlist: $this->waitlist,
            waitlistAutoPromote: $this->autoPromote,
            rsvpOpensAt: $this->rsvpOpensAt,
            rsvpClosesAt: $this->rsvpClosesAt,
            guestList: $this->guestList,
            showCounts: $this->showCounts,
            reminders: $this->reminders,
            slug: $this->slug,
            coHosts: $this->coHosts,
            tiers: $this->tiers,
            poster: $this->poster,
            posterDraft: $this->posterDraft,
            meta: $this->meta,
            actor: $this->actor,
        );
    }

    /**
     * Create the event as a draft.
     */
    public function create(): Event
    {
        return $this->manager->create($this->toData());
    }

    /**
     * Create the event and publish it, in one transaction.
     */
    public function publish(): Event
    {
        $data = $this->toData();
        $model = EventModels::event();

        return (new $model)->getConnection()->transaction(function () use ($data): Event {
            $event = $this->manager->create($data);
            $this->manager->for($event)->by($this->actor)->publish();

            return $event;
        });
    }
}
