<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventStates;

/**
 * A draft event two weeks from now at a new venue, organized by a new model of the first class
 * in `events.organizers` that has a factory (`->organizedBy($user)` to choose). Status states
 * (`published()`, `cancelled()`, …) are written on insert: lifecycle keeps a declared state and
 * arms its expiry, so a published factory event still completes at `ends_at`.
 *
 * @extends Factory<Event>
 */
final class EventFactory extends Factory
{
    /** @var class-string<Event> */
    protected $model = Event::class;

    /**
     * The configured event class, so a host subclass comes out of the factory.
     *
     * @return class-string<Event>
     */
    public function modelName(): string
    {
        return EventModels::event();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = Clock::now()->addDays(14)->setTime(18, 0);

        return [
            'title' => ['en' => rtrim($this->faker->sentence(3), '.')],
            'description' => ['en' => $this->faker->paragraph()],
            ...$this->defaultOrganizer(),
            'venue_id' => EventModels::venue()::factory(),
            'visibility' => Visibility::Public,
            'attendance_mode' => AttendanceMode::Offline,
            'timezone' => 'Europe/Bratislava',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'all_day' => false,
            'max_guests' => 0,
            'waitlist' => true,
            'waitlist_auto_promote' => true,
            'guest_list' => GuestList::Hidden,
            'show_counts' => true,
        ];
    }

    public function organizedBy(Model $organizer): self
    {
        return $this->state([
            'organizer_type' => $organizer->getMorphClass(),
            'organizer_id' => $organizer->getKey(),
        ]);
    }

    public function at(Venue $venue): self
    {
        return $this->state([
            'venue_id' => $venue->getKey(),
            'timezone' => $venue->timezone,
            'attendance_mode' => AttendanceMode::Offline,
        ]);
    }

    /**
     * Online only: no venue, a join link.
     */
    public function online(string $url = 'https://meet.example.com/join'): self
    {
        return $this->state([
            'venue_id' => null,
            'online_url' => $url,
            'attendance_mode' => AttendanceMode::Online,
        ]);
    }

    /**
     * Starting at `$start` (UTC unless it carries a zone), ending `$hours` later.
     */
    public function startingAt(CarbonImmutable $start, int $hours = 2): self
    {
        return $this->state([
            'starts_at' => $start,
            'ends_at' => $start->addHours($hours),
        ]);
    }

    public function capacity(?int $capacity): self
    {
        return $this->state(['capacity' => $capacity]);
    }

    public function maxGuests(int $guests): self
    {
        return $this->state(['max_guests' => $guests]);
    }

    public function unlisted(): self
    {
        return $this->state(['visibility' => Visibility::Unlisted]);
    }

    public function private(): self
    {
        return $this->state(['visibility' => Visibility::Private]);
    }

    public function withoutWaitlist(): self
    {
        return $this->state(['waitlist' => false]);
    }

    public function published(): self
    {
        return $this->inPhase(EventPhase::Scheduled, 'published_at');
    }

    public function postponed(): self
    {
        return $this->inPhase(EventPhase::Postponed, 'postponed_at');
    }

    public function rescheduled(): self
    {
        return $this->inPhase(EventPhase::Rescheduled, 'rescheduled_at');
    }

    public function cancelled(): self
    {
        return $this->inPhase(EventPhase::Cancelled, 'cancelled_at');
    }

    public function completed(): self
    {
        return $this->inPhase(EventPhase::Completed, 'completed_at');
    }

    /**
     * Created directly in the first state of `$phase` of the configured lifecycle, with its stamp.
     */
    public function inPhase(EventPhase $phase, ?string $stamp = null): self
    {
        return $this->state(function () use ($phase, $stamp): array {
            $value = EventStates::valuesIn($phase)[0] ?? throw new LogicException('The configured event lifecycle has no state in the '.$phase->value.' phase.');

            return array_filter([
                'status' => $value,
                $stamp ?? '' => $stamp === null ? null : Clock::now(),
                'published_at' => $phase === EventPhase::Draft ? null : Clock::now(),
            ], static fn (mixed $value, string|int $key): bool => $key !== '' && $value !== null, ARRAY_FILTER_USE_BOTH);
        });
    }

    /**
     * In this category (a live category's slug or name). Tagged after creating, through the
     * tags trait verbs — the raw `tags()` relation refuses writes.
     */
    public function categorized(string $category): self
    {
        return $this->afterCreating(static function (Event $event) use ($category): void {
            $event->syncTags($category, EventTagType::Category);
        });
    }

    /**
     * With these labels (created when new), tagged after creating.
     *
     * @param  list<string>  $labels
     */
    public function labelled(array $labels): self
    {
        return $this->afterCreating(static function (Event $event) use ($labels): void {
            $event->attachTags($labels, EventTagType::Label);
        });
    }

    /**
     * @return array{organizer_type: string, organizer_id: Factory<Model>}
     */
    private function defaultOrganizer(): array
    {
        $organizers = config('events.organizers');

        foreach (is_array($organizers) ? $organizers : [] as $class) {
            if (is_string($class) && is_subclass_of($class, Model::class) && method_exists($class, 'factory')) {
                /** @var Factory<Model> $factory */
                $factory = $class::factory();

                return ['organizer_type' => (new $class)->getMorphClass(), 'organizer_id' => $factory];
            }
        }

        throw new LogicException('No organizer: give the factory one with ->organizedBy($model), or list a model with a factory in events.organizers.');
    }
}
