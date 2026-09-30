<?php

namespace App\Models;

use App\Services\WarehouseStockService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'name',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
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
     * Get the production batches stored in this warehouse.
     */
    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    /**
     * Get warehouse stock (on-the-fly calculation).
     * Produced: SUM(productions.qty_good_ball where status=POSTED) for THIS warehouse.
     * Delivered: SUM(delivery_items.delivered_qty_ball) of this warehouse's deliveries.
     *
     * Both sides are scoped to this warehouse. Summing every production in the
     * table would report the same figure for every warehouse, which hides an
     * empty one.
     */
    public function getWarehouseStockAttribute(): float
    {
        return app(WarehouseStockService::class)->available($this->id);
    }
}
