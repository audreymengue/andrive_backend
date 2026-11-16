<?php

namespace Database\Factories;

use App\Models\DemandeReferenceSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandeReferenceSequence>
 */
class DemandeReferenceSequenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_date' => '2026-09-23',
            'last_sequence' => 0,
        ];
    }
}
