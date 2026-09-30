<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Production;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Production>
 */
class ProductionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'production_date' => now()->toDateString(),
            'qty_produced_ball' => 100,
            'qty_reject_ball' => 0,
            'qty_good_ball' => 100,
            'created_by' => User::factory(),
            'status' => 'DRAFT',
        ];
    }

    /**
     * Finalised, and therefore shippable stock. DRAFT batches are recorded but
     * not available to load.
     */
    public function posted(): static
    {
        return $this->state(fn () => ['status' => 'POSTED']);
    }

    /**
     * Not attributed to any warehouse, the way batches created before
     * warehouse_id existed are.
     */
    public function unassigned(): static
    {
        return $this->state(fn () => ['warehouse_id' => null]);
    }

    /**
     * A batch that yielded $good usable balls out of $good + $reject produced.
     */
    public function yielding(float $good, float $reject = 0): static
    {
        return $this->state(fn () => [
            'qty_good_ball' => $good,
            'qty_produced_ball' => $good + $reject,
            'qty_reject_ball' => $reject,
        ]);
    }
}
