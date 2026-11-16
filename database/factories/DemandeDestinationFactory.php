<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\DemandeDestination;
use App\Models\EntiteGeographique;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeDestination>
 */
class DemandeDestinationFactory extends Factory
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
            'entite_geographique_id' => EntiteGeographique::factory(),
            'ordre' => 1,
        ];
    }
}
