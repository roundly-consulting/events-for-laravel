<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * An `interested` RSVP — it takes no seat, so a factory row never puts an event's counters out
 * of step. Seat-taking answers go through `Events::for($event)->rsvp($attendee)->going()`.
 *
 * @extends Factory<Rsvp>
 */
final class RsvpFactory extends Factory
{
    /** @var class-string<Rsvp> */
    protected $model = Rsvp::class;

    /**
     * @return class-string<Rsvp>
     */
    public function modelName(): string
    {
        return EventModels::rsvp();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => EventModels::event()::factory(),
            ...$this->defaultAttendee(),
            'status' => RsvpStatus::Interested,
            'guests' => 0,
            'responded_at' => Clock::now(),
        ];
    }

    public function by(Model $attendee): self
    {
        return $this->state([
            'attendee_type' => $attendee->getMorphClass(),
            'attendee_id' => $attendee->getKey(),
        ]);
    }

    public function status(RsvpStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    /**
     * @return array{attendee_type: string, attendee_id: Factory<Model>}
     */
    private function defaultAttendee(): array
    {
        $attendees = config('events.attendees');

        foreach (is_array($attendees) ? $attendees : [] as $class) {
            if (is_string($class) && is_subclass_of($class, Model::class) && method_exists($class, 'factory')) {
                /** @var Factory<Model> $factory */
                $factory = $class::factory();

                return ['attendee_type' => (new $class)->getMorphClass(), 'attendee_id' => $factory];
            }
        }

        throw new LogicException('No attendee: give the factory one with ->by($model), or list a model with a factory in events.attendees.');
    }
}
