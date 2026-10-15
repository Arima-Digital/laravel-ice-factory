<?php

namespace Tests\Feature\Delivery;

use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreezerLoadConfigTest extends TestCase
{
    use RefreshDatabase;

    private function freezer(): Freezer
    {
        return Freezer::factory()->create(['store_id' => Store::factory()->create()->id]);
    }

    private function ice10(): Product
    {
        return Product::factory()->create(['code' => 'ICE-10', 'name' => 'Es Kristal 10 KG', 'weight_kg' => 10]);
    }

    private function ice5(): Product
    {
        return Product::factory()->create(['code' => 'ICE-5', 'name' => 'Es Kristal 5 KG', 'weight_kg' => 5, 'selling_price' => 5000]);
    }

    public function test_an_unset_config_falls_back_to_the_thirty_seventy_default(): void
    {
        $freezer = $this->freezer();
        $ice10 = $this->ice10();
        $ice5 = $this->ice5();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}/load-config")
            ->assertOk()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.note', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $ice10->id)
            ->assertJsonPath('data.items.0.percentage', 70)
            ->assertJsonPath('data.items.1.product_id', $ice5->id)
            ->assertJsonPath('data.items.1.percentage', 30);
    }

    public function test_a_driver_can_read_the_config(): void
    {
        $freezer = $this->freezer();
        $this->ice10();
        $this->ice5();

        $this->actingAs(User::factory()->driver()->create(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}/load-config")
            ->assertOk();
    }

    public function test_the_config_endpoint_404s_for_an_unknown_freezer(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/freezers/99999/load-config')
            ->assertStatus(404);
    }

    public function test_an_admin_can_override_the_mix_and_add_a_note(): void
    {
        $freezer = $this->freezer();
        $ice10 = $this->ice10();
        $ice5 = $this->ice5();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->putJson("/api/freezers/{$freezer->id}/load-config", [
                'note' => 'Owner cuma mau 10 kg',
                'items' => [
                    ['product_id' => $ice5->id, 'percentage' => 0],
                    ['product_id' => $ice10->id, 'percentage' => 100],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.note', 'Owner cuma mau 10 kg')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('freezer_product_settings', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice10->id,
            'percentage' => 100,
        ]);

        $this->assertDatabaseHas('freezers', [
            'id' => $freezer->id,
            'load_note' => 'Owner cuma mau 10 kg',
        ]);
    }

    public function test_putting_the_config_replaces_the_previous_mix(): void
    {
        $freezer = $this->freezer();
        $ice10 = $this->ice10();
        $ice5 = $this->ice5();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/freezers/{$freezer->id}/load-config", [
            'items' => [
                ['product_id' => $ice10->id, 'percentage' => 70],
                ['product_id' => $ice5->id, 'percentage' => 30],
            ],
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson("/api/freezers/{$freezer->id}/load-config", [
            'items' => [
                ['product_id' => $ice10->id, 'percentage' => 100],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('freezer_product_settings', [
            'freezer_id' => $freezer->id,
            'product_id' => $ice5->id,
        ]);
    }

    public function test_shares_that_do_not_add_up_to_a_hundred_are_refused(): void
    {
        $freezer = $this->freezer();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->putJson("/api/freezers/{$freezer->id}/load-config", [
                'items' => [
                    ['product_id' => $this->ice10()->id, 'percentage' => 50],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('total', 50);
    }

    public function test_the_same_product_cannot_be_listed_twice(): void
    {
        $freezer = $this->freezer();
        $ice10 = $this->ice10();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->putJson("/api/freezers/{$freezer->id}/load-config", [
                'items' => [
                    ['product_id' => $ice10->id, 'percentage' => 50],
                    ['product_id' => $ice10->id, 'percentage' => 50],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_only_an_admin_can_change_the_config(): void
    {
        $freezer = $this->freezer();

        $this->actingAs(User::factory()->driver()->create(), 'sanctum')
            ->putJson("/api/freezers/{$freezer->id}/load-config", [
                'items' => [
                    ['product_id' => $this->ice10()->id, 'percentage' => 100],
                ],
            ])
            ->assertStatus(403);
    }
}
