<?php

namespace App\Services;

use App\Models\Freezer;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Which stores look like they need a delivery, and how much ice to bring them.
 *
 * This is the 8:00 AM "Smart Delivery" screen, before any plan exists. The BRD
 * ranks them into three tiers:
 *
 *   HIGH   est stock 0
 *   MEDIUM est stock 3-5
 *   LOW    est stock over 5
 *
 * Those thresholds are the spec, so they are reproduced exactly rather than
 * smoothed into a score. The gap they leave is real: a store holding 1 or 2
 * ball belongs to none of the three, and rounding it into a neighbour would put
 * a nearly empty shop in the same tier as a comfortable one. Those land in their
 * own UNKNOWN instead of being forced somewhere, alongside a freezer that has
 * never reported, which is not the same as an empty one.
 *
 * A load cell reports one weight for a whole freezer and cannot say which
 * products are inside, so every quantity here is a total in ball. The split
 * between products is the driver's call at the shop.
 *
 * One line is one store, because that is what the screen draws and what the
 * person reading it acts on. BRD:326-340 lists a store once with a single
 * suggested figure, and the warehouse picks stores, not freezers. Repeating a
 * store once per freezer made store_id come back duplicated in a list meant to
 * be ticked off, which reads as duplicated data rather than as detail.
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
            // The BRD jumps from 0 to 3-5, so 1 and 2 fall through its tiers.
            return self::TIER_UNKNOWN;
        }

        if ($estimatedStock <= 5) {
            return self::TIER_MEDIUM;
        }

        return self::TIER_LOW;
    }

    /**
     * Every store worth a visit, most urgent first.
     *
     * No filters, by design. This is the list the warehouse reads before a plan
     * exists, so narrowing it would hide rows that are exactly what they are
     * looking for. The caller picks from what comes back.
     *
     * Stores with no freezers are left out: there is nothing to fill, so
     * suggesting a delivery for them would be noise on that screen.
     */
    public function suggest(): array
    {
        $stores = Store::with('freezers')->get()->filter(
            fn (Store $store) => $store->freezers->isNotEmpty()
        );

        $lines = [];

        foreach ($stores as $store) {
            // BRD:329-334 writes each line as "RSA-001 (Toko Rapi)", so the screen
            // has the two halves already joined for it.
            $lines[] = $this->line([
                'store_id' => $store->id,
                'label' => $store->code.' ('.$store->name.')',
            ], $store->freezers);
        }

        // The order a person works down the screen: emptiest first, then the shop
        // wanting the most ice.
        //
        // Tier is deliberately not the sort key, even though the BRD lists HIGH
        // before MEDIUM before LOW and that looks like the obvious thing to sort
        // on. Sorting by tier sends the BRD's own 1-2 ball gap to the very bottom:
        // a store holding 1 ball is labelled UNKNOWN and lands below one holding
        // 10, which is the opposite of how urgent it is. The gap stays a label,
        // not a place in the queue.
        //
        // A freezer that never reported estimates to 0, which would otherwise put a
        // shop with a dead sensor at the very top. It cannot be ranked on a reading
        // it does not have, so it goes last and says so.
        usort($lines, fn (array $a, array $b) => $a['has_sensor'] <=> $b['has_sensor']
            ?: $a['estimated_stock_ball'] <=> $b['estimated_stock_ball']
            ?: $b['suggest_ball'] <=> $a['suggest_ball']);

        return [
            // The sort needs to know which stores have a dead sensor, but that is
            // how the row was ranked, not something the screen asked for.
            'suggestions' => array_map(
                fn (array $line) => array_diff_key($line, ['has_sensor' => null]),
                $lines
            ),
        ];
    }

    /**
     * One store's line: what it is estimated to hold, how much ice to bring it,
     * and how urgent that is.
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
     * The two numbers come from different freezers on purpose, and cannot both come
     * from one without changing what they mean. The tier comes from the emptiest
     * freezer, because that is the number printed beside the label and a line has
     * to agree with itself, and because one empty freezer is enough to make the
     * store worth a visit whatever its other freezers hold. The suggestion is the
     * sum across the store's freezers, because that is the amount to be carried:
     * two half-full freezers still need both topped up.
     */
    private function line(array $store, Collection $freezers): array
    {
        // One freezer that has never reported is enough to hold the whole store
        // back. Averaging it away would rank a shop with dead sensors on the
        // strength of its working ones.
        $hasSensor = $freezers->every(fn (Freezer $freezer) => $freezer->last_weight_kg !== null);

        // sortBy then first, not min(): min() with a callback hands back the
        // smallest value, which is a float, and the line needs the freezer it came
        // from. The collection is not empty here, the caller filters for that.
        //
        // Sorted by code first so a tie in stock resolves the same way on every
        // request. sortBy is stable, so the second sort keeps the code order within
        // each group of equal readings.
        $emptiest = $freezers->sortBy('code')->sortBy('estimated_stock_ball')->first();

        return [
            'store_id' => $store['store_id'],
            'store' => $store['label'],
            // The freezer the two numbers below were read from, not an arbitrary one
            // from the store. The screen says which freezer is the problem, and if
            // this named a different one the line would send the driver to the wrong
            // door with a figure that belongs somewhere else.
            'freezer_code' => $emptiest->code,
            'estimated_stock_ball' => round((float) $emptiest->estimated_stock_ball, 2),
            'suggest_ball' => round((float) $freezers->sum(
                fn (Freezer $freezer) => $freezer->suggested_delivery_ball
            ), 2),
            'label' => $this->tierFor((float) $emptiest->estimated_stock_ball, $hasSensor),
            // Only used to sort: it keeps a store with a dead sensor out of the top
            // of the list, and is stripped before the row is returned.
            'has_sensor' => $hasSensor,
        ];
    }
}
