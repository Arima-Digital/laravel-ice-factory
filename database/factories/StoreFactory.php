<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'RSA-' . fake()->unique()->numerify('###'),
            'name' => 'Toko ' . fake()->lastName(),
            'owner_name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(-6.9, -6.1),
            'longitude' => fake()->longitude(106.6, 107.1),
        ];
    }

    /**
     * Store without coordinates, for testing the map feature being optional.
     */
    public function withoutLocation(): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => null,
            'longitude' => null,
        ]);
    }
}
