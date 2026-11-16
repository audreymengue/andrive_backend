<?php

namespace Database\Factories;

use App\Models\Direction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Direction>
 */
class DirectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->company(),
            'code' => fake()->unique()->bothify('DIR-###'),
            'description' => fake()->sentence(),
            'statut' => 'ACTIF',
        ];
    }
}
