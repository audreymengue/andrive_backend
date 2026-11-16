<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\DemandeModification;
use App\Models\Direction;
use App\Models\EntiteGeographique;
use App\Models\TypeSortie;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeModification>
 */
class DemandeModificationFactory extends Factory
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
            'demandeur_id' => User::factory(),
            'direction_id' => Direction::factory(),
            'type_sortie_id' => TypeSortie::factory(),
            'adresse_depart_id' => EntiteGeographique::factory(),
            'date_depart' => '2026-10-05',
            'heure_depart' => '08:00:00',
            'heure_retour' => '16:30:00',
            'motif' => fake()->sentence(),
            'commentaire' => fake()->optional()->sentence(),
            'destinations' => fn (): array => [['entite_geographique_id' => EntiteGeographique::factory()->create()->id, 'ordre' => 1]],
            'employes' => [['nom' => fake()->lastName(), 'prenom' => fake()->firstName(), 'fonction' => null]],
            'objets' => [],
            'submitted_at' => null,
        ];
    }
}
