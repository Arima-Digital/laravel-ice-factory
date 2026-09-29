<?php

namespace Tests\Feature\MasterData;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverListTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_only_users_with_the_driver_role(): void
    {
        User::factory()->admin()->create();
        User::factory()->warehouse()->create();
        User::factory()->driver()->count(2)->create();

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/drivers');

        $response->assertOk()
            ->assertJsonPath('message', 'Drivers retrieved successfully')
            ->assertJsonPath('count', 2);

        $this->assertEquals(
            ['DRIVER', 'DRIVER'],
            collect($response->json('data'))->pluck('role')->all()
        );
    }

    public function test_list_returns_only_the_fields_needed_for_a_dropdown(): void
    {
        User::factory()->driver()->create(['username' => 'budi01', 'email' => 'budi@icefactory.local']);

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/drivers');

        $response->assertOk()
            ->assertJsonPath('data.0.username', 'budi01')
            ->assertJsonPath('data.0.role', 'DRIVER');

        $driver = $response->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'role', 'username'],
            array_keys($driver)
        );
    }

    public function test_list_never_exposes_email_or_password_hash(): void
    {
        User::factory()->driver()->create(['email' => 'budi@icefactory.local']);

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/drivers');

        $response->assertOk()
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.password_hash')
            ->assertJsonMissingPath('data.0.created_at');
    }

    public function test_warehouse_can_read_the_driver_list(): void
    {
        User::factory()->driver()->create();

        $this->actingAs(User::factory()->warehouse()->create(), 'sanctum')
            ->getJson('/api/drivers')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_list_is_empty_when_there_is_no_driver(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/drivers')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('count', 0);
    }
}
