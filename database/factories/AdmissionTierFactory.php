<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Money\Money;

/**
 * A free ticket type without its own capacity.
 *
 * @extends Factory<AdmissionTier>
 */
final class AdmissionTierFactory extends Factory
{
    /** @var class-string<AdmissionTier> */
    protected $model = AdmissionTier::class;

    /**
     * @return class-string<AdmissionTier>
     */
    public function modelName(): string
    {
        return EventModels::tier();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = $this->faker->unique()->slug(2);

        return [
            'event_id' => EventModels::event()::factory(),
            'key' => substr($key, 0, 64),
            'name' => ['en' => ucfirst(str_replace('-', ' ', $key))],
            'min_party' => 1,
        ];
    }

    public function key(string $key): self
    {
        return $this->state(['key' => $key]);
    }

    public function price(Money $price): self
    {
        return $this->state(['price' => $price]);
    }

    public function capacity(?int $capacity): self
    {
        return $this->state(['capacity' => $capacity]);
    }

    public function hidden(): self
    {
        return $this->state(['hidden' => true]);
    }
}
