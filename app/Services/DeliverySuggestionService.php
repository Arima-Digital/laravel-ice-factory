<?php

namespace App\Services;

use App\Models\Freezer;
use App\Models\Store;
use App\Models\Warehouse;

/**
 * Which stores look like they need a delivery, and roughly how much.
 *
 * The BRD asks the warehouse for a "Smart Delivery" screen before the plan is
 * built, sorted into three buckets:
 *
 *   HIGH   est stock 0
 *   MEDIUM est stock 3-5
 *   LOW    est stock over 5
 *
 * Those thresholds are the spec, so they are reproduced exactly rather than
 * smoothed into a score. The gap they leave is real: a freezer holding 1 or 2
 * ball belongs to none of the three, and pretending otherwise would put a shop
 * that is nearly empty in the same bucket as one that is comfortable. Those land
 * in their own UNKNOWN group instead of being forced into a neighbour.
 *
 * A load cell reports one weight for a whole freezer and cannot say which
 * products are inside, so every quantity here is a total in kilograms-as-ball.
 * The split between products is the driver's call at the shop.
 */
class DeliverySuggestionService
{
    public const TIER_HIGH = 'HIGH';

    public const TIER_MEDIUM = 'MEDIUM';

    public const TIER_LOW = 'LOW';

    public const TIER_UNKNOWN = 'UNKNOWN';

    /**
     * A freezer with no reading at all is not "empty". Reporting 0 for it would
     * put a shop with dead sensors at the top of the urgent list, which is the
     * one place a broken sensor must not appear.
     */
    private const EMPTY_STOCK = 0.0;

    private const TIER_ORDER = [
        self::TIER_HIGH,
        self::TIER_MEDIUM,
        self::TIER_LOW,
        self::TIER_UNKNOWN,
    ];

    private const TIER_LABELS = [
        self::TIER_HIGH => '🔴 HIGH PRIORITY (Est stock 0)',
        self::TIER_MEDIUM => '🟡 MEDIUM (Est stock 3-5)',
        self::TIER_LOW => '🟢 LOW (Est stock > 5)',
        self::TIER_UNKNOWN => '⚪ UNKNOWN (Est stock 1-2, or sensor never reported)',
    ];

    private const THRESHOLD_TEXT = [
        self::TIER_HIGH => 'estimated stock 0',
        self::TIER_MEDIUM => 'estimated stock 3-5',
        self::TIER_LOW => 'estimated stock over 5',
        self::TIER_UNKNOWN => 'estimated stock 1-2, or a freezer that has never reported',
    ];

    // The BRD's confidence example reads "95% (sensor online 24h)". This half-life
    // produces a comparable freshness-based score without inventing a spec line.
    private const CONFIDENCE_HALF_LIFE_MINUTES = 720;


    /**
     * Rank a store by how empty its freezers are.
     */
    public function tierFor(float $estimatedStock, bool $hasSensor): string
    {
        if (! $hasSensor) {
            return self::TIER_UNKNOWN;
        }

        if ($estimatedStock <= self::EMPTY_STOCK) {
            return self::TIER_HIGH;
        }

        if ($estimatedStock <= 2) {
            // The BRD jumps from 0 to 3-5, so 1 and 2 fall through its buckets.
            return self::TIER_UNKNOWN;
        }

        if ($estimatedStock <= 5) {
            return self::TIER_MEDIUM;
        }

        return self::TIER_LOW;
    }

    /**
     * Every store with a suggestion, grouped the way the BRD groups them.
     *
     * Stores with no freezers are left out: there is nothing to fill, so
     * suggesting a delivery for them would be noise on a screen the warehouse
     * reads before every run.
     *
     * @param  array{warehouse_id?: int, store_ids?: array<int>, tier?: string}  $filters
     */
    public function suggest(array $filters = []): array
    {
        $query = Store::with('freezers');

        if (! empty($filters['store_ids'])) {
            $query->whereIn('id', $filters['store_ids']);
        }

        $stores = $query->orderBy('code')->get()->filter(
            fn (Store $store) => $store->freezers->isNotEmpty()
        );

        $buckets = [
            self::TIER_HIGH => [],
            self::TIER_MEDIUM => [],
            self::TIER_LOW => [],
            self::TIER_UNKNOWN => [],
        ];

        foreach ($stores as $store) {
            $rows = $store->freezers->map(fn (Freezer $freezer) => $this->rowFor($freezer));

            $estimatedStock = round((float) $rows->sum('estimated_stock_ball'), 2);
            $suggestedDelivery = round((float) $rows->sum('suggested_delivery_ball'), 2);

            // A store's confidence is the worst of its freezers, not the average.
            // One freezer that has not reported is enough to make the whole
            // suggestion unreliable, and averaging it away would hide that.
            $hasSensor = $rows->every(fn (array $row) => $row['has_sensor']);
            $confidence = $this->worstConfidence($rows);

            $tier = $this->tierFor($estimatedStock, $hasSensor);

            // The oldest reading is the one that makes the row stale, so that is
            // the timestamp shown as "Last update" rather than the newest.
            $lastSeenAt = $rows->map(fn (array $row) => $row['last_seen_at'])
                ->filter()
                ->sortDesc()
                ->first();

            $buckets[$tier][] = [
                'store_id' => $store->id,
                'store_code' => $store->code,
                'store_name' => $store->name,
                // BRD:329-334 writes each line as "RSA-001 (Toko Rapi)", so the
                // screen has the two halves already joined for it.
                'label' => $store->code.' ('.$store->name.')',
                'owner_name' => $store->owner_name,
                'address' => $store->address,
                'priority' => $tier,
                'estimated_stock_ball' => $estimatedStock,
                'suggested_delivery_ball' => $suggestedDelivery,
                'freezer_count' => $rows->count(),
                'sensors_online' => $rows->where('has_sensor')->count(),
                'sensors_offline' => $rows->filter(fn (array $row) => ! $row['has_sensor'])->count(),
                'confidence' => $confidence,
                'last_seen_at' => $lastSeenAt,
                'last_seen_minutes_ago' => $rows->min('last_seen_minutes_ago'),
                'last_update' => $this->humanize($lastSeenAt),
                'freezers' => $rows,
            ];
        }

        // Within a bucket, the emptiest store comes first, then the one that
        // wants the most ice. That is the order a warehouse works down the list.
        foreach ($buckets as &$rows) {
            usort($rows, fn (array $a, array $b) => $a['estimated_stock_ball'] <=> $b['estimated_stock_ball']
                ?: $b['suggested_delivery_ball'] <=> $a['suggested_delivery_ball']);
        }
        unset($rows);

        if (! empty($filters['tier'])) {
            $wanted = strtoupper($filters['tier']);
            $buckets = array_filter($buckets, fn ($_, $tier) => $tier === $wanted, ARRAY_FILTER_USE_BOTH);
        }

        $allRows = array_merge(...array_values($buckets ?: [[]]));

        return [
            'buckets' => $buckets,
            'groups' => $this->groups($buckets),
            'confidence' => $this->screenConfidence($allRows),
            'generated_at' => now()->toIso8601String(),
            'total_stores' => $allRows === [] ? 0 : count($allRows),
            'total_suggested_ball' => round((float) array_sum(array_column($allRows, 'suggested_delivery_ball')), 2),
            'sensors_offline' => (int) array_sum(array_column($allRows, 'sensors_offline')),
            'tier_thresholds' => self::THRESHOLD_TEXT,
            'note' => 'Quantities are a whole-freezer total in ball. A load cell cannot say which products are inside, so the split per product is the driver\'s call at the shop.',
        ];
    }

    /**
     * The three groups the 8:00 AM screen draws, in the order BRD:328-338 draws
     * them, with the label and rule already on each one.
     *
     * This is a list rather than the keyed object above because the screen is a
     * vertical stack: a keyed object would make the front end hard-code the
     * order and the labels, and those are exactly the details that changed the
     * last time the thresholds were touched.
     *
     * UNKNOWN is included and marked as not being a BRD group, so a shop with a
     * dead sensor is visibly held back instead of quietly missing from a list
     * somebody reads as complete.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $buckets
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $buckets): array
    {
        return collect(self::TIER_ORDER)
            ->map(function (string $tier) use ($buckets) {
                $rows = $buckets[$tier] ?? [];
                $lastSeenAt = collect($rows)->map(fn (array $row) => $row['last_seen_at'])->filter()->sortDesc()->first();

                return [
                    'tier' => $tier,
                    'label' => self::TIER_LABELS[$tier],
                    'rule' => self::THRESHOLD_TEXT[$tier],
                    'in_brd' => $tier !== self::TIER_UNKNOWN,
                    'stores_count' => count($rows),
                    'total_suggested_ball' => round((float) array_sum(array_column($rows, 'suggested_delivery_ball')), 2),
                    'last_update' => $this->humanize($lastSeenAt),
                    'stores' => array_map(fn (array $row) => [
                        'store_id' => $row['store_id'],
                        'store_code' => $row['store_code'],
                        'store_name' => $row['store_name'],
                        'label' => $row['label'],
                        'estimated_stock_ball' => $row['estimated_stock_ball'],
                        'suggested_delivery_ball' => $row['suggested_delivery_ball'],
                        'confidence' => $row['confidence'],
                        'last_seen_at' => $row['last_seen_at'],
                        'last_update' => $row['last_update'],
                    ], $rows),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The "Confidence: 95% (sensor online 24h)" line at BRD:340.
     *
     * The BRD prints a percentage and a freshness note but never says how to get
     * one from the readings, so the number here is our own and the formula is
     * sent with it. Each store scores 100 for a reading from now, falling to 0
     * at 24 hours old, and the screen shows the mean. Staleness is what actually
     * makes a suggestion wrong, so it is what the number measures: 95% means the
     * average reading is about an hour old, not that 95% of the shops are right.
     *
     * A freezer that has never reported scores 0, because freshness cannot be
     * claimed for a reading that does not exist.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function screenConfidence(array $rows): array
    {
        $freezers = collect($rows)->flatMap(fn (array $row) => $row['freezers']);

        if ($freezers->isEmpty()) {
            return [
                'percent' => null,
                'basis' => 'no freezer readings',
                'sensors_reporting' => 0,
                'sensors_silent' => 0,
                'oldest_reading' => null,
                'formula' => 'mean of per-freezer freshness, 100 at 0 minutes old to 0 at 24 hours old',
            ];
        }

        $score = $freezers->avg(function (array $row) {
            if ($row['last_seen_minutes_ago'] === null) {
                return 0.0;
            }

            $ratio = min(1.0, $row['last_seen_minutes_ago'] / (self::CONFIDENCE_HALF_LIFE_MINUTES * 2));

            return (1 - $ratio) * 100;
        });

        $oldest = $freezers->map(fn (array $row) => $row['last_seen_at'])->filter()->sort()->first();
        $silent = $freezers->filter(fn (array $row) => $row['last_seen_minutes_ago'] === null)->count();

        return [
            'percent' => (int) round($score),
            'basis' => $silent === 0
                ? 'sensor online'
                : 'sensor online, '.$silent.' freezer(s) never reported',
            'sensors_reporting' => $freezers->count() - $silent,
            'sensors_silent' => $silent,
            'oldest_reading' => $this->humanize($oldest),
            'formula' => 'mean of per-freezer freshness, 100 at 0 minutes old to 0 at 24 hours old',
        ];
    }

    /**
     * "2 hours ago", for the "Last update:" line the BRD prints under a group.
     */
    private function humanize(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($timestamp)->diffForHumans();
    }

    /**
     * One freezer's contribution to its store's suggestion.
     *
     * @return array<string, mixed>
     */
    private function rowFor(Freezer $freezer): array
    {
        $hasSensor = $freezer->last_weight_kg !== null;

        $lastSeenMinutes = $freezer->last_seen_at === null
            ? null
            : now()->diffInMinutes($freezer->last_seen_at);

        return [
            'freezer_id' => $freezer->id,
            'freezer_code' => $freezer->code,
            'has_sensor' => $hasSensor,
            'estimated_stock_ball' => round($freezer->estimated_stock_ball, 2),
            'max_capacity_ball' => round((float) $freezer->max_capacity_ball, 2),
            'suggested_delivery_ball' => round($freezer->suggested_delivery_ball, 2),
            'last_weight_kg' => $freezer->last_weight_kg,
            'tare_weight_kg' => $freezer->tare_weight_kg,
            'last_temperature_c' => $freezer->last_temperature_c,
            'last_door_status' => $freezer->last_door_status,
            'last_seen_at' => $freezer->last_seen_at,
            'last_seen_minutes_ago' => $lastSeenMinutes,
            'confidence' => $freezer->iot_confidence,
        ];
    }

    /**
     * The weakest confidence among a store's freezers.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function worstConfidence(iterable $rows): string
    {
        $order = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3];

        return collect($rows)
            ->map(fn (array $row) => $row['confidence'])
            ->sortBy(fn (string $c) => $order[$c] ?? 0)
            ->first() ?? 'LOW';
    }

    /**
     * A warehouse's own current stock, shown next to the suggestions.
     *
     * A suggestion to fill 40 ball is not actionable from an empty warehouse, so
     * the screen says whether the goods to do it are there.
     */
    public function warehouseContext(?Warehouse $warehouse): ?array
    {
        if ($warehouse === null) {
            return null;
        }

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'available_stock_ball' => round(app(WarehouseStockService::class)->available($warehouse->id), 2),
        ];
    }
}
