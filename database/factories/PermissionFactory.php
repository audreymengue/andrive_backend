<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => str_replace('-', '_', fake()->unique()->slug(2)),
            'guard_name' => 'web',
            'libelle' => fake()->sentence(3),
            'description' => fake()->sentence(),
        ];
    }
}
