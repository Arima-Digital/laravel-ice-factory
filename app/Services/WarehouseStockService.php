<?php

namespace App\Services;

use App\Models\DeliveryItem;
use App\Models\Production;
use App\Models\Warehouse;

/**
 * Single source of truth for "how much stock does this warehouse have".
 *
 * This used to be written out three times, and every copy of it summed across
 * all warehouses while taking a warehouse id as an argument. The id was echoed
 * back to the caller but never reached the query, so two warehouses always
 * reported the same number and a delivery could be loaded from an empty one.
 *
 * Available = good production POSTED in this warehouse
 *          - delivered by this warehouse's deliveries
 *
 * Batches with a NULL warehouse_id are counted by unassigned() and excluded
 * from every warehouse total, so they cannot be double counted or silently
 * attributed to whichever warehouse happens to be asked about.
 */
class WarehouseStockService
{
    /**
     * Good production already finalised in one warehouse, or across all of them.
     */
    public function produced(?int $warehouseId = null): float
    {
        $query = Production::where('status', 'POSTED');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (float) $query->sum('qty_good_ball');
    }

    /**
     * Quantity this warehouse has already shipped.
     *
     * delivery_items has no warehouse column of its own, so this joins through
     * deliveries. Cancelled deliveries are excluded: they moved nothing.
     */
    public function delivered(int $warehouseId): float
    {
        return (float) DeliveryItem::whereHas('delivery', function ($query) use ($warehouseId) {
            $query->where('warehouse_id', $warehouseId)
                ->where('status', '!=', 'CANCELLED');
        })->sum('delivered_qty_ball');
    }

    /**
     * Finalised production that is not attributed to any warehouse yet.
     */
    public function unassigned(): float
    {
        return (float) Production::where('status', 'POSTED')
            ->whereNull('warehouse_id')
            ->sum('qty_good_ball');
    }

    /**
     * Stock one warehouse can still load, or the total across all of them.
     */
    public function available(?int $warehouseId = null): float
    {
        $produced = $this->produced($warehouseId);

        if ($warehouseId === null) {
            $delivered = (float) DeliveryItem::whereHas('delivery', function ($query) {
                $query->where('status', '!=', 'CANCELLED');
            })->sum('delivered_qty_ball');
        } else {
            $delivered = $this->delivered($warehouseId);
        }

        return $produced - $delivered;
    }

    /**
     * Whether one warehouse exists, so a backfill or a lookup is unambiguous.
     */
    public function hasSingleWarehouse(): bool
    {
        return Warehouse::count() === 1;
    }
}
