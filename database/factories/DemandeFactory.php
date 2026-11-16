<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\TypeSortie;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Demande>
 */
class DemandeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => '01-01-26-'.str_pad((string) fake()->unique()->numberBetween(1, 1000), 4, '0', STR_PAD_LEFT),
            'demandeur_id' => User::factory(),
            'direction_id' => Direction::factory(),
            'type_sortie_id' => TypeSortie::factory(),
            'adresse_depart_id' => EntiteGeographique::factory(),
            'adresse_retour_id' => null,
            'date_depart' => '2026-10-05',
            'heure_depart' => '08:00:00',
            'heure_retour' => '16:30:00',
            'motif' => fake()->sentence(),
            'commentaire' => fake()->optional()->sentence(),
            'statut' => Demande::STATUT_BROUILLON,
        ];
    }
}
