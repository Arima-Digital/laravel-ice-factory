<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryLoadItem;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryItemEditTest extends TestCase
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

    public function test_a_driver_can_correct_a_delivered_quantity(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.change.delivered_qty_ball.old', 5)
            ->assertJsonPath('data.change.delivered_qty_ball.new', 3);

        $this->assertDatabaseHas('delivery_items', [
            'id' => $itemId,
            'delivered_qty_ball' => 3.00,
        ]);

        // The composition is a running total, so the correction moves it by
        // the difference rather than writing the new figure over the top.
        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 3.00,
        ]);
    }

    public function test_correcting_the_stock_before_leaves_the_composition_unchanged(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'confirmed_stock_before_ball' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.change.confirmed_stock_before_ball.old', 0)
            ->assertJsonPath('data.change.confirmed_stock_before_ball.new', 2);

        $this->assertDatabaseHas('delivery_items', [
            'id' => $itemId,
            'confirmed_stock_before_ball' => 2.00,
            'delivered_qty_ball' => 5.00,
        ]);

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 5.00,
        ]);
    }

    public function test_a_correction_cannot_exceed_the_loaded_quantity(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        DeliveryLoadItem::create([
            'delivery_id' => $delivery->id,
            'product_id' => $ice10->id,
            'qty_ball' => 4,
        ]);

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah kirim melebihi muatan produk ini')
            ->assertJsonPath('loaded_ball', 4)
            ->assertJsonPath('delivered_ball', 5);

        $this->assertDatabaseHas('delivery_items', [
            'id' => $itemId,
            'delivered_qty_ball' => 3.00,
        ]);
    }

    public function test_a_correction_can_reduce_the_composition_to_zero(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 3);

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 0,
            ])
            ->assertOk();

        $this->assertDatabaseHas('freezer_product_compositions', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'qty_ball' => 0.00,
        ]);
    }

    public function test_a_correction_that_would_push_the_composition_negative_is_rejected(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        // An approved sale has already taken the freezer contents down to zero,
        // so cutting this line further would put the running total under zero.
        $saleId = $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/api/delivery-items/{$itemId}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 5,
            ])
            ->assertCreated()
            ->json('data.sale.id');

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/sales/{$saleId}/approve")
            ->assertOk();

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Koreksi akan membuat isi freezer negatif');
    }

    public function test_an_item_cannot_be_corrected_once_the_run_is_completed(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $delivery->update(['status' => 'COMPLETED']);

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonPath('current_status', 'COMPLETED');
    }

    public function test_a_driver_cannot_correct_another_drivers_item(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $otherDriver = User::factory()->driver()->create();

        $this->actingAs($otherDriver, 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 3,
            ])
            ->assertStatus(403);
    }

    public function test_an_admin_can_correct_any_item(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->putJson("/api/delivery-items/{$itemId}", [
                'delivered_qty_ball' => 2,
            ])
            ->assertOk();
    }
}
