<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Production;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A delivery plan used to record a driver, a vehicle, a warehouse and a load,
 * and nothing about where the driver was going. The store only appeared once
 * the driver was standing in front of it, so an in-progress run could not say
 * where it was meant to be, and a driver who visited the wrong shop was
 * indistinguishable from one who followed the plan.
 *
 * These tests pin down the plan itself: that a route has to be given, that it
 * is ordered by distance rather than by the order it was typed, that a visit
 * is only accepted at a planned stop, and that a driver only sees their own
 * runs.
 */
class DeliveryRouteTest extends TestCase
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

    /**
     * A store at a given position, so distances are deliberate rather than
     * whatever the faker produced.
     */
    private function storeAt(float $latitude, float $longitude): Store
    {
        return Store::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    private function warehouseAt(float $latitude, float $longitude): Warehouse
    {
        return Warehouse::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /**
     * A warehouse holding enough posted stock to load a plan from, at a chosen
     * position.
     */
    private function stockedWarehouseAt(float $latitude, float $longitude): Warehouse
    {
        $warehouse = $this->warehouseAt($latitude, $longitude);

        Production::factory()
            ->posted()
            ->yielding(100)
            ->create(['warehouse_id' => $warehouse->id]);

        return $warehouse;
    }

    /**
     * A warehouse holding enough posted stock to load a plan from.
     */
    private function stockedWarehouse(): Warehouse
    {
        return $this->stockedWarehouseAt(-6.2000, 106.8000);
    }

    public function test_a_delivery_plan_must_name_the_stores_it_visits(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stores');
    }

    public function test_creating_a_plan_records_every_store_as_a_stop(): void
    {
        // Fixed positions, so the sequence each store lands on is decided by
        // the planner rather than by whatever coordinates the faker produced.
        $storeA = $this->storeAt(-6.2001, 106.8001);
        $storeB = $this->storeAt(-6.3000, 106.9000);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$storeA->id, $storeB->id],
            ])
            ->assertCreated();

        $deliveryId = $response->json('data.id');

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $storeA->id,
            'sequence' => 1,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $storeB->id,
            'sequence' => 2,
        ]);
    }

    public function test_stops_are_ordered_nearest_first_from_the_warehouse(): void
    {
        $warehouse = $this->stockedWarehouseAt(-6.2000, 106.8000);

        $far = $this->storeAt(-6.3000, 106.9000);
        $near = $this->storeAt(-6.2001, 106.8001);
        $middle = $this->storeAt(-6.2500, 106.8500);

        // Typed far-to-near: the route must not keep that order.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $warehouse->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$far->id, $near->id, $middle->id],
            ])
            ->assertCreated();

        $order = Delivery::with('stops')->latest('id')->first()->stops->pluck('store_id')->all();

        $this->assertSame([$near->id, $middle->id, $far->id], $order);
    }

    public function test_a_store_cannot_be_listed_twice_on_one_plan(): void
    {
        $store = Store::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$store->id, $store->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The same store was listed more than once');
    }

    public function test_the_route_endpoint_reports_order_distance_and_progress(): void
    {
        $warehouse = $this->stockedWarehouseAt(-6.2000, 106.8000);
        $near = $this->storeAt(-6.2001, 106.8001);
        $far = $this->storeAt(-6.3000, 106.9000);

        $driver = $this->driver();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $warehouse->id,
                'initial_qty_loaded_ball' => 30,
                'collection_target' => 500000,
                'stores' => [$near->id, $far->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk()
            ->assertJsonPath('data.stops_total', 2)
            ->assertJsonPath('data.stops_visited', 0)
            ->assertJsonPath('data.collection_target', '500000.00')
            ->assertJsonPath('data.stops.0.store.id', $near->id)
            ->assertJsonPath('data.current_stop.id', $near->id);

        // The route has to be longer than one straight hop, and every leg has
        // to be measurable now that both ends have coordinates.
        $this->assertGreaterThan(0, json_decode(
            $this->actingAs($this->admin(), 'sanctum')
                ->getJson("/api/deliveries/{$deliveryId}/route")
                ->getContent(),
            true
        )['data']['total_km']);

        $this->assertNotNull(json_decode(
            $this->actingAs($this->admin(), 'sanctum')
                ->getJson("/api/deliveries/{$deliveryId}/route")
                ->getContent(),
            true
        )['data']['stops'][0]['leg_km']);
    }

    public function test_a_store_without_coordinates_is_still_planned_but_has_no_distance(): void
    {
        $warehouse = $this->stockedWarehouseAt(-6.2000, 106.8000);
        $located = $this->storeAt(-6.2001, 106.8001);
        $unlocated = Store::factory()->withoutLocation()->create();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $warehouse->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$located->id, $unlocated->id],
            ])
            ->assertCreated()
            ->json('data.id');

        // Planned, so the driver can still be told to go there.
        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $unlocated->id,
        ]);

        // But it cannot be measured, and the API says so instead of guessing.
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk()
            ->assertJsonPath('data.stops_total', 2);

        $unlocatedLeg = collect($response->json('data.stops'))
            ->firstWhere('store.id', $unlocated->id);

        $this->assertNull($unlocatedLeg['leg_km']);
    }

    public function test_a_driver_cannot_confirm_a_delivery_at_a_store_off_the_route(): void
    {
        $onRoute = Store::factory()->create();
        $offRoute = Store::factory()->create();

        $driver = $this->driver();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$onRoute->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertOk();

        $freezer = Freezer::factory()->create(['store_id' => $offRoute->id]);

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/delivery-items/confirm', [
                'delivery_id' => $deliveryId,
                'store_id' => $offRoute->id,
                'freezer_id' => $freezer->id,
                'product_id' => Product::factory()->create()->id,
                'confirmed_stock_before_ball' => 1,
                'delivered_qty_ball' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This store is not on the delivery route');

        $this->assertDatabaseMissing('delivery_items', [
            'delivery_id' => $deliveryId,
            'store_id' => $offRoute->id,
        ]);
    }

    public function test_confirming_at_a_planned_stop_marks_it_visited(): void
    {
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
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

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
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
            ->assertCreated();

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $store->id,
            'status' => 'VISITED',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk()
            ->assertJsonPath('data.stops_visited', 1);
    }

    public function test_a_driver_only_sees_their_own_deliveries(): void
    {
        $mine = $this->driver();
        $someoneElse = $this->driver();

        $myDelivery = Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => $mine->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'initial_qty_loaded_ball' => 10,
            'status' => 'DRAFT',
        ]);

        Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => $someoneElse->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'initial_qty_loaded_ball' => 10,
            'status' => 'DRAFT',
        ]);

        $response = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertSame($myDelivery->id, $response->json('data.0.id'));

        // An admin still sees everything.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_replacing_the_stores_on_a_draft_rebuilds_the_route(): void
    {
        $first = Store::factory()->create();
        $second = Store::factory()->create();
        $replacement = Store::factory()->create();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$first->id, $second->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'stores' => [$replacement->id],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $first->id,
        ]);
        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $replacement->id,
            'sequence' => 1,
        ]);
    }
}
