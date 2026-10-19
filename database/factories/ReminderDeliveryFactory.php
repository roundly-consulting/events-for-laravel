<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Events\Models\ReminderDelivery;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * A claimed `P1D` reminder.
 *
 * @extends Factory<ReminderDelivery>
 */
final class ReminderDeliveryFactory extends Factory
{
    /** @var class-string<ReminderDelivery> */
    protected $model = ReminderDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => EventModels::event()::factory(),
            'offset' => 'P1D',
            'starts_at' => Clock::now()->addDay(),
            'dispatched_at' => Clock::now(),
            'recipients' => 0,
        ];
    }
}
