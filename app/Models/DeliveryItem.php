<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryItem extends Model
{
    protected $table = 'delivery_items';
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'store_id',
        'freezer_id',
        'confirmed_stock_before_ball',
        'photo_stock_before',
        'delivered_qty_ball',
        'photo_delivered',
        'visited_at',
        'notes',
    ];

    protected $casts = [
        'confirmed_stock_before_ball' => 'decimal:2',
        'delivered_qty_ball' => 'decimal:2',
        'visited_at' => 'datetime',
        'photo_stock_before' => 'array',
        'photo_delivered' => 'array',
    ];

    /**
     * Get the delivery this item belongs to.
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * Get the store for this delivery item.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the freezer for this delivery item.
     */
    public function freezer(): BelongsTo
    {
        return $this->belongsTo(Freezer::class);
    }

    /**
     * Get the sales records for this delivery item.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'delivery_item_id');
    }

    /**
     * Calculate stock after delivery.
     * Formula: confirmed_stock_before_ball + delivered_qty_ball
     */
    public function getStockAfterBallAttribute(): float
    {
        return $this->confirmed_stock_before_ball + $this->delivered_qty_ball;
    }
}
