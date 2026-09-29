<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The roles allowed by the users table.
     */
    private const ROLES = ['ADMIN', 'WAREHOUSE', 'DRIVER'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$password ??= Hash::make('password'),
            'role' => fake()->randomElement(self::ROLES),
        ];
    }

    /**
     * Administrator with full access.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'ADMIN',
        ]);
    }

    /**
     * Warehouse staff, allowed to read master data and create products.
     */
    public function warehouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'WAREHOUSE',
        ]);
    }

    /**
     * Driver, only allowed to read stores and store freezer lists.
     */
    public function driver(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'DRIVER',
        ]);
    }
}
