<?php

namespace Tests\Feature\Delivery;

use App\Models\Freezer;
use App\Models\Production;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliverySuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The BRD asks the warehouse to open a "Smart Delivery" screen before a plan
 * exists and work down three buckets: high priority when a freezer reads empty,
 * medium at 3-5 ball, low above that.
 *
 * The thresholds are reproduced exactly, including the gap they leave. A freezer
 * at 1 or 2 ball falls outside every bucket the BRD names, and a freezer that
 * has never reported is not an empty freezer. Both are separated out rather than
 * rounded into a neighbour, because either mistake puts a shop in the urgent
 * list for a reason that has nothing to do with its stock.
 */
class DeliverySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function warehouse(): User
    {
        return User::factory()->warehouse()->create();
    }

    /**
     * A freezer whose load cell reports a given total weight, so a store's
     * estimated stock is a chosen number rather than a faker's.
     */
    private function freezerReading(Store $store, float $weightKg, float $capacity = 10): Freezer
    {
        return Freezer::factory()->create([
            'store_id' => $store->id,
            'tare_weight_kg' => 45,
            'max_capacity_ball' => $capacity,
            'last_weight_kg' => $weightKg,
            'last_temperature_c' => -18,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * A freezer that has never reported anything.
     */
    private function freezerSilent(Store $store, float $capacity = 10): Freezer
    {
        return Freezer::factory()->withoutSensorData()->create([
            'store_id' => $store->id,
            'tare_weight_kg' => 45,
            'max_capacity_ball' => $capacity,
        ]);
    }

    public function test_an_empty_freezer_is_high_priority(): void
    {
        $store = Store::factory()->create();
        // 45 kg tare, so 45 kg total is an empty freezer.
        $this->freezerReading($store, 45);

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.buckets.HIGH.0.store_id', $store->id)
            ->assertJsonPath('data.buckets.HIGH.0.estimated_stock_ball', 0)
            ->assertJsonPath('data.buckets.HIGH.0.suggested_delivery_ball', 10);
    }

    public function test_three_to_five_ball_is_medium_priority(): void
    {
        // 3 ball = 45 + 30 kg.
        $medium = Store::factory()->create();
        $this->freezerReading($medium, 75);

        $buckets = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.buckets');

        $this->assertSame($medium->id, $buckets['MEDIUM'][0]['store_id']);
        $this->assertEqualsWithDelta(3.0, $buckets['MEDIUM'][0]['estimated_stock_ball'], 0.01);
        $this->assertEmpty($buckets['HIGH']);
    }

    public function test_more_than_five_ball_is_low_priority(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45 + 60); // 6 ball

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.buckets.LOW.0.store_id', $store->id)
            ->assertJsonPath('data.buckets.LOW.0.estimated_stock_ball', 6)
            ->assertJsonPath('data.buckets.LOW.0.suggested_delivery_ball', 4);
    }

    public function test_one_or_two_ball_falls_outside_every_bracket_the_brd_names(): void
    {
        $one = Store::factory()->create();
        $this->freezerReading($one, 45 + 10); // 1 ball

        $two = Store::factory()->create();
        $this->freezerReading($two, 45 + 20); // 2 ball

        $response = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk();

        $buckets = $response->json('data.buckets');

        // The BRD jumps from 0 straight to 3-5, so these two are reported
        // rather than forced into a bucket that would misdescribe them.
        $this->assertEmpty($buckets['HIGH']);
        $this->assertEmpty($buckets['MEDIUM']);
        $this->assertCount(2, $buckets['UNKNOWN']);
        $this->assertEqualsCanonicalizing(
            [$one->id, $two->id],
            array_column($buckets['UNKNOWN'], 'store_id')
        );
    }

    public function test_a_freezer_that_never_reported_is_not_treated_as_empty(): void
    {
        $store = Store::factory()->create();
        $this->freezerSilent($store);

        $response = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk();

        // A dead sensor means the stock is unknown, and an unknown shop must not
        // sit at the top of the urgent list.
        $this->assertEmpty($response->json('data.buckets.HIGH'));
        $response->assertJsonPath('data.buckets.UNKNOWN.0.store_id', $store->id)
            ->assertJsonPath('data.buckets.UNKNOWN.0.sensors_offline', 1)
            ->assertJsonPath('data.buckets.UNKNOWN.0.confidence', 'LOW');
    }

    public function test_a_store_keeps_its_weakest_sensor_confidence(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45 + 60, capacity: 10);      // reported
        $this->freezerSilent($store, capacity: 10);                // never reported

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.buckets.UNKNOWN.0.confidence', 'LOW')
            ->assertJsonPath('data.buckets.UNKNOWN.0.sensors_online', 1)
            ->assertJsonPath('data.buckets.UNKNOWN.0.sensors_offline', 1);
    }

    public function test_the_suggestion_for_a_store_is_the_sum_of_its_freezers(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45, capacity: 6);    // empty, wants 6
        $this->freezerReading($store, 45 + 20, capacity: 4); // 2 ball, wants 2

        // Two freezers, so 0 + 2 ball, and 2 lands in the gap the BRD leaves
        // open. The quantities are still the point: one figure per store, not
        // one per freezer.
        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.buckets.UNKNOWN.0.estimated_stock_ball', 2)
            ->assertJsonPath('data.buckets.UNKNOWN.0.suggested_delivery_ball', 8)
            ->assertJsonPath('data.buckets.UNKNOWN.0.freezer_count', 2);
    }

    public function test_the_emptiest_shops_come_first(): void
    {
        $fourBall = Store::factory()->create();
        $this->freezerReading($fourBall, 45 + 40);

        $threeBall = Store::factory()->create();
        $this->freezerReading($threeBall, 45 + 30);

        $order = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.buckets.MEDIUM');

        // Both sit in MEDIUM, and the emptier one is listed first, so the
        // warehouse can work down the list without sorting it themselves.
        $this->assertSame([$threeBall->id, $fourBall->id], array_column($order, 'store_id'));
    }

    public function test_a_store_with_no_freezers_is_left_out(): void
    {
        Store::factory()->create();
        $withFreezer = Store::factory()->create();
        $this->freezerReading($withFreezer, 45);

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.total_stores', 1)
            ->assertJsonPath('data.buckets.HIGH.0.store_id', $withFreezer->id);
    }

    public function test_suggestions_can_be_limited_to_certain_stores(): void
    {
        $wanted = Store::factory()->create();
        $this->freezerReading($wanted, 45);

        $other = Store::factory()->create();
        $this->freezerReading($other, 45);

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions?store_ids[]='.$wanted->id)
            ->assertOk()
            ->assertJsonPath('data.total_stores', 1)
            ->assertJsonPath('data.buckets.HIGH.0.store_id', $wanted->id);
    }

    public function test_suggestions_can_be_filtered_to_one_tier(): void
    {
        $empty = Store::factory()->create();
        $this->freezerReading($empty, 45);

        $comfortable = Store::factory()->create();
        $this->freezerReading($comfortable, 45 + 60);

        $response = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions?tier=HIGH')
            ->assertOk();

        $response->assertJsonPath('data.buckets.HIGH.0.store_id', $empty->id);
        $this->assertArrayNotHasKey('LOW', $response->json('data.buckets'));
    }

    public function test_an_unknown_tier_is_rejected(): void
    {
        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions?tier=URGENT')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tier');
    }

    public function test_the_warehouse_stock_is_shown_next_to_the_suggestions(): void
    {
        $warehouse = Warehouse::factory()->create();
        Production::factory()->posted()->yielding(40)->create(['warehouse_id' => $warehouse->id]);

        $store = Store::factory()->create();
        $this->freezerReading($store, 45);

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->assertJsonPath('data.warehouse.available_stock_ball', 40)
            ->assertJsonPath('data.buckets.HIGH.0.suggested_delivery_ball', 10);
    }

    public function test_a_suggestion_never_exceeds_what_the_freezer_can_hold(): void
    {
        // A freezer reporting more than its capacity is clamped, so the
        // suggestion does not go negative and turn into credit.
        $store = Store::factory()->create();
        $this->freezerReading($store, 45 + 200, capacity: 5);

        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.buckets.MEDIUM.0.estimated_stock_ball', 5)
            ->assertJsonPath('data.buckets.MEDIUM.0.suggested_delivery_ball', 0)
            ->assertJsonPath('data.total_suggested_ball', 0);
    }

    public function test_a_driver_cannot_open_the_suggestion_screen(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45);

        // Which shops to visit is the warehouse's decision, so this stays out of
        // the driver's hands even though the driver sees the route afterwards.
        $this->actingAs(User::factory()->driver()->create(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertStatus(403);
    }

    public function test_the_tier_thresholds_are_exposed(): void
    {
        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.tier_thresholds.HIGH', 'estimated stock 0')
            ->assertJsonPath('data.tier_thresholds.MEDIUM', 'estimated stock 3-5')
            ->assertJsonPath('data.tier_thresholds.LOW', 'estimated stock over 5');
    }

    /**
     * The 8:00 AM screen at BRD:326-340 is a fixed vertical stack: three named
     * groups in order, each with its own stores and a "Last update" line, and a
     * confidence figure underneath. That ordering is the screen, so it is
     * asserted here rather than left for the front end to hard-code.
     */
    public function test_the_screen_groups_are_ordered_as_the_brd_draws_them(): void
    {
        $rapi = Store::factory()->create(['code' => 'RSA-001', 'name' => 'Toko Rapi']);
        $this->freezerReading($rapi, 45, 10);

        $sejahtera = Store::factory()->create(['code' => 'RSA-002', 'name' => 'Toko Sejahtera']);
        $this->freezerReading($sejahtera, 45, 8);

        $groups = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.groups');

        $this->assertSame(
            ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'],
            array_column($groups, 'tier')
        );

        $high = $groups[0];
        $this->assertSame('🔴 HIGH PRIORITY (Est stock 0)', $high['label']);
        $this->assertSame('estimated stock 0', $high['rule']);
        $this->assertTrue($high['in_brd']);
        $this->assertSame(2, $high['stores_count']);

        // BRD:329-330 writes "RSA-001 (Toko Rapi): Suggest 10 ball", so the code,
        // the name and the quantity all have to reach the screen together.
        $this->assertSame('RSA-001 (Toko Rapi)', $high['stores'][0]['label']);
        $this->assertSame(10, $high['stores'][0]['suggested_delivery_ball']);
        $this->assertSame('RSA-002 (Toko Sejahtera)', $high['stores'][1]['label']);
        $this->assertSame(8, $high['stores'][1]['suggested_delivery_ball']);
        $this->assertSame(18, $high['total_suggested_ball']);
    }

    /**
     * "Last update: 2 hours ago" is printed under the stores, so it has to be
     * readable text and not just a timestamp the front end formats itself.
     */
    public function test_a_group_reports_when_it_was_last_updated(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45, 10);

        $groups = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.groups');

        $high = $groups[0];
        $this->assertNotNull($high['last_update']);
        $this->assertStringContainsString('ago', $high['last_update']);
        $this->assertStringContainsString('ago', $high['stores'][0]['last_update']);
    }

    /**
     * BRD:340 closes the screen with "Confidence: 95% (sensor online 24h)". The
     * BRD never says how to turn a reading into a percentage, so the formula is
     * sent with the number instead of the number being taken on trust.
     */
    public function test_the_screen_reports_a_confidence_figure(): void
    {
        $store = Store::factory()->create();
        $this->freezerReading($store, 45, 10);

        $confidence = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.confidence');

        $this->assertSame(1, $confidence['sensors_reporting']);
        $this->assertSame(0, $confidence['sensors_silent']);
        $this->assertNotEmpty($confidence['formula']);
        $this->assertGreaterThan(50, $confidence['percent']);
    }

    /**
     * A dead sensor cannot claim to be fresh, and a confidence of 95% over a
     * screen where one freezer never reported would be the number doing the
     * most harm.
     */
    public function test_confidence_drops_when_a_freezer_has_never_reported(): void
    {
        $reporting = Store::factory()->create();
        $this->freezerReading($reporting, 45, 10);

        $silent = Store::factory()->create();
        $this->freezerSilent($silent, 10);

        $confidence = $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->json('data.confidence');

        $this->assertSame(1, $confidence['sensors_reporting']);
        $this->assertSame(1, $confidence['sensors_silent']);
        $this->assertStringContainsString('never reported', $confidence['basis']);
        $this->assertLessThan(100, $confidence['percent']);
    }

    /**
     * With nothing to suggest there is no percentage to claim, and inventing one
     * would put a number on an empty screen.
     */
    public function test_confidence_is_null_when_no_freezer_has_reported(): void
    {
        $this->actingAs($this->warehouse(), 'sanctum')
            ->getJson('/api/deliveries/suggestions')
            ->assertOk()
            ->assertJsonPath('data.confidence.percent', null)
            ->assertJsonPath('data.confidence.basis', 'no freezer readings');
    }

    public function test_the_brd_thresholds_map_to_the_right_tier(): void
    {
        $service = app(DeliverySuggestionService::class);

        // Straight from the BRD buckets, plus the two cases they leave open.
        $this->assertSame(DeliverySuggestionService::TIER_HIGH, $service->tierFor(0, true));
        $this->assertSame(DeliverySuggestionService::TIER_MEDIUM, $service->tierFor(3, true));
        $this->assertSame(DeliverySuggestionService::TIER_MEDIUM, $service->tierFor(5, true));
        $this->assertSame(DeliverySuggestionService::TIER_LOW, $service->tierFor(5.01, true));
        $this->assertSame(DeliverySuggestionService::TIER_LOW, $service->tierFor(6, true));
        $this->assertSame(DeliverySuggestionService::TIER_UNKNOWN, $service->tierFor(1, true));
        $this->assertSame(DeliverySuggestionService::TIER_UNKNOWN, $service->tierFor(2, true));
        $this->assertSame(DeliverySuggestionService::TIER_UNKNOWN, $service->tierFor(0, false));
    }
}
