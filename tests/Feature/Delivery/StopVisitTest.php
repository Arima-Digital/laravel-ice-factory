<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Production;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A visit to a store is two moments, arriving and leaving, and the BRD shows
 * them as two steps. The backend only had the first one, and only as a side
 * effect of confirming a freezer, so a store the driver reached and found
 * nothing to sell could never be closed: the stop stayed pending for the rest of
 * the run, and the progress count said there was work left that was already
 * done. A shop that was shut had no way to be written off at all.
 *
 * These tests cover both moments as their own endpoints, the pass-over, and the
 * settlement preview the driver needs before taking money.
 */
class StopVisitTest extends TestCase
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

    private function stockedWarehouse(): Warehouse
    {
        $warehouse = Warehouse::factory()->create();

        Production::factory()
            ->posted()
            ->yielding(100)
            ->create(['warehouse_id' => $warehouse->id]);

        return $warehouse;
    }

    /**
     * A started run with the given stores on its route, and its driver.
     *
     * @param  array<int>  $storeIds
     * @return array{0: User, 1: int}
     */
    private function startedRun(array $storeIds, ?User $driver = null): array
    {
        $driver ??= $this->driver();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => $storeIds,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/post")
            ->assertOk();

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertOk();

        return [$driver, $deliveryId];
    }

    public function test_the_driver_records_arriving_at_a_stop(): void
    {
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk()
            ->assertJsonPath('data.arrived_at', fn ($value) => $value !== null);

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $store->id,
            'status' => 'PENDING',
        ]);

        // The store's own details and its freezers come back with the arrival,
        // which is what the driver's arrival screen needs before unloading.
        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk()
            ->assertJsonStructure(['data' => ['arrived_at', 'store' => ['id', 'name'], 'freezers']]);

        $route = $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk();

        // The driver is standing in this store now, so it is neither the one
        // being served nor the next one to drive to.
        $route->assertJsonPath('data.at_store.id', $store->id);
        $route->assertJsonPath('data.next_stop', null);
    }

    public function test_arriving_keeps_the_first_time_when_the_screen_is_reopened(): void
    {
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $first = $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk()
            ->json('data.arrived_at');

        $second = $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk()
            ->json('data.arrived_at');

        $this->assertSame($first, $second);
    }

    public function test_a_store_with_nothing_to_sell_can_still_be_closed(): void
    {
        // The case that had no way to be closed before: the driver gets there and
        // the shop has nothing, so no confirmation is ever made.
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk();

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/depart")
            ->assertOk()
            ->assertJsonPath('data.departed_at', fn ($value) => $value !== null);

        $this->assertNotNull(
            $this->actingAs($driver, 'sanctum')
                ->getJson("/api/deliveries/{$deliveryId}/route")
                ->assertOk()
                ->json('data.stops.0.departed_at')
        );

        // Nothing was delivered there, so the stop is not claimed as visited.
        $this->assertDatabaseMissing('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $store->id,
            'status' => 'VISITED',
        ]);

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/summary")
            ->assertOk()
            ->assertJsonPath('data.stops_departed', 1)
            ->assertJsonPath('data.at_store', null);
    }

    public function test_leaving_a_stop_with_confirmed_goods_marks_it_visited(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => Product::factory()->create()->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertCreated();

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/depart")
            ->assertOk()
            ->assertJsonPath('data.status', 'VISITED');
    }

    public function test_a_stop_cannot_be_left_without_arriving(): void
    {
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/depart")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The driver has not arrived at this stop yet');
    }

    public function test_confirming_goods_records_an_arrival_that_was_never_announced(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        // A driver who skips the arrival screen and just confirms is still
        // standing at the shop, so the stop cannot read as unvisited.
        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => Product::factory()->create()->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $store->id,
            'status' => 'VISITED',
        ]);

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk()
            ->assertJsonPath('data.at_store.id', $store->id);
    }

    public function test_a_closed_shop_can_be_passed_over_with_a_reason(): void
    {
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/skip", [
                'reason' => 'Toko tutup, tidak ada yang bisa dilayani',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'SKIPPED')
            ->assertJsonPath('data.notes', 'Toko tutup, tidak ada yang bisa dilayani');

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/summary")
            ->assertOk()
            ->assertJsonPath('data.stops_skipped', 1);
    }

    public function test_skipping_a_stop_needs_a_reason(): void
    {
        $store = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/skip")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_goods_cannot_be_confirmed_at_a_skipped_stop(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/skip", [
                'reason' => 'Toko tutup',
            ])
            ->assertOk();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => Product::factory()->create()->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This stop was skipped and cannot receive goods');
    }

    public function test_the_preview_totals_the_visit_and_the_previous_debt(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        $product = Product::factory()->create(['selling_price' => 10000]);

        $itemId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => $product->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertCreated()
            ->json('data.delivery_item.id');

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/delivery-items/{$itemId}/sales", [
                'product_id' => $product->id,
                'qty_ball' => 2,
            ])
            ->assertCreated();

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/settlement-preview")
            ->assertOk()
            ->assertJsonPath('data.total_delivered_ball', 5)
            ->assertJsonPath('data.outstanding_before_this_stop', 0)
            // Two ball at 10,000 is what the driver can ask for today.
            ->assertJsonPath('data.payable_today', 20000)
            ->assertJsonPath('data.this_visit_sales.pending_total', 20000)
            ->assertJsonPath('data.all_freezers_confirmed', true);
    }

    public function test_the_preview_adds_the_previous_debt_to_what_the_visit_collected(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        // An earlier run left the store owing 50,000, already approved so it is a
        // real debt. Nothing has been paid against it.
        Sale::create([
            'store_id' => $store->id,
            'freezer_id' => $freezer->id,
            'qty_ball' => 5,
            'unit_price' => 10000,
            'total_amount' => 50000,
            'status' => 'CONFIRMED',
            'sold_at' => now()->subDays(3),
        ]);

        $product = Product::factory()->create(['selling_price' => 10000]);

        $itemId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $store->id,
                'freezer_id' => $freezer->id,
                'product_id' => $product->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertCreated()
            ->json('data.delivery_item.id');

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/delivery-items/{$itemId}/sales", [
                'product_id' => $product->id,
                'qty_ball' => 2,
            ])
            ->assertCreated();

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/settlement-preview")
            ->assertOk()
            ->assertJsonPath('data.outstanding_before_this_stop', 50000)
            // The old 50,000 plus the 20,000 from this visit, not just one of
            // them: a driver who only sees today's sales would under-ask and the
            // store would keep owing the rest.
            ->assertJsonPath('data.payable_today', 70000)
            ->assertJsonPath('data.payment_options.PAY_OLD_DEBT.amount', 50000)
            ->assertJsonPath('data.payment_options.PAY_TODAY.amount', 70000);
    }

    public function test_sales_from_another_run_are_left_out_of_this_stop(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        [$driver, $deliveryId] = $this->startedRun([$store->id]);

        // A line from a delivery that is not this one. The same store, a
        // different day, and it must not be counted as money this stop took.
        $otherItem = DeliveryItem::create([
            'delivery_id' => Delivery::create([
                'delivery_date' => now()->subWeek()->toDateString(),
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 10,
                'status' => 'COMPLETED',
            ])->id,
            'store_id' => $store->id,
            'freezer_id' => $freezer->id,
            'product_id' => Product::factory()->create()->id,
            'confirmed_stock_before_ball' => 0,
            'delivered_qty_ball' => 9,
            'visited_at' => now()->subWeek(),
        ]);

        Sale::create([
            'store_id' => $store->id,
            'freezer_id' => $freezer->id,
            'delivery_item_id' => $otherItem->id,
            'qty_ball' => 9,
            'unit_price' => 10000,
            'total_amount' => 90000,
            'status' => 'CONFIRMED',
            'sold_at' => now()->subWeek(),
        ]);

        $this->actingAs($driver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/settlement-preview")
            ->assertOk()
            // Nothing was delivered or sold on this run, so both figures are
            // zero even though the store has 90,000 of history behind it.
            ->assertJsonPath('data.total_delivered_ball', 0)
            ->assertJsonPath('data.this_visit_sales.total', 0);
    }

    public function test_a_stop_off_the_route_has_no_visit_endpoints(): void
    {
        $onRoute = Store::factory()->create();
        $offRoute = Store::factory()->create();
        [$driver, $deliveryId] = $this->startedRun([$onRoute->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$offRoute->id}/arrive")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This store is not on the delivery route');
    }

    public function test_a_visit_cannot_be_recorded_before_the_run_starts(): void
    {
        $store = Store::factory()->create();
        $driver = $this->driver();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$store->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stop visits are only recorded while the delivery is in progress');
    }

    public function test_a_driver_cannot_reach_another_drivers_stops(): void
    {
        $store = Store::factory()->create();
        $otherDriver = $this->driver();
        [, $deliveryId] = $this->startedRun([$store->id], $otherDriver);

        $stranger = $this->driver();

        // Reading it, arriving at it, and previewing the money are all the
        // delivery owner's business only.
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/summary")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/settlement-preview")
            ->assertStatus(403);

        // The driver it belongs to still gets in.
        $this->actingAs($otherDriver, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk();
    }
}
