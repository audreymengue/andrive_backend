<?php

namespace Database\Factories;

use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vehicule>
 */
class VehiculeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'immatriculation' => 'TEST-'.Str::upper(Str::random(8)),
            'places_utiles' => fake()->numberBetween(1, 20),
            'statut' => 'ACTIF',
            'lock_version' => 0,
        ];
    }
}
