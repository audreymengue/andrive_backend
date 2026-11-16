<?php

namespace Database\Factories;

use App\Models\TypeSortie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeSortie>
 */
class TypeSortieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'libelle' => 'Type de test '.fake()->word(),
            'code' => fake()->unique()->bothify('TEST-????-####'),
        ];
    }
}
