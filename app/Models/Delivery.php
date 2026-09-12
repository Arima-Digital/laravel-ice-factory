<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = [
        'delivery_date',
        'driver_id',
        'vehicle_id',
        'warehouse_id',
        'initial_qty_loaded_ball',
        'total_qty_delivered_ball',
        'total_qty_returned_ball',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'initial_qty_loaded_ball' => 'decimal:2',
        'total_qty_delivered_ball' => 'decimal:2',
        'total_qty_returned_ball' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the driver (user) for this delivery.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Get the vehicle for this delivery.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the warehouse source for this delivery.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the delivery items for this delivery.
     */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * Calculate total delivered quantity.
     * sum of all delivery_items.delivered_qty_ball
     */
    public function getTotalDeliveredBallAttribute(): float
    {
        return $this->deliveryItems()->sum('delivered_qty_ball') ?? 0;
    }

    /**
     * Calculate remaining quantity in vehicle.
     * initial_qty_loaded_ball - total_delivered_ball
     */
    public function getRemainingQtyBallAttribute(): float
    {
        return $this->initial_qty_loaded_ball - $this->total_delivered_ball;
    }
}
