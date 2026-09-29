<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $letters = fake()->randomElement(['B', 'D', 'F']) . fake()->numerify('####') . fake()->randomLetter();

        return [
            'code' => 'VH-' . fake()->unique()->numerify('###'),
            'plate_number' => $letters,
            'name' => fake()->randomElement(['Avanza Putih', 'Datsun Hijet', 'L300 Biru', 'Gran Max']),
        ];
    }
}
