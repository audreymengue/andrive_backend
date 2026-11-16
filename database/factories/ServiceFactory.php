<?php

namespace Database\Factories;

use App\Models\Direction;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction_id' => Direction::factory(),
            'nom' => fake()->words(3, true),
            'code' => fake()->unique()->bothify('TEST-????-########'),
            'description' => null,
            'statut' => 'ACTIF',
        ];
    }
}
