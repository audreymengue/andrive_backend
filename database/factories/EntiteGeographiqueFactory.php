<?php

namespace Database\Factories;

use App\Models\EntiteGeographique;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntiteGeographique>
 */
class EntiteGeographiqueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adresse' => fake()->address(),
            'latitude' => null,
            'longitude' => null,
            'description' => null,
        ];
    }
}
