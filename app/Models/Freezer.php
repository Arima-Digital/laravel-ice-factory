<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Freezer extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'code',
        'sim_number',
        'max_capacity_ball',
        'tare_weight_kg',
        'last_weight_kg',
        'last_temperature_c',
        'last_door_status',
        'last_seen_at',
    ];

    protected $casts = [
        'max_capacity_ball' => 'integer',
        'tare_weight_kg' => 'decimal:2',
        'last_weight_kg' => 'decimal:2',
        'last_temperature_c' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the store this freezer belongs to.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the product assigned to this freezer.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the delivery items for this freezer.
     */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * Get the sales for this freezer.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Get the IoT logs for this freezer.
     */
    public function freezerLogs(): HasMany
    {
        return $this->hasMany(FreezerLog::class);
    }

    /**
     * Calculate estimated stock from latest IoT data.
     * Formula: (last_weight_kg - tare_weight_kg) / product.weight_kg
     */
    public function getEstimatedStockBallAttribute(): float
    {
        if (!$this->last_weight_kg || !$this->product) {
            return 0;
        }

        $netWeight = $this->last_weight_kg - $this->tare_weight_kg;
        $estimatedStock = $netWeight / $this->product->weight_kg;
        
        // Boundary check
        $estimatedStock = max(0, min($this->max_capacity_ball, round($estimatedStock, 2)));
        
        return $estimatedStock;
    }

    /**
     * Calculate suggested delivery based on estimated stock.
     * Formula: max_capacity_ball - estimated_stock_ball
     */
    public function getSuggestedDeliveryBallAttribute(): float
    {
        return max(0, $this->max_capacity_ball - $this->estimated_stock_ball);
    }
}
