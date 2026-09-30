<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryStop extends Model
{
    protected $fillable = [
        'delivery_id',
        'store_id',
        'sequence',
        'planned_qty_ball',
        'status',
        'visited_at',
        'notes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'planned_qty_ball' => 'decimal:2',
        'visited_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the delivery this stop belongs to.
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * Get the store to be visited on this stop.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
