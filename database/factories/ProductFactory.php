<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'ICE-' . fake()->unique()->numerify('##'),
            'name' => 'Es Kristal ' . fake()->numberBetween(5, 25) . ' KG',
            'weight_kg' => fake()->numberBetween(5, 25),
            'selling_price' => fake()->numberBetween(8000, 25000),
        ];
    }

    /**
     * The single ice product used across the MVP.
     */
    public function ice10(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'ICE-10',
            'name' => 'Es Kristal 10 KG',
            'weight_kg' => 10,
            'selling_price' => 10000,
        ]);
    }
}
