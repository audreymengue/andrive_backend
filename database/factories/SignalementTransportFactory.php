<?php

namespace Database\Factories;

use App\Models\Chauffeur;
use App\Models\SignalementTransport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SignalementTransport> */
class SignalementTransportFactory extends Factory
{
    protected $model = SignalementTransport::class;

    public function definition(): array
    {
        return [
            'reported_by_id' => User::factory(),
            'chauffeur_id' => Chauffeur::factory(),
            'vehicule_id' => null,
            'course_id' => null,
            'type' => fake()->randomElement(['RETARD', 'PANNE', 'ABSENCE', 'IMPOSSIBILITE', 'INDISPONIBILITE']),
            'details' => fake()->sentence(),
            'signale_a' => now(),
        ];
    }
}
