<?php

namespace Tests\Feature\Auth;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roles are enforced by the token, not by the URL, so a DRIVER calling a
 * WAREHOUSE endpoint gets 403 and never 200.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'username' => 'budi01',
                'email' => 'budi@icefactory.local',
                'password' => 'password123',
                'role' => 'DRIVER',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.username', 'budi01')
            ->assertJsonPath('data.role', 'DRIVER');
    }

    public function test_warehouse_cannot_create_a_user(): void
    {
        $warehouse = User::factory()->warehouse()->create();

        $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/users', [
                'username' => 'budi01',
                'password' => 'password123',
                'role' => 'DRIVER',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden')
            ->assertJsonPath('details.your_role', 'WAREHOUSE');
    }

    public function test_admin_is_the_only_role_allowed_on_the_user_resource(): void
    {
        $warehouse = User::factory()->warehouse()->create();
        $driver = User::factory()->driver()->create();

        $this->actingAs($warehouse, 'sanctum')->getJson('/api/users')->assertStatus(403);
        $this->actingAs($driver, 'sanctum')->getJson('/api/users')->assertStatus(403);
    }

    public function test_driver_cannot_list_drivers(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/drivers')
            ->assertStatus(403)
            ->assertJsonPath('details.required_roles', 'ADMIN, WAREHOUSE');
    }

    public function test_driver_cannot_read_products(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver, 'sanctum')->getJson('/api/products')->assertStatus(403);
    }

    public function test_driver_can_read_stores(): void
    {
        $driver = User::factory()->driver()->create();
        Store::factory()->count(2)->create();

        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/stores')
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_driver_cannot_write_a_store(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/stores', [
                'code' => 'RSA-001',
                'name' => 'Toko Rapi',
                'owner_name' => 'Joko',
                'phone' => '081234567890',
                'address' => 'Jl. Kebon Sirih No. 12',
            ])
            ->assertStatus(403);
    }

    public function test_warehouse_can_read_master_data_but_only_admin_can_write_it(): void
    {
        $warehouse = User::factory()->warehouse()->create();
        $product = Product::factory()->ice10()->create();

        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/products', [
                'code' => 'ICE-20',
                'name' => 'Es Kristal 20 KG',
                'weight_kg' => 20,
                'selling_price' => 18000,
            ])
            ->assertStatus(403);

        $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/vehicles', [
                'code' => 'VH-001',
                'plate_number' => 'B2345XYZ',
                'name' => 'Avanza Putih',
            ])
            ->assertStatus(403);

        $this->actingAs($warehouse, 'sanctum')
            ->postJson('/api/warehouses', [
                'code' => 'WH-JKT-01',
                'name' => 'Gudang Jakarta Utara',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('products', ['code' => 'ICE-10']);
        $this->assertDatabaseMissing('products', ['code' => 'ICE-20']);
    }

    public function test_protected_endpoints_reject_anonymous_requests(): void
    {
        $this->getJson('/api/drivers')->assertStatus(401);
        $this->getJson('/api/products')->assertStatus(401);
        $this->getJson('/api/stores')->assertStatus(401);
        $this->getJson('/api/freezers')->assertStatus(401);
    }
}
