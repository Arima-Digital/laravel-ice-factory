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
 * The load manifest of a run: what the driver carries out of the warehouse,
 * split by product.
 *
 * deliveries.initial_qty_loaded_ball is one number for the whole van, so on its
 * own it cannot say which products are on board. These tests pin down the
 * breakdown that goes with it: that a plan has to state it, that it has to add
 * up to the headline figure, and that it comes back with the delivery.
 */
class DeliveryLoadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
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
     * A create payload carrying the given products, defaulting to a load whose
     * total is 30 ball.
     *
     * @param  array<int, array{product_id: int, qty_ball: float|int}>  $loadedItems
     */
    private function payload(Warehouse $warehouse, array $loadedItems, float $loadedBall = 30): array
    {
        return [
            'driver_id' => User::factory()->driver()->create()->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'initial_qty_loaded_ball' => $loadedBall,
            'loaded_items' => $loadedItems,
            'stores' => [Store::factory()->create()->id],
        ];
    }

    public function test_the_load_is_derived_from_the_freezer_config_when_none_is_given(): void
    {
        $big = Product::factory()->create(['code' => 'ICE-10', 'weight_kg' => 10]);
        $small = Product::factory()->create(['code' => 'ICE-5', 'weight_kg' => 5]);

        $warehouse = $this->stockedWarehouse();
        $store = Store::factory()->create();
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);
        $freezer->productSettings()->createMany([
            ['product_id' => $big->id, 'percentage' => 70],
            ['product_id' => $small->id, 'percentage' => 30],
        ]);

        // No loaded_items: the split is taken from the freezer's config and the
        // remainder lands on the biggest share, so 30 becomes 21 and 9.
        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $warehouse->id,
                'initial_qty_loaded_ball' => 30,
                'stores' => [$store->id],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $deliveryId = $created['id'],
            'product_id' => $big->id,
            'qty_ball' => 21,
        ]);

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $deliveryId,
            'product_id' => $small->id,
            'qty_ball' => 9,
        ]);

        $this->assertSame('30.00', $created['initial_qty_loaded_ball']);
    }

    public function test_a_plan_with_nothing_to_derive_a_load_from_is_refused(): void
    {
        // No breakdown, no total, and a store with no freezer: there is no way
        // to know what the van should carry, so the plan is refused rather than
        // invented.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', [
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => $this->stockedWarehouse()->id,
                'stores' => [Store::factory()->create()->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loaded_items']);
    }

    public function test_the_load_must_add_up_to_initial_qty_loaded_ball(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', $this->payload(
                $this->stockedWarehouse(),
                [['product_id' => $product->id, 'qty_ball' => 5]],
                30
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loaded_items'])
            ->assertJsonPath('loaded_items_total', 5);
    }

    public function test_a_product_cannot_be_listed_twice_in_the_load(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', $this->payload(
                $this->stockedWarehouse(),
                [
                    ['product_id' => $product->id, 'qty_ball' => 10],
                    ['product_id' => $product->id, 'qty_ball' => 20],
                ],
                30
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loaded_items']);
    }

    public function test_the_load_is_stored_per_product_and_returned_with_the_delivery(): void
    {
        $big = Product::factory()->create();
        $small = Product::factory()->create();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', $this->payload(
                $this->stockedWarehouse(),
                [
                    ['product_id' => $big->id, 'qty_ball' => 20],
                    ['product_id' => $small->id, 'qty_ball' => 10],
                ],
                30
            ))
            ->assertCreated()
            ->assertJsonCount(2, 'data.loaded_items')
            ->json('data.id');

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $deliveryId,
            'product_id' => $big->id,
            'qty_ball' => 20,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/deliveries/'.$deliveryId)
            ->assertOk()
            ->assertJsonCount(2, 'data.loaded_items')
            ->assertJsonPath('data.loaded_items.0.product.id', $big->id)
            ->assertJsonPath('data.loaded_items.0.qty_ball', '20.00');
    }

    public function test_updating_the_load_keeps_it_in_step_with_the_total(): void
    {
        $product = Product::factory()->create();

        $deliveryId = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/deliveries', $this->payload($this->stockedWarehouse(), [
                ['product_id' => $product->id, 'qty_ball' => 30],
            ], 30))
            ->assertCreated()
            ->json('data.id');

        // Raising the headline figure without touching the breakdown would
        // leave the two views of the load disagreeing, so it is refused.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/deliveries/'.$deliveryId, ['initial_qty_loaded_ball' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loaded_items']);

        // Moving the total and the breakdown together is accepted.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/deliveries/'.$deliveryId, [
                'initial_qty_loaded_ball' => 40,
                'loaded_items' => [['product_id' => $product->id, 'qty_ball' => 40]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('delivery_load_items', [
            'delivery_id' => $deliveryId,
            'product_id' => $product->id,
            'qty_ball' => 40,
        ]);
    }

    public function test_editing_a_plan_without_a_breakdown_is_not_blocked(): void
    {
        // A plan drafted before loads were split has a headline figure but no
        // breakdown, the way every run already in the database does.
        $delivery = Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => User::factory()->driver()->create()->id,
            'vehicle_id' => Vehicle::factory()->create()->id,
            'warehouse_id' => $this->stockedWarehouse()->id,
            'initial_qty_loaded_ball' => 30,
            'status' => 'DRAFT',
        ]);

        // Changing something that is not the load must still work.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/deliveries/'.$delivery->id, ['notes' => 'antar pagi'])
            ->assertOk();

        // Moving the headline figure with no breakdown to match it is refused.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/deliveries/'.$delivery->id, ['initial_qty_loaded_ball' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loaded_items']);
    }
}
