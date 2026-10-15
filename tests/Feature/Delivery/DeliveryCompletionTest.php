<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryLoadItem;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing a run: the balance has to hold per product, not just in total.
 *
 * A van carries a mix of products and already reports one combined figure
 * (loaded = delivered + returned). That total can balance while a single
 * product is over- or under-accounted for, so the manifest is the only place
 * that knows how much of each product left the warehouse.
 */
class DeliveryCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function inProgressDelivery(array $loaded): Delivery
    {
        $delivery = Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => User::factory()->driver()->create()->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'initial_qty_loaded_ball' => array_sum(array_column($loaded, 'qty_ball')),
            'status' => 'IN_PROGRESS',
        ]);

        foreach ($loaded as $row) {
            DeliveryLoadItem::create([
                'delivery_id' => $delivery->id,
                'product_id' => $row['product_id'],
                'qty_ball' => $row['qty_ball'],
            ]);
        }

        return $delivery;
    }

    private function dropOff(Delivery $delivery, Product $product, float $qty): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);

        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'store_id' => $store->id,
            'freezer_id' => $freezer->id,
            'product_id' => $product->id,
            'confirmed_stock_before_ball' => 0,
            'delivered_qty_ball' => $qty,
            'visited_at' => now(),
        ]);
    }

    private function complete(int $deliveryId, array $payload)
    {
        return $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/complete", $payload);
    }

    private function big(): Product
    {
        return Product::factory()->ice10()->create();
    }

    private function small(): Product
    {
        return Product::factory()->create([
            'code' => 'ICE-5',
            'name' => 'Es Kristal 5 KG',
            'weight_kg' => 5,
            'selling_price' => 5000,
        ]);
    }

    public function test_a_multi_product_run_needs_the_return_broken_down_per_product(): void
    {
        $delivery = $this->inProgressDelivery([
            ['product_id' => $this->big()->id, 'qty_ball' => 7],
            ['product_id' => $this->small()->id, 'qty_ball' => 3],
        ]);

        $this->complete($delivery->id, ['total_qty_returned_ball' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Rincian barang sisa per produk wajib diisi');

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'IN_PROGRESS',
        ]);
    }

    public function test_a_total_that_balances_but_mismatches_per_product_is_rejected(): void
    {
        $big = $this->big();
        $small = $this->small();

        $delivery = $this->inProgressDelivery([
            ['product_id' => $big->id, 'qty_ball' => 7],
            ['product_id' => $small->id, 'qty_ball' => 3],
        ]);

        $this->dropOff($delivery, $big, 3);
        $this->dropOff($delivery, $small, 3);

        // Total: 10 = 6 + 4 balances, but all 4 returns are claimed on $small,
        // leaving $big owing 4 with nothing to answer for it.
        $this->complete($delivery->id, [
            'total_qty_returned_ball' => 4,
            'returned_items' => [
                ['product_id' => $big->id, 'qty_returned_ball' => 0],
                ['product_id' => $small->id, 'qty_returned_ball' => 4],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Saldo pengiriman tidak sesuai per produk')
            ->assertJsonPath('by_product.1.product_id', $small->id)
            ->assertJsonPath('by_product.1.balanced', false);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'status' => 'IN_PROGRESS',
        ]);
    }

    public function test_a_balanced_multi_product_run_completes_and_records_each_return(): void
    {
        $big = $this->big();
        $small = $this->small();

        $delivery = $this->inProgressDelivery([
            ['product_id' => $big->id, 'qty_ball' => 7],
            ['product_id' => $small->id, 'qty_ball' => 3],
        ]);

        $this->dropOff($delivery, $big, 3);
        $this->dropOff($delivery, $small, 3);

        $this->complete($delivery->id, [
            'total_qty_returned_ball' => 4,
            'returned_items' => [
                ['product_id' => $big->id, 'qty_returned_ball' => 4],
                ['product_id' => $small->id, 'qty_returned_ball' => 0],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('summary.balance_verified', true);

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $delivery->id,
            'product_id' => $big->id,
            'qty_returned_ball' => 4.00,
        ]);
        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $delivery->id,
            'product_id' => $small->id,
            'qty_returned_ball' => 0.00,
        ]);
    }

    public function test_a_single_product_run_takes_the_total_as_its_return(): void
    {
        $big = $this->big();

        $delivery = $this->inProgressDelivery([
            ['product_id' => $big->id, 'qty_ball' => 10],
        ]);

        $this->dropOff($delivery, $big, 6);

        $this->complete($delivery->id, ['total_qty_returned_ball' => 4])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $delivery->id,
            'product_id' => $big->id,
            'qty_returned_ball' => 4.00,
        ]);
    }

    public function test_the_return_breakdown_must_sum_to_the_total(): void
    {
        $big = $this->big();
        $small = $this->small();

        $delivery = $this->inProgressDelivery([
            ['product_id' => $big->id, 'qty_ball' => 7],
            ['product_id' => $small->id, 'qty_ball' => 3],
        ]);

        $this->complete($delivery->id, [
            'total_qty_returned_ball' => 4,
            'returned_items' => [
                ['product_id' => $big->id, 'qty_returned_ball' => 4],
                ['product_id' => $small->id, 'qty_returned_ball' => 2],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Total barang sisa tidak sesuai dengan rincian per produk');
    }

    public function test_a_product_delivered_but_never_loaded_breaks_the_balance(): void
    {
        $big = $this->big();
        $extra = $this->small();

        $delivery = $this->inProgressDelivery([
            ['product_id' => $big->id, 'qty_ball' => 7],
        ]);

        $this->dropOff($delivery, $big, 3);
        $this->dropOff($delivery, $extra, 4);

        // The $extra product was never on the manifest, so more was delivered
        // than the van ever carried.
        $this->complete($delivery->id, [
            'total_qty_returned_ball' => 4,
            'returned_items' => [
                ['product_id' => $big->id, 'qty_returned_ball' => 4],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Saldo pengiriman tidak sesuai per produk');
    }
}
