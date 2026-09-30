<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStop;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Store;

/**
 * Records a driver's visit to one store on a delivery route.
 *
 * The BRD has arriving and leaving as their own steps, separate from confirming
 * a freezer. That distinction matters for a store the driver reaches and finds
 * nothing to sell: without a departure, the only thing that could close the stop
 * was recording goods, so the stop stayed pending for the rest of the run and
 * the progress count stayed low while the driver was in fact finished with it.
 *
 * Arriving is idempotent and keeps the first time, because a driver who
 * re-opens a stop's screen is still the same arrival, not a second one. The same
 * goes for leaving: a stop cannot be left twice, and it cannot be left without
 * having arrived.
 */
class StopVisitService
{
    /**
     * Record arrival at a stop, keeping the first arrival.
     */
    public function arrive(Delivery $delivery, DeliveryStop $stop): DeliveryStop
    {
        $this->assertDrivable($delivery, $stop);

        if ($stop->arrived_at !== null) {
            return $stop;
        }

        $stop->forceFill([
            'arrived_at' => now(),
        ])->save();

        return $stop->refresh();
    }

    /**
     * Record leaving a stop, keeping the first departure.
     *
     * A stop that held at least one confirmed line is also marked VISITED, so
     * the progress count agrees with the goods. One where nothing was sold stays
     * PENDING on status and is closed by departed_at alone: nothing was
     * delivered there, and marking it visited would claim otherwise.
     */
    public function depart(Delivery $delivery, DeliveryStop $stop): DeliveryStop
    {
        $this->assertDrivable($delivery, $stop);

        if ($stop->arrived_at === null) {
            throw new StopVisitException(
                'The driver has not arrived at this stop yet',
                422,
                ['arrived_at' => null, 'departed_at' => null]
            );
        }

        if ($stop->departed_at !== null) {
            return $stop;
        }

        $stop->forceFill([
            'departed_at' => now(),
        ])->save();

        if ($stop->status === 'PENDING' && $this->hasConfirmedLines($delivery, $stop)) {
            $stop->forceFill([
                'status' => 'VISITED',
                'visited_at' => $stop->visited_at ?? $stop->arrived_at,
            ])->save();
        }

        return $stop->refresh();
    }

    /**
     * Pass over a stop without serving it, with a reason.
     *
     * A stop the driver never reached and one the driver reached but could not
     * serve are both skipped, so the reason is what tells them apart.
     */
    public function skip(Delivery $delivery, DeliveryStop $stop, ?string $reason): DeliveryStop
    {
        $this->assertDrivable($delivery, $stop);

        if ($stop->departed_at !== null) {
            throw new StopVisitException(
                'This stop has already been left',
                422,
                ['departed_at' => $stop->departed_at->toIso8601String()]
            );
        }

        $stop->forceFill([
            'status' => 'SKIPPED',
            'departed_at' => now(),
            'notes' => $reason,
        ])->save();

        return $stop->refresh();
    }

    /**
     * What is owed at one stop, before any of it becomes a payment.
     *
     * The BRD wants this as its own screen once the freezers are done: how much
     * was delivered, what the store bought, what it owed before this visit, and
     * what it can be asked for today. The last figure is the sum of the two,
     * because a store paying part of what it owes today still leaves the rest as
     * debt.
     *
     * Sales recorded during this visit are listed whatever their status, since
     * the driver needs to see what the stop collected. The previous outstanding
     * deliberately counts only approved sales: an unapproved figure is not yet a
     * debt, and adding it would make the preview disagree with the store's
     * balance the moment a payment is made.
     */
    public function settlementPreview(Delivery $delivery, DeliveryStop $stop): array
    {
        $storeId = $stop->store_id;

        $thisVisitItems = DeliveryItem::where('delivery_id', $delivery->id)
            ->where('store_id', $storeId)
            ->get();

        // A sale points at the delivery item it was recorded against, not at the
        // delivery, so the visit's sales are found through this run's items at
        // this store. Anything else would sweep in the same store's sales from
        // earlier runs and inflate what this stop collected.
        $sales = Sale::whereIn('delivery_item_id', $thisVisitItems->pluck('id'))
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->get();

        $totalDelivered = (float) $thisVisitItems->sum('delivered_qty_ball');
        $thisVisitSales = (float) $sales->sum('total_amount');

        $outstandingBeforeThisStop = round(
            (float) Sale::confirmed()->where('store_id', $storeId)->sum('total_amount')
            - (float) Payment::where('store_id', $storeId)->where('status', 'CONFIRMED')->sum('amount'),
            2
        );

        // What the driver can ask for on top of the old debt is the previous
        // outstanding plus what this stop itself collected. No adjustment is
        // needed for this visit's sales appearing in the outstanding above,
        // because they are not in it: a sale is not a debt until an admin
        // approves it, and nothing here has been approved yet.

        $pendingSales = (float) $sales->where('status', 'PENDING')->sum('total_amount');

        return [
            'delivery_id' => $delivery->id,
            'store_id' => $storeId,
            'store' => Store::find($storeId),
            'sequence' => $stop->sequence,
            'arrived_at' => $stop->arrived_at,
            'departed_at' => $stop->departed_at,
            'total_delivered_ball' => round($totalDelivered, 2),
            'lines_confirmed' => $thisVisitItems->count(),
            'all_freezers_confirmed' => $this->allFreezersConfirmed($storeId, $thisVisitItems),
            'this_visit_sales' => [
                'total' => round($thisVisitSales, 2),
                'count' => $sales->count(),
                'pending_total' => round($pendingSales, 2),
                'lines' => $sales,
            ],
            'outstanding_before_this_stop' => $outstandingBeforeThisStop,
            'payable_today' => round(max(0, $outstandingBeforeThisStop + $thisVisitSales), 2),
            'payment_options' => [
                'PAY_TODAY' => [
                    'label' => 'Pay in full',
                    'amount' => round(max(0, $outstandingBeforeThisStop + $thisVisitSales), 2),
                ],
                'PAY_PARTIAL' => [
                    'label' => 'Pay part of it',
                    'minimum' => 1,
                    'maximum' => round(max(0, $outstandingBeforeThisStop + $thisVisitSales), 2),
                ],
                'SKIP' => [
                    'label' => 'Pay nothing now',
                    'adds_to_outstanding' => round($outstandingBeforeThisStop + $thisVisitSales, 2),
                ],
                'PAY_OLD_DEBT' => $outstandingBeforeThisStop > 0
                    ? [
                        'label' => 'Settle the previous debt',
                        'amount' => $outstandingBeforeThisStop,
                    ]
                    : null,
            ],
            'note' => 'Sales stay PENDING until an admin approves them, so this_visit_sales is not yet a debt. It becomes outstanding after approval.',
        ];
    }

    /**
     * Whether every freezer at the store has a line on this delivery.
     *
     * A store with no freezers at all is reported as complete: there was nothing
     * to confirm, so nothing is outstanding on the driver.
     */
    private function allFreezersConfirmed(int $storeId, $items): bool
    {
        $freezerIds = Store::find($storeId)?->freezers()->pluck('freezers.id') ?? collect();

        if ($freezerIds->isEmpty()) {
            return true;
        }

        $confirmed = $items->pluck('freezer_id')->unique();

        return $freezerIds->diff($confirmed)->isEmpty();
    }

    private function hasConfirmedLines(Delivery $delivery, DeliveryStop $stop): bool
    {
        return DeliveryItem::where('delivery_id', $delivery->id)
            ->where('store_id', $stop->store_id)
            ->exists();
    }

    /**
     * A visit can only be recorded on a run that is under way, and only once
     * per stop.
     */
    private function assertDrivable(Delivery $delivery, DeliveryStop $stop): void
    {
        if ($delivery->status !== 'IN_PROGRESS') {
            throw new StopVisitException(
                'Stop visits are only recorded while the delivery is in progress',
                422,
                ['current_status' => $delivery->status]
            );
        }

        if ($stop->delivery_id !== $delivery->id) {
            throw new StopVisitException('This stop is not on that delivery', 422);
        }

        if ($stop->status === 'SKIPPED') {
            throw new StopVisitException(
                'This stop was skipped',
                422,
                ['notes' => $stop->notes]
            );
        }
    }
}
