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

    /**
     * Approve a draft, which is what the driver waits for before starting.
     */
    private function approve(int $deliveryId): int
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED');

        return $deliveryId;
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
                'stores' => [$near->id, $far->id],
            ])
            ->assertCreated()
            ->json('data.id');

        // A delivery plan carries no collection target. The BRD only sets one at
        // the shop, and the daily target belongs to the progress screen, so the
        // route has nothing of the sort to report.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk()
            ->assertJsonPath('data.stops_total', 2)
            ->assertJsonPath('data.stops_visited', 0)
            ->assertJsonMissingPath('data.collection_target')
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

        $this->approve($deliveryId);

        $this->actingAs($driver, 'sanctum')
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

        $this->approve($deliveryId);

        $this->actingAs($driver, 'sanctum')
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

    /**
     * A driver can draft a plan and adjust it, but not release it. DRAFT is
     * where a plan is a proposal; an admin starts the run, so nothing a driver
     * writes reaches the road without someone deciding it should.
     */
    public function test_a_driver_can_draft_a_plan_for_themselves(): void
    {
        $driver = $this->driver();
        $store = Store::factory()->create();

        $response = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'notes' => 'Route I want to try tomorrow',
                'stores' => [$store->id],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.notes', 'Route I want to try tomorrow');

        $this->assertDatabaseHas('deliveries', [
            'id' => $response->json('data.id'),
            'status' => 'DRAFT',
            'driver_id' => $driver->id,
        ]);

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $response->json('data.id'),
            'store_id' => $store->id,
        ]);
    }

    /**
     * A driver cannot put a route on someone else's dashboard, and cannot take
     * one that is already theirs away from them.
     */
    public function test_a_driver_cannot_draft_a_plan_for_another_driver(): void
    {
        $mine = $this->driver();
        $colleague = $this->driver();

        $this->actingAs($mine, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $colleague->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'A driver can only create a delivery plan for themselves');

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_a_driver_can_edit_their_own_draft(): void
    {
        $driver = $this->driver();
        $first = Store::factory()->create();
        $replacement = Store::factory()->create();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [$first->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($driver, 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'stores' => [$replacement->id],
                'notes' => 'Swapped the first stop',
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Swapped the first stop');

        $this->assertDatabaseMissing('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $first->id,
        ]);
        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $replacement->id,
        ]);
    }

    public function test_a_driver_cannot_edit_another_drivers_draft(): void
    {
        $colleague = $this->driver();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $colleague->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->driver(), 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This delivery is assigned to another driver');

        $this->assertDatabaseCount('delivery_stops', 1);
    }

    /**
     * Reassigning is the admin's call. A driver keeping their own name on their
     * own draft is allowed, since that changes nothing.
     */
    public function test_a_driver_cannot_reassign_their_own_draft(): void
    {
        $driver = $this->driver();
        $colleague = $this->driver();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($driver, 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'driver_id' => $colleague->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'A driver cannot reassign their own delivery plan');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'driver_id' => $driver->id,
        ]);
    }

    /**
     * The step a driver cannot take. A plan they drafted themselves is still
     * only a draft, and an admin is the one who puts it on the road.
     */
    public function test_a_driver_cannot_start_their_own_draft(): void
    {
        $driver = $this->driver();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        // A draft must be POSTED by an admin before it can be started, and the
        // driver who drafted it cannot start it while it's still unapproved.
        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This delivery plan has not been approved yet')
            ->assertJsonPath('current_status', 'DRAFT');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'DRAFT',
        ]);

        // Once approved (POSTED), the driver can start their own run. The BRD
        // gives them the [START DELIVERY] button at 7 AM, which this honours.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED');

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');
    }

    /**
     * Warehouse does not get the start button at all, and this is the one place
     * where the route is the whole answer: the request never reaches the
     * controller, so an approved plan is refused just as firmly as a draft.
     *
     * BRD:417 puts [VIEW ROUTE] -> [START DELIVERY] in the driver's own 7:00 AM
     * screen. BRD:421 gives warehouse the loading job, "Verify & load 50 ball",
     * and never the start.
     */
    public function test_warehouse_cannot_start_a_delivery(): void
    {
        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->approve($deliveryId);

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertStatus(403)
            ->assertJsonPath('details.required_roles', 'ADMIN, DRIVER');

        // An approved plan that warehouse cannot start is not started by mistake.
        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'POSTED',
        ]);
    }

    /**
     * Once the run is live the plan is frozen, driver included. Editing a route
     * mid-run would let the delivery that was agreed and the delivery actually
     * driven drift apart.
     */
    public function test_a_driver_cannot_edit_a_run_that_has_started(): void
    {
        $driver = $this->driver();
        $store = Store::factory()->create();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [$store->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->approve($deliveryId);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertOk();

        $this->actingAs($driver, 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only DRAFT deliveries can be edited');

        $this->assertDatabaseHas('delivery_stops', [
            'delivery_id' => $deliveryId,
            'store_id' => $store->id,
        ]);
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

    /**
     * A run that is on the road, so the endpoints behind it can be tested against
     * a delivery that is genuinely IN_PROGRESS rather than one faked into that
     * state.
     */
    private function liveRun(?User $driver = null, ?Store $store = null, int $load = 30): array
    {
        $driver ??= $this->driver();
        $store ??= Store::factory()->create();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => $load,
                'stores' => [$store->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->approve($deliveryId);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/start")
            ->assertOk();

        return [$driver, $store, $deliveryId];
    }

    /**
     * Warehouse drafts nothing, approves nothing, and cannot delete a draft.
     *
     * This is a team decision that goes against BRD:342-352, which puts
     * [CREATE DELIVERY PLAN] inside the WAREHOUSE STAFF section. It is asserted
     * here so the next person to open routes/api.php finds out from a test
     * rather than from a stakeholder.
     */
    public function test_warehouse_cannot_draft_a_delivery_plan(): void
    {
        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(403)
            ->assertJsonPath('details.required_roles', 'ADMIN, DRIVER');
    }

    /**
     * Approving is admin's alone. A driver who drafted the plan cannot approve
     * their own, or the approval step would be a formality.
     */
    public function test_a_driver_cannot_approve_their_own_plan(): void
    {
        $driver = $this->driver();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/post")
            ->assertStatus(403)
            ->assertJsonPath('details.required_roles', 'ADMIN');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'DRAFT',
        ]);
    }

    public function test_warehouse_cannot_approve_a_plan(): void
    {
        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/post")
            ->assertStatus(403);

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'DRAFT',
        ]);
    }

    /**
     * Editing a draft is a drafting permission, so warehouse is off it for the
     * same reason as creating one.
     */
    public function test_warehouse_cannot_edit_a_draft(): void
    {
        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->putJson("/api/deliveries/{$deliveryId}", [
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('delivery_stops', 1);
    }

    /**
     * Deleting is the third drafting action, and the one the user called out:
     * warehouse must not be able to remove a draft that is still DRAFT.
     */
    public function test_warehouse_cannot_delete_a_draft(): void
    {
        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $this->driver()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->deleteJson("/api/deliveries/{$deliveryId}")
            ->assertStatus(403)
            ->assertJsonPath('details.required_roles', 'ADMIN');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'DRAFT',
        ]);
    }

    /**
     * A driver cannot delete either. The plan is the admin's record of what was
     * agreed; a driver who does not want to run it leaves it as DRAFT.
     */
    public function test_a_driver_cannot_delete_their_own_draft(): void
    {
        $driver = $this->driver();

        $deliveryId = $this->actingAs($driver, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => $driver->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'initial_qty_loaded_ball' => 20,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($driver, 'sanctum')
            ->deleteJson("/api/deliveries/{$deliveryId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'DRAFT',
        ]);
    }

    /**
     * BRD:366-374 has the driver submitting the completion, not the warehouse.
     */
    public function test_warehouse_cannot_complete_a_delivery(): void
    {
        [, , $deliveryId] = $this->liveRun();

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/complete", [
                'total_qty_returned_ball' => 30,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'IN_PROGRESS',
        ]);
    }

    /**
     * The run is closed by the person who drove it.
     */
    public function test_the_driver_can_complete_their_own_run(): void
    {
        [$driver, , $deliveryId] = $this->liveRun();

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/complete", [
                'total_qty_returned_ball' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('summary.balance_verified', true);
    }

    /**
     * A driver cannot close somebody else's run by guessing the id.
     */
    public function test_a_driver_cannot_complete_another_drivers_run(): void
    {
        [, , $deliveryId] = $this->liveRun();
        $stranger = $this->driver();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/complete", [
                'total_qty_returned_ball' => 30,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This delivery is assigned to another driver');

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'IN_PROGRESS',
        ]);
    }

    /**
     * BRD:435 onwards, "At Store - SETTLEMENT WORKFLOW", is the driver's at
     * every step. Warehouse reads progress but does not act on it.
     */
    public function test_warehouse_cannot_record_a_stop_visit(): void
    {
        [$driver, $store, $deliveryId] = $this->liveRun();
        $warehouse = User::factory()->warehouse()->create();

        $this->actingAs($warehouse, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertStatus(403);

        $this->actingAs($warehouse, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/depart", [
                'notes' => 'closed for the day',
            ])
            ->assertStatus(403);

        $this->actingAs($warehouse, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/skip", [
                'reason' => 'Toko tutup',
            ])
            ->assertStatus(403);

        $this->actingAs($warehouse, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/settlement-preview")
            ->assertStatus(403);

        // The stop is still pending, so nothing was recorded behind the refusals.
        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertOk();
    }

    /**
     * A driver cannot walk a stop on somebody else's run.
     */
    public function test_a_driver_cannot_record_a_visit_on_another_drivers_run(): void
    {
        [, $store, $deliveryId] = $this->liveRun();

        $this->actingAs($this->driver(), 'sanctum')
            ->postJson("/api/deliveries/{$deliveryId}/stops/{$store->id}/arrive")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This delivery is assigned to another driver');
    }

    /**
     * Reading is what warehouse keeps: BRD:258-260 lists delivery plans, status
     * and active progress under what they see, and BRD:374 needs the delivered
     * total to move warehouse stock.
     */
    public function test_warehouse_can_still_read_deliveries(): void
    {
        [$driver, $store, $deliveryId] = $this->liveRun();
        $warehouse = User::factory()->warehouse()->create();

        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $deliveryId);

        $this->actingAs($warehouse, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}")
            ->assertOk()
            ->assertJsonPath('data.id', $deliveryId);

        $this->actingAs($warehouse, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/summary")
            ->assertOk();

        $this->actingAs($warehouse, 'sanctum')
            ->getJson("/api/deliveries/{$deliveryId}/route")
            ->assertOk();

        unset($driver, $store);
    }

}
