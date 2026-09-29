<?php

namespace Database\Factories;

use App\Models\Freezer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Freezer>
 */
class FreezerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'code' => 'FRZ-' . fake()->unique()->numerify('#####'),
            'sim_number' => fake()->unique()->numerify('8990###########'),
            'max_capacity_ball' => fake()->numberBetween(5, 15),
            'tare_weight_kg' => 45,
        ];
    }

    /**
     * Freezer whose IoT sensor has never reported, so stock is unknown.
     */
    public function withoutSensorData(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_weight_kg' => null,
            'last_temperature_c' => null,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => null,
        ]);
    }

    /**
     * Freezer with the latest IoT readings, ready to be estimated.
     */
    public function withReadings(float $weightKg, float $temperatureC = -18.0, string $doorStatus = 'CLOSED'): static
    {
        return $this->state(fn (array $attributes) => [
            'last_weight_kg' => $weightKg,
            'last_temperature_c' => $temperatureC,
            'last_door_status' => $doorStatus,
            'last_seen_at' => now()->subMinutes(5),
        ]);
    }
}
