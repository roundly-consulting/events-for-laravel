<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;

/**
 * @extends Factory<Guest>
 */
final class GuestFactory extends Factory
{
    /** @var class-string<Guest> */
    protected $model = Guest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
