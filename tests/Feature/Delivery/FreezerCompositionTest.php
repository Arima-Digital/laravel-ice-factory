<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreezerCompositionTest extends TestCase
{
    use RefreshDatabase;

    private ?User $driver = null;

    private function driver(): User
    {
        return $this->driver ??= User::factory()->driver()->create();
    }

    private function inProgressDelivery(): Delivery
    {
        return Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => $this->driver()->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'initial_qty_loaded_ball' => 100,
            'status' => 'IN_PROGRESS',
        ]);
    }

    private function confirmProduct(
        Delivery $delivery,
        Store $store,
        Freezer $freezer,
        Product $product,
        float $delivered,
    ): int {
        return $this->actingAs($this->driver(), 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $delivery->id,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => $product->id,
                'confirmed_stock_before_ball' => 0,
                'delivered_qty_ball' => $delivered,
            ])
            ->assertCreated()
            ->json('data.delivery_item.id');
    }

    private function recordSale(int $deliveryItemId, Product $product, float $qty): int
    {
        return $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/api/delivery-items/{$deliveryItemId}/sales", [
                'product_id' => $product->id,
                'qty_ball' => $qty,
            ])
            ->assertCreated()
            ->json('data.sale.id');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_confirming_a_product_records_it_in_the_freezer_composition(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 3.00,
        ]);
    }

    public function test_a_freezer_composition_holds_each_product_separately(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);
        $this->confirmProduct($delivery, $store, $freezer, $ice15, 2);

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 3.00,
        ]);
        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice15->id,
            'qty_ball' => 2.00,
        ]);
    }

    public function test_the_composition_endpoint_returns_the_products_in_the_freezer(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);
        $this->confirmProduct($delivery, $store, $freezer, $ice15, 2);

        $this->actingAs($this->driver(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}/compositions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.product_id', $ice10->id)
            ->assertJsonPath('data.0.qty_ball', '3.00')
            ->assertJsonPath('data.1.product_id', $ice15->id)
            ->assertJsonPath('data.1.qty_ball', '2.00');
    }

    public function test_the_composition_endpoint_404s_for_an_unknown_freezer(): void
    {
        $this->actingAs($this->driver(), 'sanctum')
            ->getJson('/api/freezers/99999/compositions')
            ->assertStatus(404);
    }

    public function test_the_delivery_detail_reports_live_delivered_and_remaining(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);
        $this->confirmProduct($delivery, $store, $freezer, $ice15, 2);

        $this->actingAs($this->driver(), 'sanctum')
            ->getJson("/api/deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.total_qty_delivered_ball', '5.00')
            ->assertJsonPath('totals.total_qty_delivered_ball', 5)
            ->assertJsonPath('totals.remaining_ball', 95)
            ->assertJsonPath('totals.items_count', 2);
    }

    public function test_approving_a_sale_reduces_the_freezer_composition(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);
        $saleId = $this->recordSale($itemId, $ice10, 2);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/sales/{$saleId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 3.00,
        ]);
    }

    public function test_rejecting_a_sale_leaves_the_freezer_composition_unchanged(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);
        $saleId = $this->recordSale($itemId, $ice10, 2);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/sales/{$saleId}/reject")
            ->assertOk();

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 5.00,
        ]);
    }

    public function test_the_composition_never_goes_negative(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 1);
        $saleId = $this->recordSale($itemId, $ice10, 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/sales/{$saleId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 0.00,
        ]);
    }
}
