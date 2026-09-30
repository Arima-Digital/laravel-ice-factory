<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\Production;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock used to be computed by summing the whole productions table while
 * accepting a warehouse id as an argument, so the id was echoed back to the
 * caller but never reached the query. Every warehouse reported the same figure
 * and a delivery could be cleared against stock held somewhere else entirely.
 *
 * These tests pin the scoping down, including the cases that are easiest to
 * break again: two warehouses that must not agree, production with no
 * warehouse, and cancelled deliveries.
 */
class WarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * A POSTED batch of $good balls sitting in $warehouse, or unattributed when
     * $warehouse is null.
     */
    private function postedIn(
        ?Warehouse $warehouse,
        float $good,
        ?Product $product = null,
        float $reject = 0,
        ?string $date = null
    ): Production {
        return Production::factory()
            ->posted()
            ->yielding($good, $reject)
            ->when($warehouse === null, fn ($factory) => $factory->unassigned())
            ->create(array_filter([
                'warehouse_id' => $warehouse?->id,
                'product_id' => $product?->id,
                'production_date' => $date,
            ], fn ($value) => $value !== null));
    }

    private function stock(): WarehouseStockService
    {
        return app(WarehouseStockService::class);
    }

    public function test_available_stock_is_scoped_to_the_warehouse_in_the_url(): void
    {
        $admin = $this->admin();
        $central = Warehouse::factory()->create();
        $north = Warehouse::factory()->create();

        $this->postedIn($central, 77);
        $this->postedIn($north, 60);

        // Two warehouses, two different numbers. The old implementation summed
        // both batches and answered 137 for each of them.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$central->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.available_stock', 77)
            ->assertJsonPath('data.unassigned_production_qty', 0);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$north->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.available_stock', 60);
    }

    public function test_warehouse_stock_accessor_is_not_identical_across_warehouses(): void
    {
        $central = Warehouse::factory()->create();
        $north = Warehouse::factory()->create();

        $this->postedIn($central, 40);
        $this->postedIn($north, 10);

        $central->refresh();
        $north->refresh();

        $this->assertEquals(40, $central->warehouse_stock);
        $this->assertEquals(10, $north->warehouse_stock);
    }

    public function test_draft_production_does_not_count_as_available_stock(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::factory()->create();

        // DRAFT batches are not finalised, so they are not shippable stock.
        Production::factory()->yielding(95)->create(['warehouse_id' => $warehouse->id]);
        $this->postedIn($warehouse, 20);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$warehouse->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.total_produced_posted', 20)
            ->assertJsonPath('data.available_stock', 20);
    }

    public function test_production_without_a_warehouse_is_reported_as_unassigned(): void
    {
        $admin = $this->admin();
        $central = Warehouse::factory()->create();
        $north = Warehouse::factory()->create();

        $this->postedIn($central, 30);
        $this->postedIn(null, 12);

        // The unattributed batch must not be handed to one of the warehouses,
        // and it must be visible rather than lost.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$central->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.available_stock', 30)
            ->assertJsonPath('data.unassigned_production_qty', 12);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$north->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.available_stock', 0)
            ->assertJsonPath('data.unassigned_production_qty', 12);
    }

    public function test_delivered_items_reduce_only_their_own_warehouse(): void
    {
        $admin = $this->admin();
        $central = Warehouse::factory()->create();
        $north = Warehouse::factory()->create();

        $this->postedIn($central, 77);
        $this->postedIn($north, 60);

        $this->deliveryWithDeliveredQty($central, 16);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$central->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.total_delivered', 16)
            ->assertJsonPath('data.available_stock', 61);

        // The northern warehouse shipped nothing, so its stock is untouched.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$north->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.total_delivered', 0)
            ->assertJsonPath('data.available_stock', 60);
    }

    public function test_cancelled_delivery_does_not_reduce_stock(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::factory()->create();

        $this->postedIn($warehouse, 50);
        $this->deliveryWithDeliveredQty($warehouse, 20, 'CANCELLED');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/warehouses/{$warehouse->id}/available-stock")
            ->assertOk()
            ->assertJsonPath('data.total_delivered', 0)
            ->assertJsonPath('data.available_stock', 50);
    }

    public function test_delivery_is_rejected_when_the_chosen_warehouse_lacks_stock(): void
    {
        $admin = $this->admin();
        $empty = Warehouse::factory()->create();
        $stocked = Warehouse::factory()->create();

        $this->postedIn($stocked, 100);

        // There is stock, but not in the warehouse the driver is loading from.
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $empty->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [Store::factory()->create()->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient warehouse stock')
            ->assertJsonPath('warehouse_id', $empty->id)
            ->assertJsonPath('available', 0);
    }

    public function test_delivery_is_accepted_when_the_chosen_warehouse_has_stock(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::factory()->create();

        $this->postedIn($warehouse, 100);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $warehouse->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('deliveries', [
            'warehouse_id' => $warehouse->id,
            'initial_qty_loaded_ball' => 30,
        ]);
    }

    public function test_creating_a_production_requires_a_warehouse(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/productions', [
                'product_id' => Product::factory()->create()->id,
                'production_date' => now()->toDateString(),
                'qty_produced_ball' => 100,
                'qty_reject_ball' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_creating_a_production_records_the_warehouse(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/productions', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'production_date' => now()->toDateString(),
                'qty_produced_ball' => 100,
                'qty_reject_ball' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.warehouse_id', $warehouse->id);

        $this->assertDatabaseHas('productions', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_production_list_can_be_filtered_by_warehouse(): void
    {
        $admin = $this->admin();
        $central = Warehouse::factory()->create();
        $north = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $this->postedIn($central, 10, $product);
        $this->postedIn($north, 10, $product);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/productions?warehouse_id={$north->id}")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.warehouse_id', $north->id);
    }

    /**
     * The date report summed "qty_produced" and "qty_reject", which are not
     * columns on the table, so both totals silently came back as 0.
     */
    public function test_production_date_report_returns_real_totals(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $date = now()->toDateString();

        $this->postedIn($warehouse, 77, $product, 5, $date);
        $this->postedIn($warehouse, 60, $product, 2, $date);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/productions/date/{$date}")
            ->assertOk()
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.total_produced', 144)
            ->assertJsonPath('summary.total_reject', 7)
            ->assertJsonPath('summary.total_good', 137);
    }

    /**
     * A delivery that actually moved $qty out of $warehouse.
     */
    private function deliveryWithDeliveredQty(
        Warehouse $warehouse,
        float $qty,
        string $status = 'COMPLETED'
    ): void {
        $delivery = Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => User::factory()->driver()->create()->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'initial_qty_loaded_ball' => $qty,
            'status' => $status,
        ]);

        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'store_id' => Store::factory()->create()->id,
            'freezer_id' => Freezer::factory()->create()->id,
            'product_id' => Product::factory()->create()->id,
            'confirmed_stock_before_ball' => $qty,
            'delivered_qty_ball' => $qty,
            'visited_at' => now(),
        ]);
    }
}
