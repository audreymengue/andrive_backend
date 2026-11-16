<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\DemandeEmploye;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeEmploye>
 */
class DemandeEmployeFactory extends Factory
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
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'fonction' => fake()->optional()->jobTitle(),
        ];
    }
}
