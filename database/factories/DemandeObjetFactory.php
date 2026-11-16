<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\DemandeObjet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeObjet>
 */
class DemandeObjetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'demande_id' => Demande::factory(),
            'designation' => fake()->words(3, true),
            'quantite' => fake()->numberBetween(1, 10),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
