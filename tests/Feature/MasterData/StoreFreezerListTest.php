<?php

namespace Tests\Feature\MasterData;

use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreFreezerListTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_every_freezer_of_a_store_in_one_call(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->ice10()->create();

        Freezer::factory()->count(3)->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers")
            ->assertOk()
            ->assertJsonPath('message', 'Freezers retrieved successfully')
            ->assertJsonPath('data.store.id', $store->id)
            ->assertJsonPath('data.store.code', $store->code)
            ->assertJsonPath('count', 3)
            ->assertJsonCount(3, 'data.freezers');
    }

    public function test_each_freezer_carries_its_own_estimate(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->ice10()->create();

        Freezer::factory()->withReadings(weightKg: 65.5)->create([
            'store_id' => $store->id,
            'tare_weight_kg' => 45.5,
            'max_capacity_ball' => 10,
        ]);

        $freezer = Freezer::factory()->withReadings(weightKg: 45.5)->create([
            'store_id' => $store->id,
            'tare_weight_kg' => 45.5,
            'max_capacity_ball' => 8,
        ]);

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers");

        $response->assertOk();

        $this->assertEquals(2.0, $response->json('data.freezers.0.estimated_stock_ball'));
        $this->assertEquals(8.0, $response->json('data.freezers.0.suggested_delivery_ball'));
        $this->assertEquals(0.0, $response->json('data.freezers.1.estimated_stock_ball'));
        $this->assertEquals(8.0, $response->json('data.freezers.1.suggested_delivery_ball'));

        $this->assertNotNull($freezer->id);
    }

    public function test_freezer_without_sensor_data_suggests_a_full_restock(): void
    {
        $store = Store::factory()->create();

        Freezer::factory()->withoutSensorData()->create([
            'store_id' => $store->id,
            'max_capacity_ball' => 10,
        ]);

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers");

        $response->assertOk()
            ->assertJsonPath('data.freezers.0.last_seen_at', null)
            ->assertJsonPath('data.freezers.0.estimated_stock_ball', 0)
            ->assertJsonPath('data.freezers.0.suggested_delivery_ball', 10);
    }

    public function test_does_not_return_freezers_of_another_store(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();

        Freezer::factory()->create(['store_id' => $store->id]);
        Freezer::factory()->create(['store_id' => $otherStore->id]);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.freezers.0.store_id', $store->id);
    }

    public function test_returns_an_empty_list_when_the_store_has_no_freezer(): void
    {
        $store = Store::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers")
            ->assertOk()
            ->assertJsonPath('data.freezers', [])
            ->assertJsonPath('count', 0);
    }

    public function test_returns_404_for_an_unknown_store(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/stores/999999/freezers')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Store not found');
    }

    public function test_driver_can_read_the_freezer_list_of_a_store(): void
    {
        $store = Store::factory()->create();
        Freezer::factory()->count(2)->create(['store_id' => $store->id]);

        $this->actingAs(User::factory()->driver()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers")
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_warehouse_can_read_the_freezer_list_of_a_store(): void
    {
        $store = Store::factory()->create();
        Freezer::factory()->create(['store_id' => $store->id]);

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->getJson("/api/stores/{$store->id}/freezers")
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_requires_a_token(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/stores/{$store->id}/freezers")->assertStatus(401);
    }
}
