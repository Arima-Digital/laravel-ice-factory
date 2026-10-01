<?php

namespace App\Services;

use App\Models\Freezer;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Which freezers look like they need a delivery, and how much ice to bring them.
 *
 * This is the 8:00 AM "Smart Delivery" screen, before any plan exists. The BRD
 * ranks freezers into three tiers:
 *
 *   HIGH   est stock 0
 *   MEDIUM est stock 3-5
 *   LOW    est stock over 5
 *
 * Those thresholds are the spec, so they are reproduced exactly rather than
 * smoothed into a score. The gap they leave is real: a freezer holding 1 or 2
 * ball belongs to none of the three, and rounding it into a neighbour would put a
 * nearly empty freezer in the same tier as a comfortable one. Those land in their
 * own UNKNOWN instead of being forced somewhere, alongside a freezer that has
 * never reported, which is not the same as an empty one.
 *
 * A load cell reports one weight for a whole freezer and cannot say which
 * products are inside, so every quantity here is a total in ball. The split
 * between products is the driver's call at the shop.
 *
 * Each line is one freezer, not one store: the number that decides a tier belongs
 * to a single freezer, and a store total printed beside a single freezer's code
 * would read as if it described that freezer.
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

    /**
     * Rank a freezer by how empty it is.
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
     * Every freezer worth a visit, most urgent first.
     *
     * Stores with no freezers are left out: there is nothing to fill, so
     * suggesting a delivery for them would be noise on a screen the warehouse
     * reads before every run.
     *
     * store_ids narrows the answer when the caller already has a shortlist. The
     * default returns every store, which is what the screen wants.
     *
     * @param  array{store_ids?: array<int>}  $filters
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

        $lines = [];

        foreach ($stores as $store) {
            // BRD:329-334 writes each line as "RSA-001 (Toko Rapi)", so the screen
            // has the two halves already joined for it.
            array_push($lines, ...$this->lines([
                'store_id' => $store->id,
                'label' => $store->code.' ('.$store->name.')',
            ], $store->freezers));
        }

        // The order a person works down the screen: the BRD's own tier order first
        // (high, then medium, then low), and inside a tier the emptiest freezer,
        // then the one wanting the most ice.
        $order = array_flip(self::TIER_ORDER);

        usort($lines, fn (array $a, array $b) => $order[$a['label']] <=> $order[$b['label']]
            ?: $a['estimated_stock_ball'] <=> $b['estimated_stock_ball']
            ?: $b['suggest_ball'] <=> $a['suggest_ball']);

        return [
            'suggestions' => $lines,
        ];
    }

    /**
     * One line of the list: a store, what it is estimated to hold, how much ice
     * to bring it, and how urgent that is.
     *
     * No row number. The list arrives already in priority order, so a front end
     * rendering it top to bottom numbers the rows itself, and a hard-coded
     * position here would only be one more thing to keep in step.
     *
     * `label` is the bare tier name, HIGH or MEDIUM, and the colour belongs to the
     * front end. The earlier decorated form ("🔴 HIGH PRIORITY (Est stock 0)")
     * repeated the threshold that `estimated_stock_ball` already shows next to it,
     * and put presentation on the wire where it can only get out of step.
     *
     * A store with more than one freezer becomes more than one line, one per
     * freezer, so the figures always belong to a single freezer instead of being
     * a shop total that reads as if it were one freezer's reading.
     *
     * The tier is worked out from that freezer's own reading rather than the
     * store's total. Once each line is one freezer, a label carried over from the
     * shop total would contradict the number printed beside it: a store totalling
     * 2.5 ball is MEDIUM, but the line showing its empty freezer at 0 ball would
     * then be labelled MEDIUM while claiming to be empty. A row has to agree with
     * itself, so each one is ranked on its own figure.
     */
    private function lines(array $store, Collection $freezers): array
    {
        return $freezers
            ->sortBy('estimated_stock_ball')
            ->values()
            ->map(fn (Freezer $freezer) => [
                'store_id' => $store['store_id'],
                'store' => $store['label'],
                'freezer_code' => $freezer->code,
                'estimated_stock_ball' => round($freezer->estimated_stock_ball, 2),
                'suggest_ball' => round($freezer->suggested_delivery_ball, 2),
                'label' => $this->tierFor(
                    (float) $freezer->estimated_stock_ball,
                    $freezer->last_weight_kg !== null,
                ),
            ])
            ->all();
    }
}
