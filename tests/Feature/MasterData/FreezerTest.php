<?php

namespace Tests\Feature\MasterData;

use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FreezerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'store_id' => Store::factory()->create()->id,
            'code' => 'FRZ-RSA-001-A',
            'sim_number' => '899012345678901',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 45.5,
        ], $overrides);
    }

    public function test_admin_can_create_a_freezer(): void
    {
        $payload = $this->payload();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Freezer created successfully')
            ->assertJsonPath('data.code', 'FRZ-RSA-001-A')
            ->assertJsonPath('data.max_capacity_ball', '10.00')
            ->assertJsonPath('data.store.id', $payload['store_id'])
            ->assertJsonPath('data.estimated_stock_ball', 0)
            ->assertJsonPath('data.suggested_delivery_ball', 10)
            // A freezer can hold more than one product, so it is not created
            // with one and starts with an empty list.
            ->assertJsonPath('data.products', []);

        $this->assertDatabaseHas('freezers', ['code' => 'FRZ-RSA-001-A']);
    }

    public function test_create_rejects_a_duplicate_code(): void
    {
        Freezer::factory()->create(['code' => 'FRZ-RSA-001-A']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonPath('errors.code.0', 'The code has already been taken.');
    }

    public function test_create_rejects_a_capacity_below_one_tenth(): void
    {
        // 0.1 ball is 1 kg, the smallest freezer worth registering.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload(['max_capacity_ball' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('errors.max_capacity_ball.0', 'The max capacity ball field must be at least 0.1.');
    }

    public function test_create_accepts_a_fractional_capacity(): void
    {
        // A freezer does not have to hold whole 10 kg units.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload(['max_capacity_ball' => 7.5]))
            ->assertStatus(201)
            ->assertJsonPath('data.max_capacity_ball', '7.50');
    }

    public function test_create_rejects_a_store_that_does_not_exist(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload(['store_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('errors.store_id.0', 'The selected store id is invalid.');
    }

    public function test_create_rejects_a_tare_weight_below_the_minimum(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload(['tare_weight_kg' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('errors.tare_weight_kg.0', 'The tare weight kg field must be at least 0.1.');
    }

    public function test_iot_fields_sent_by_the_client_are_ignored(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/freezers', $this->payload([
                'last_weight_kg' => 99,
                'last_temperature_c' => 30,
                'last_door_status' => 'OPEN',
                'last_seen_at' => '2026-01-01 00:00:00',
            ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.last_weight_kg', null)
            ->assertJsonPath('data.last_temperature_c', null)
            ->assertJsonPath('data.last_seen_at', null)
            ->assertJsonPath('data.last_door_status', 'CLOSED');

        $this->assertDatabaseHas('freezers', [
            'code' => 'FRZ-RSA-001-A',
            'last_weight_kg' => null,
            'last_temperature_c' => null,
            'last_seen_at' => null,
        ]);
    }

    public function test_estimated_stock_is_derived_from_the_latest_sensor_reading(): void
    {
        $freezer = Freezer::factory()
            ->withReadings(weightKg: 65.5, temperatureC: -18.4)
            ->create([
                'max_capacity_ball' => 10,
                'tare_weight_kg' => 45.5,
            ]);

        // (65.5 - 45.5) / 10 = 2 balls
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}")
            ->assertOk()
            ->assertJsonPath('data.last_temperature_c', '-18.40')
            ->assertJsonPath('data.last_door_status', 'CLOSED');

        $this->assertEquals(2.0, $freezer->fresh()->estimated_stock_ball);
        $this->assertEquals(8.0, $freezer->fresh()->suggested_delivery_ball);
    }

    public function test_estimated_stock_is_clamped_to_the_freezer_capacity(): void
    {
        $freezer = Freezer::factory()
            ->withReadings(weightKg: 200)
            ->create([
                'max_capacity_ball' => 10,
                'tare_weight_kg' => 45.5,
            ]);

        $this->assertEquals(10.0, $freezer->fresh()->estimated_stock_ball);
        $this->assertEquals(0.0, $freezer->fresh()->suggested_delivery_ball);
    }

    public function test_index_can_be_filtered_by_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        Freezer::factory()->count(2)->create(['store_id' => $storeA->id]);
        Freezer::factory()->create(['store_id' => $storeB->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers?store_id={$storeA->id}")
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_index_can_be_filtered_by_delivered_product(): void
    {
        // product_id filters on what has actually been delivered, since a
        // freezer no longer holds a single product of its own.
        $store = Store::factory()->create();
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15]);

        $withIce10 = Freezer::factory()->create(['store_id' => $store->id]);
        $withIce15 = Freezer::factory()->create(['store_id' => $store->id]);

        DB::table('delivery_items')->insert([
            ['delivery_id' => $this->createDelivery(), 'store_id' => $store->id, 'freezer_id' => $withIce10->id, 'product_id' => $ice10->id, 'confirmed_stock_before_ball' => 1, 'delivered_qty_ball' => 5, 'visited_at' => now()],
            ['delivery_id' => $this->createDelivery(), 'store_id' => $store->id, 'freezer_id' => $withIce15->id, 'product_id' => $ice15->id, 'confirmed_stock_before_ball' => 1, 'delivered_qty_ball' => 3, 'visited_at' => now()],
        ]);

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/freezers?product_id={$ice10->id}")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $withIce10->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/freezers?product_id={$ice15->id}")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $withIce15->id);
    }

    public function test_a_freezer_can_hold_more_than_one_product(): void
    {
        $store = Store::factory()->create();
        $ice10 = Product::factory()->ice10()->create();
        $ice15 = Product::factory()->create(['code' => 'ICE-15', 'weight_kg' => 15]);
        $freezer = Freezer::factory()->create(['store_id' => $store->id]);

        DB::table('delivery_items')->insert([
            ['delivery_id' => $this->createDelivery(), 'store_id' => $store->id, 'freezer_id' => $freezer->id, 'product_id' => $ice10->id, 'confirmed_stock_before_ball' => 1, 'delivered_qty_ball' => 5, 'visited_at' => now()],
            ['delivery_id' => $this->createDelivery(), 'store_id' => $store->id, 'freezer_id' => $freezer->id, 'product_id' => $ice15->id, 'confirmed_stock_before_ball' => 1, 'delivered_qty_ball' => 3, 'visited_at' => now()],
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}")
            ->assertOk()
            ->assertJsonPath('data.products.0.code', 'ICE-10')
            ->assertJsonPath('data.products.1.code', 'ICE-15');
    }

    public function test_show_returns_404_for_an_unknown_freezer(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/freezers/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Freezer not found');
    }

    public function test_admin_can_update_a_freezer(): void
    {
        $freezer = Freezer::factory()->create(['code' => 'FRZ-RSA-001-A', 'max_capacity_ball' => 10]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/freezers/{$freezer->id}", [
                'max_capacity_ball' => 12,
                'tare_weight_kg' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Freezer updated successfully')
            ->assertJsonPath('data.max_capacity_ball', '12.00')
            ->assertJsonPath('data.code', 'FRZ-RSA-001-A');

        $this->assertDatabaseHas('freezers', [
            'id' => $freezer->id,
            'max_capacity_ball' => 12,
            'tare_weight_kg' => 50,
        ]);
    }

    public function test_admin_can_delete_a_freezer_without_history(): void
    {
        $freezer = Freezer::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/freezers/{$freezer->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Freezer deleted successfully');

        $this->assertDatabaseMissing('freezers', ['id' => $freezer->id]);
    }

    public function test_delete_is_blocked_when_a_delivery_item_references_the_freezer(): void
    {
        $freezer = Freezer::factory()->create();
        $deliveryId = $this->createDelivery();

        DB::table('delivery_items')->insert([
            'delivery_id' => $deliveryId,
            'store_id' => $freezer->store_id,
            'freezer_id' => $freezer->id,
            'product_id' => Product::factory()->ice10()->create()->id,
            'confirmed_stock_before_ball' => 2,
            'delivered_qty_ball' => 8,
            'visited_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/freezers/{$freezer->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Freezer cannot be deleted because it has transaction history')
            ->assertJsonPath('details.delivery_items', 1)
            ->assertJsonPath('details.sales', 0);

        $this->assertDatabaseHas('freezers', ['id' => $freezer->id]);
    }

    public function test_delete_is_blocked_when_a_sale_references_the_freezer(): void
    {
        $freezer = Freezer::factory()->create();

        DB::table('sales')->insert([
            'store_id' => $freezer->store_id,
            'freezer_id' => $freezer->id,
            'delivery_item_id' => null,
            'qty_ball' => 3,
            'unit_price' => 10000,
            'total_amount' => 30000,
            'status' => 'CONFIRMED',
            'sold_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/freezers/{$freezer->id}")
            ->assertStatus(422)
            ->assertJsonPath('details.sales', 1)
            ->assertJsonPath('details.delivery_items', 0);

        $this->assertDatabaseHas('freezers', ['id' => $freezer->id]);
    }

    public function test_warehouse_can_read_but_not_write_freezers(): void
    {
        $warehouse = User::factory()->warehouse()->create();
        $freezer = Freezer::factory()->create();

        $this->actingAs($warehouse, 'sanctum')->getJson('/api/freezers')->assertOk();
        $this->actingAs($warehouse, 'sanctum')->getJson("/api/freezers/{$freezer->id}")->assertOk();
        $this->actingAs($warehouse, 'sanctum')->postJson('/api/freezers', $this->payload())->assertStatus(403);
        $this->actingAs($warehouse, 'sanctum')->deleteJson("/api/freezers/{$freezer->id}")->assertStatus(403);
    }

    public function test_driver_has_no_access_to_the_freezer_resource(): void
    {
        $driver = User::factory()->driver()->create();
        $freezer = Freezer::factory()->create();

        $this->actingAs($driver, 'sanctum')->getJson('/api/freezers')->assertStatus(403);
        $this->actingAs($driver, 'sanctum')->getJson("/api/freezers/{$freezer->id}")->assertStatus(403);
    }

    public function test_removed_undocumented_routes_are_gone(): void
    {
        $admin = $this->admin();
        $user = User::factory()->driver()->create();
        $freezer = Freezer::factory()->create();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/users/{$user->id}", ['role' => 'ADMIN'])->assertStatus(405);
        $this->actingAs($admin, 'sanctum')->postJson('/api/users/register', [])->assertStatus(405);
        $this->actingAs($admin, 'sanctum')->patchJson("/admin/productions/{$freezer->id}", [])->assertStatus(405);
    }

    private function createDelivery(): int
    {
        return DB::table('deliveries')->insertGetId([
            'delivery_date' => now()->toDateString(),
            'driver_id' => User::factory()->driver()->create()->id,
            'vehicle_id' => \App\Models\Vehicle::factory()->create()->id,
            'warehouse_id' => \App\Models\Warehouse::factory()->create()->id,
            'initial_qty_loaded_ball' => 100,
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
