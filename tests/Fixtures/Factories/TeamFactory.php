<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;

/**
 * @extends Factory<Team>
 */
final class TeamFactory extends Factory
{
    /** @var class-string<Team> */
    protected $model = Team::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
        ];
    }
}
