<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A new event — what `Events::make()` builds and `Events::create()` takes. Every null setting
 * takes its `events.defaults.*` value. The event is created as a draft.
 */
final readonly class CreateEventData
{
    /**
     * @param  array<string, string>|string  $title  a locale map, or a string in the current locale
     * @param  array<string, string>|string|null  $description
     * @param  array<string, string>|null  $slug  manual per-locale slugs (strict: a taken slug throws)
     * @param  list<string>|null  $reminders  ISO-8601 offsets before the start (null = config)
     * @param  list<Model>  $coHosts
     * @param  list<TierData>  $tiers
     * @param  Tag|int|string|null  $category  a curated category's slug or name, a tag key or a tag
     * @param  list<mixed>  $labels  label names (created when new), slugs, keys or tags
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array|string $title,
        public Model $organizer,
        public ScheduleData $schedule,
        public array|string|null $description = null,
        public Venue|int|null $venue = null,
        public ?string $onlineUrl = null,
        public ?AttendanceMode $attendanceMode = null,
        public ?Visibility $visibility = null,
        public ?int $capacity = null,
        public ?int $maxGuests = null,
        public ?bool $waitlist = null,
        public ?bool $waitlistAutoPromote = null,
        public CarbonInterface|string|null $rsvpOpensAt = null,
        public CarbonInterface|string|null $rsvpClosesAt = null,
        public ?GuestList $guestList = null,
        public ?bool $showCounts = null,
        public ?array $reminders = null,
        public ?array $slug = null,
        public array $coHosts = [],
        public array $tiers = [],
        public UploadedFile|string|null $poster = null,
        public ?string $posterDraft = null,
        public Tag|int|string|null $category = null,
        public array $labels = [],
        public array $meta = [],
        public ?Model $actor = null,
    ) {}
}
