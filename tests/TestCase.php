<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A valid loaded_items array whose quantities sum to the given total.
     *
     * A delivery plan has to name the products it carries, and their total has
     * to agree with initial_qty_loaded_ball. Tests that only care about the
     * route, the stock or the stops still have to send a load, so this builds
     * one product carrying the whole quantity.
     *
     * @return array<int, array{product_id: int, qty_ball: float|int}>
     */
    protected function loadedItems(float $qty, ?int $productId = null): array
    {
        return [[
            'product_id' => $productId ?? Product::factory()->create()->id,
            'qty_ball' => $qty,
        ]];
    }
}
