<?php

namespace Tests\Feature\MasterData;

use App\Models\Freezer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One ball equals 10 kg, so a product is measured in fractions of a ball and a
 * freezer of any product size can share the same numeric stock columns.
 */
class BallUnitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function freezerWith(float $capacity, float $tare, ?float $reading): Freezer
    {
        return Freezer::factory()->create([
            'store_id' => Store::factory()->create()->id,
            'max_capacity_ball' => $capacity,
            'tare_weight_kg' => $tare,
            'last_weight_kg' => $reading,
            'last_seen_at' => now(),
        ]);
    }

    public function test_ball_kg_is_ten(): void
    {
        $this->assertSame(10, Freezer::BALL_KG);
    }

    public function test_ten_kilogram_product_counts_as_exactly_one_ball(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 60);

        $this->assertSame(1.0, $freezer->estimated_stock_ball);
        $this->assertSame(1.0, $product->ball_equivalent);
    }

    public function test_fifteen_kilogram_product_counts_as_one_and_a_half_ball(): void
    {
        $product = Product::factory()->create(['weight_kg' => 15]);
        $freezer = $this->freezerWith(10, 50, 65);

        $this->assertSame(1.5, $freezer->estimated_stock_ball);
        $this->assertSame(1.5, $product->ball_equivalent);
    }

    public function test_five_kilogram_product_counts_as_half_a_ball(): void
    {
        $product = Product::factory()->create(['weight_kg' => 5]);
        $freezer = $this->freezerWith(10, 50, 55);

        $this->assertSame(0.5, $freezer->estimated_stock_ball);
        $this->assertSame(0.5, $product->ball_equivalent);
    }

    public function test_stock_is_reported_as_measured_and_not_rounded(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 53.7);

        // 3.7 kg of ice is 0.37 ball. The sensor is not precise enough for that
        // to be rounded away, and the driver weighs the freezer anyway.
        $this->assertSame(0.37, $freezer->estimated_stock_ball);
    }

    public function test_fractional_capacity_is_stored_and_returned(): void
    {
        $product = Product::factory()->create(['weight_kg' => 15]);
        $freezer = $this->freezerWith(7.5, 50, 50);

        $this->assertDatabaseHas('freezers', ['max_capacity_ball' => 7.5]);
        $this->assertSame(7.5, (float) $freezer->fresh()->max_capacity_ball);
    }

    public function test_fractional_capacity_survives_the_api(): void
    {
        $product = Product::factory()->create(['weight_kg' => 15]);
        $freezer = $this->freezerWith(7.5, 50, 57.5);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}");

        // Decimal columns are cast to strings so trailing zeros survive, which
        // is what keeps 7.5 from being displayed as 7. The frontend must parse
        // these as numbers, not concatenate them.
        $response->assertOk()
            ->assertJsonPath('data.max_capacity_ball', '7.50')
            ->assertJsonPath('data.estimated_stock_ball', 0.75)
            ->assertJsonPath('data.suggested_delivery_ball', 6.75);
    }

    public function test_suggestion_fills_the_gap_up_to_capacity(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 52);

        $this->assertSame(0.2, $freezer->estimated_stock_ball);
        $this->assertSame(9.8, $freezer->suggested_delivery_ball);
    }

    public function test_freezer_without_sensor_reading_reports_no_stock(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, null);

        $this->assertSame(0.0, $freezer->estimated_stock_ball);
    }

    public function test_unmeasured_freezer_never_suggests_more_than_its_capacity(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, null);

        // This used to divide a null reading by the product weight, which made
        // the net weight negative and the suggestion larger than the capacity.
        $this->assertLessThanOrEqual(10, $freezer->suggested_delivery_ball);
        $this->assertSame(10.0, $freezer->suggested_delivery_ball);
    }

    public function test_estimated_stock_never_exceeds_capacity(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(3, 50, 200);

        $this->assertSame(3.0, $freezer->estimated_stock_ball);
        $this->assertSame(0.0, $freezer->suggested_delivery_ball);
    }

    public function test_estimated_stock_never_goes_negative(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 20);

        $this->assertSame(0.0, $freezer->estimated_stock_ball);
    }

    public function test_suggestion_endpoint_agrees_with_the_freezer_payload(): void
    {
        $product = Product::factory()->create(['weight_kg' => 15]);
        $freezer = $this->freezerWith(7.5, 50, 57.5);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}/suggestion");

        $response->assertOk()
            ->assertJsonPath('data.suggestion.estimated_stock_ball', 0.75)
            ->assertJsonPath('data.suggestion.max_capacity_ball', 7.5)
            ->assertJsonPath('data.suggestion.suggested_delivery_ball', 6.75)
            ->assertJsonPath('data.suggestion.ball_kg', 10);
    }

    public function test_suggestion_endpoint_clamps_an_unmeasured_freezer(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, null);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/freezers/{$freezer->id}/suggestion");

        $response->assertOk()
            ->assertJsonPath('data.suggestion.estimated_stock_ball', 0)
            ->assertJsonPath('data.suggestion.suggested_delivery_ball', 10)
            ->assertJsonPath('data.suggestion.confidence', 'LOW');
    }

    public function test_product_payload_exposes_the_ball_equivalent(): void
    {
        Product::factory()->create(['weight_kg' => 15]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/products');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['code', 'weight_kg', 'ball_equivalent']],
            ])
            ->assertJsonPath('data.0.ball_equivalent', 1.5);
    }

    public function test_weight_cannot_change_once_the_product_has_been_delivered(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 55);

        DB::table('delivery_items')->insert([
            'delivery_id' => DB::table('deliveries')->insertGetId([
                'delivery_date' => now()->toDateString(),
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => Warehouse::factory()->create()->id,
                'initial_qty_loaded_ball' => 100,
                'status' => 'DRAFT',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'store_id' => $freezer->store_id,
            'freezer_id' => $freezer->id,
            'product_id' => $product->id,
            'confirmed_stock_before_ball' => 0.5,
            'delivered_qty_ball' => 5,
            'visited_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['weight_kg' => 15]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.weight_kg.0',
                'Locked: 1 delivery record(s) and 0 sale(s) already use this weight.');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'weight_kg' => 10]);
    }

    public function test_weight_cannot_change_once_the_product_has_productions(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $warehouse = Warehouse::factory()->create();
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/productions', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'production_date' => now()->toDateString(),
                'qty_produced_ball' => 100,
                'qty_reject_ball' => 5,
            ])
            ->assertCreated();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['weight_kg' => 5]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.weight_kg.0', 'Locked: this product already has production records.');
    }

    public function test_weight_can_still_change_while_the_product_is_unused(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", ['weight_kg' => 5])
            ->assertOk()
            ->assertJsonPath('data.weight_kg', '5.00')
            ->assertJsonPath('data.ball_equivalent', 0.5);
    }

    public function test_resending_the_same_weight_is_allowed(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $this->freezerWith(10, 50, 55);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/products/{$product->id}", [
                'name' => 'Es Kristal 10 KG Baru',
                'weight_kg' => 10,
            ])
            ->assertOk();
    }

    public function test_deleting_a_product_in_use_returns_a_clear_error(): void
    {
        $product = Product::factory()->create(['weight_kg' => 10]);
        $freezer = $this->freezerWith(10, 50, 55);

        // A freezer no longer holds a product of its own, so "in use" means
        // the product has been delivered into a freezer and that history
        // would be erased by a cascading delete.
        DB::table('delivery_items')->insert([
            'delivery_id' => DB::table('deliveries')->insertGetId([
                'delivery_date' => now()->toDateString(),
                'driver_id' => User::factory()->driver()->create()->id,
                'vehicle_id' => Vehicle::factory()->create()->id,
                'warehouse_id' => Warehouse::factory()->create()->id,
                'initial_qty_loaded_ball' => 100,
                'status' => 'DRAFT',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'store_id' => $freezer->store_id,
            'freezer_id' => $freezer->id,
            'product_id' => $product->id,
            'confirmed_stock_before_ball' => 1,
            'delivered_qty_ball' => 5,
            'visited_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Product cannot be deleted because it is still referenced')
            ->assertJsonPath('delivery_count', 1)
            ->assertJsonPath('sale_count', 0);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_deleting_an_unused_product_still_works(): void
    {
        $product = Product::factory()->create(['weight_kg' => 5]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/products/{$product->id}")
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
