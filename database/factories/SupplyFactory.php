<?php

namespace Database\Factories;

use App\Models\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'category' => 'Material',
            'unit' => 'unidad',
            'kind' => 'stock',
            'unit_cost' => 1000,
            'min_stock' => 0,
            'active' => true,
            'is_legacy' => false,
        ];
    }
}
