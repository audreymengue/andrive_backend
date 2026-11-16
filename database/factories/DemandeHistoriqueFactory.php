<?php

namespace Database\Factories;

use App\Models\Demande;
use App\Models\DemandeHistorique;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeHistorique>
 */
class DemandeHistoriqueFactory extends Factory
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
            'ancien_statut' => Demande::STATUT_BROUILLON,
            'nouveau_statut' => Demande::STATUT_EN_ATTENTE,
            'utilisateur_id' => User::factory(),
            'commentaire' => fake()->optional()->sentence(),
        ];
    }
}
