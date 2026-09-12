<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Warehouse extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the deliveries from this warehouse.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Get warehouse stock (on-the-fly calculation).
     * PHASE 1: SUM(productions.qty_good_ball where status=POSTED)
     * PHASE 2: Above - SUM(delivery_items.delivered_qty_ball)
     */
    public function getWarehouseStockAttribute(): float
    {
        $produced = Production::where('status', 'POSTED')
            ->sum('qty_good_ball') ?? 0;

        $delivered = DB::table('delivery_items')
            ->sum('delivered_qty_ball') ?? 0;

        return max(0, $produced - $delivered);
    }
}
