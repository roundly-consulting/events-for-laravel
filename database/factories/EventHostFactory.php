<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * A co-host row; name the host with `->host($model)`.
 *
 * @extends Factory<EventHost>
 */
final class EventHostFactory extends Factory
{
    /** @var class-string<EventHost> */
    protected $model = EventHost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => EventModels::event()::factory(),
        ];
    }

    public function host(Model $host): self
    {
        return $this->state(['host_type' => $host->getMorphClass(), 'host_id' => $host->getKey()]);
    }
}
