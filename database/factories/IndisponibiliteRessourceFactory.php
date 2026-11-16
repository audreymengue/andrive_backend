<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\IndisponibiliteRessource;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IndisponibiliteRessource> */
class IndisponibiliteRessourceFactory extends Factory
{
    protected $model = IndisponibiliteRessource::class;

    public function definition(): array
    {
        $isVehicle = fake()->boolean();

        return [
            'vehicule_id' => $isVehicle ? Vehicule::factory() : null,
            'chauffeur_id' => $isVehicle ? null : Chauffeur::factory(),
            'created_by_id' => User::factory(),
            'debut_a' => now()->addDay(),
            'fin_estimee_a' => now()->addDays(2),
            'fin_effective_a' => null,
            'motif' => fake()->sentence(),
            'lock_version' => 0,
        ];
    }
}
