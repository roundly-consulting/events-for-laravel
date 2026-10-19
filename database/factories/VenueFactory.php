<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * A venue in Bratislava with coordinates and its timezone.
 *
 * @extends Factory<Venue>
 */
final class VenueFactory extends Factory
{
    /** @var class-string<Venue> */
    protected $model = Venue::class;

    /**
     * @return class-string<Venue>
     */
    public function modelName(): string
    {
        return EventModels::venue();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => ['en' => $this->faker->unique()->company()],
            'street' => $this->faker->streetAddress(),
            'city' => 'Bratislava',
            'postal_code' => '811 01',
            'country_code' => 'SK',
            'latitude' => $this->faker->randomFloat(7, 48.10, 48.20),
            'longitude' => $this->faker->randomFloat(7, 17.05, 17.20),
            'timezone' => 'Europe/Bratislava',
        ];
    }

    /**
     * Without coordinates (not geocoded yet).
     */
    public function withoutCoordinates(): self
    {
        return $this->state(['latitude' => null, 'longitude' => null]);
    }

    public function at(float $latitude, float $longitude): self
    {
        return $this->state(['latitude' => $latitude, 'longitude' => $longitude]);
    }

    public function ownedBy(Model $owner): self
    {
        return $this->state(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }
}
