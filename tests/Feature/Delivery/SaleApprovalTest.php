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

class SaleApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function driver(): User
    {
        return User::factory()->driver()->create();
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
        float $delivered = 5.0,
        float $confirmedBefore = 1,
    ): int {
        return $this->actingAs($this->driver(), 'sanctum')
            ->postJson('/admin/delivery-items/confirm', [
                'delivery_id' => $delivery->id,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => $product->id,
                'confirmed_stock_before_ball' => $confirmedBefore,
                'delivered_qty_ball' => $delivered,
            ])
            ->assertCreated()
            ->json('data.delivery_item.id');
    }

    public function test_driver_can_deliver_two_products_to_the_same_freezer(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);
        $this->confirmProduct($delivery, $store, $freezer, $ice15, 3);

        $this->assertDatabaseHas('delivery_items', [
            'delivery_id' => $delivery->id,
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
        ]);
        $this->assertDatabaseHas('delivery_items', [
            'delivery_id' => $delivery->id,
            'freezer_id' => $freezer->id,
            'product_id' => $ice15->id,
        ]);
    }

    public function test_the_same_product_cannot_be_delivered_twice_to_one_freezer_in_one_visit(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $this->confirmProduct($delivery, $store, $freezer, $ice10);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson('/admin/delivery-items/confirm', [
                'delivery_id' => $delivery->id,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => $ice10->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This product already confirmed for this freezer in this delivery');
    }

    public function test_confirming_a_freezer_does_not_create_a_sale_on_its_own(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_driver_records_a_sale_and_it_waits_for_approval(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10);

        $response = $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 2,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.sale.status', 'PENDING')
            // The price comes from the product, not from the request, so the
            // same product cannot be sold at two different prices.
            ->assertJsonPath('data.sale.unit_price', '10000.00')
            ->assertJsonPath('data.total_amount', 20000);

        $this->assertDatabaseHas('sales', [
            'product_id' => $ice10->id,
            'status' => 'PENDING',
            'qty_ball' => 2,
            'total_amount' => 20000,
        ]);
    }

    public function test_each_product_is_priced_with_its_own_price(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $item10Id = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);
        $item15Id = $this->confirmProduct($delivery, $store, $freezer, $ice15, 3);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$item10Id}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 10000);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$item15Id}/sales", [
                'product_id' => $ice15->id,
                'qty_ball' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 30000);
    }

    public function test_a_product_that_was_not_delivered_to_this_freezer_cannot_be_sold(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $other = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15]);
        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $other->id,
                'qty_ball' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Product was not delivered to this freezer in this delivery');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_admin_approves_a_pending_sale(): void
    {
        $saleId = $this->recordSale();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'CONFIRMED']);
    }

    public function test_admin_rejects_a_pending_sale_and_the_row_is_kept(): void
    {
        $saleId = $this->recordSale();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'VOID');

        // Kept rather than deleted so the driver can see what was refused.
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'VOID']);
    }

    public function test_a_sale_cannot_be_reviewed_twice(): void
    {
        $saleId = $this->recordSale();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only PENDING sales can be reviewed');
    }

    public function test_a_driver_cannot_approve_their_own_sale(): void
    {
        $saleId = $this->recordSale();

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertStatus(403);

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'PENDING']);
    }

    public function test_sales_list_separates_pending_from_confirmed(): void
    {
        $ice10 = Product::factory()->ice10()->create();

        $confirmedId = $this->recordSale(product: $ice10);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/admin/sales/{$confirmedId}/approve")->assertOk();
        $this->recordSale(product: $ice10);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/admin/sales')
            ->assertOk()
            ->assertJsonPath('summary.CONFIRMED.amount', 20000)
            ->assertJsonPath('summary.PENDING.amount', 20000)
            // The headline total is revenue, so it must not include a sale
            // nobody has reviewed yet.
            ->assertJsonPath('total_amount', 20000);
    }

    public function test_a_pending_sale_is_not_part_of_what_a_store_owes(): void
    {
        $store = Store::factory()->create();

        $this->recordSale($store, approved: false);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/admin/stores/{$store->id}/settlement")
            ->assertOk()
            ->assertJsonPath('data.total_sales', 0)
            ->assertJsonPath('data.outstanding', 0)
            ->assertJsonPath('data.status', 'SETTLED')
            // Reported so the admin can see what is waiting, but not billed.
            ->assertJsonPath('data.pending_sales', 20000);
    }

    public function test_an_approved_sale_becomes_outstanding(): void
    {
        $store = Store::factory()->create();

        $this->recordSale($store, approved: true);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/admin/stores/{$store->id}/settlement")
            ->assertOk()
            ->assertJsonPath('data.total_sales', 20000)
            ->assertJsonPath('data.outstanding', 20000)
            ->assertJsonPath('data.status', 'DEBT');
    }

    public function test_a_rejected_sale_is_not_part_of_what_a_store_owes(): void
    {
        $store = Store::factory()->create();
        $saleId = $this->recordSale($store, approved: false);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/reject")
            ->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/admin/stores/{$store->id}/settlement")
            ->assertOk()
            ->assertJsonPath('data.total_sales', 0)
            ->assertJsonPath('data.outstanding', 0);
    }

    public function test_sales_for_a_store_can_be_filtered_by_product(): void
    {
        $store = Store::factory()->create();
        $delivery = $this->inProgressDelivery();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $item10Id = $this->confirmProduct($delivery, $store, $freezer, $ice10, 5);
        $item15Id = $this->confirmProduct($delivery, $store, $freezer, $ice15, 3);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$item10Id}/sales", ['product_id' => $ice10->id, 'qty_ball' => 1])
            ->assertCreated();
        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$item15Id}/sales", ['product_id' => $ice15->id, 'qty_ball' => 1])
            ->assertCreated();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/admin/sales?product_id={$ice15->id}")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.product_id', $ice15->id);
    }

    public function test_the_stock_check_only_counts_what_happened_since_the_last_visit(): void
    {
        $store = Store::factory()->create();
        // tare 45 kg + 8 ball of 10 kg = 125 kg, so the sensor reports 8 ball.
        $freezer = Freezer::factory()->withReadings(125)->create([
            'store_id' => $store->id,
            'max_capacity_ball' => 20,
        ]);
        $ice10 = Product::factory()->ice10()->create();

        // First visit: 1 ball before, 5 ball delivered.
        $this->confirmProduct($this->inProgressDelivery(), $store, $freezer, $ice10, 5, 1);

        // Second visit: 6 ball before, 2 ball delivered. The hand-confirmed 6
        // is the starting point for the comparison, so the 5 balls from the
        // first visit must not be added to it again.
        $itemId = $this->confirmProduct($this->inProgressDelivery(), $store, $freezer, $ice10, 2, 6);

        $this->assertEqualsWithDelta(
            8,
            $this->stockCheck($itemId)['expected_stock_ball'],
            0.01,
        );
    }

    public function test_the_stock_check_reports_a_drift_when_the_sensor_and_the_driver_disagree(): void
    {
        $store = Store::factory()->create();
        // tare 45 kg + 5 ball of 10 kg = 95 kg.
        $freezer = Freezer::factory()->withReadings(95)->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        // Driver says 2 before and 3 delivered, so 5 expected, sensor says 5.
        $itemId = $this->confirmProduct($this->inProgressDelivery(), $store, $freezer, $ice10, 3, 2);

        $this->assertEqualsWithDelta(
            0,
            $this->stockCheck($itemId)['drift_ball'],
            0.01,
        );

        $freezer->update(['last_weight_kg' => 115]);

        $this->assertSame('DRIFT', $this->stockCheck($itemId)['result']);
    }

    public function test_the_stock_check_treats_an_approved_sale_as_gone_from_the_freezer(): void
    {
        $store = Store::factory()->create();
        // tare 45 kg + 3 ball of 10 kg = 75 kg, so the sensor reports 3 ball.
        $freezer = Freezer::factory()->withReadings(75)->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($this->inProgressDelivery(), $store, $freezer, $ice10, 5, 1);

        $saleId = $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 3,
            ])
            ->assertCreated()
            ->json('data.sale.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertOk();

        // 1 before, 5 delivered, 3 sold leaves 3, which is what the sensor
        // reports, so an approved sale has to be subtracted for the check to
        // still line up.
        $check = $this->stockCheck($itemId);

        $this->assertEqualsWithDelta(3, $check['sold_ball'], 0.01);
        $this->assertEqualsWithDelta(3, $check['expected_stock_ball'], 0.01);
        $this->assertSame('MATCH', $check['result']);
    }

    public function test_a_pending_sale_is_not_counted_as_gone_from_the_freezer(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->withReadings(75)->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();

        $itemId = $this->confirmProduct($this->inProgressDelivery(), $store, $freezer, $ice10, 5, 1);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 3,
            ])
            ->assertCreated();

        // Only confirmed sales leave the freezer as far as the system knows,
        // so an unreviewed sale leaves 6 expected against a sensor reading 3
        // and the check reports a drift rather than a false match.
        $check = $this->stockCheck($itemId);

        $this->assertEqualsWithDelta(0, $check['sold_ball'], 0.01);
        $this->assertEqualsWithDelta(6, $check['expected_stock_ball'], 0.01);
        $this->assertSame('DRIFT', $check['result']);
    }

    public function test_the_stock_check_adds_up_every_product_of_a_multi_product_visit(): void
    {
        $store = Store::factory()->create();
        // tare 45 kg + 8 ball of 10 kg = 125 kg, so the sensor reports 8 ball.
        $freezer = Freezer::factory()->withReadings(125)->create([
            'store_id' => $store->id,
            'max_capacity_ball' => 20,
        ]);
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15, 'selling_price' => 15000]);

        $delivery = $this->inProgressDelivery();

        // Two products, two rows, one visit: 6 + 2 before, 2 + 2 delivered.
        $this->confirmProduct($delivery, $store, $freezer, $ice10, 2, 6);
        $item15Id = $this->confirmProduct($delivery, $store, $freezer, $ice15, 2, 2);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$item15Id}/sales", [
                'product_id' => $ice15->id,
                'qty_ball' => 1,
            ])
            ->assertCreated();
        $saleId = $this->actingAs($this->driver(), 'sanctum')
            ->getJson("/admin/delivery-items/{$item15Id}")
            ->json('data.delivery_item.sales.0.id');
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/admin/sales/{$saleId}/approve")
            ->assertOk();

        // The baseline is a whole-freezer figure, so it has to be the sum of
        // both products. Using only the last row would compare one product's
        // 2 ball against the 8 ball the sensor reports.
        $check = $this->stockCheck($item15Id);

        $this->assertEqualsWithDelta(8, $check['confirmed_stock_before_ball'], 0.01);
        $this->assertEqualsWithDelta(4, $check['delivered_ball'], 0.01);
        $this->assertEqualsWithDelta(1, $check['sold_ball'], 0.01);
        $this->assertEqualsWithDelta(11, $check['expected_stock_ball'], 0.01);
    }

    public function test_sales_cannot_be_recorded_for_a_delivery_that_is_not_in_progress(): void
    {
        $delivery = $this->inProgressDelivery();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $ice10 = Product::factory()->ice10()->create();
        $itemId = $this->confirmProduct($delivery, $store, $freezer, $ice10);

        $delivery->update(['status' => 'COMPLETED']);

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $ice10->id,
                'qty_ball' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only IN_PROGRESS deliveries can record sales');
    }

    /**
     * Confirm one product and return the delivery item id plus the stock check
     * the endpoint reports.
     */
    private function stockCheck(int $deliveryItemId): array
    {
        return $this->actingAs($this->driver(), 'sanctum')
            ->getJson("/admin/delivery-items/{$deliveryItemId}")
            ->assertOk()
            ->json('data.stock_check');
    }

    /**
     * Record one 2-ball sale of the 10 kg product at Rp10.000/ball and return
     * its id, optionally approving it right away.
     */
    private function recordSale(?Store $store = null, bool $approved = false, ?Product $product = null): int
    {
        $store ??= Store::factory()->create();
        $delivery = $this->inProgressDelivery();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        // Reused when given, because the product code is unique and two
        // separate ice10() products would collide on it.
        $product ??= Product::factory()->ice10()->create();
        $itemId = $this->confirmProduct($delivery, $store, $freezer, $product, 5);

        $saleId = $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/admin/delivery-items/{$itemId}/sales", [
                'product_id' => $product->id,
                'qty_ball' => 2,
            ])
            ->assertCreated()
            ->json('data.sale.id');

        if ($approved) {
            $this->actingAs($this->admin(), 'sanctum')
                ->postJson("/admin/sales/{$saleId}/approve")
                ->assertOk();
        }

        return $saleId;
    }
}
