<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Production extends Model
{
    protected $fillable = [
        'product_id',
        'production_date',
        'qty_produced_ball',
        'qty_reject_ball',
        'qty_good_ball',
        'created_by',
        'status',
    ];

    protected $casts = [
        'production_date' => 'date',
        'qty_produced_ball' => 'decimal:2',
        'qty_reject_ball' => 'decimal:2',
        'qty_good_ball' => 'decimal:2',
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Production records are permanent audit trail - should not be updated after creation.
     * Consider using read-only model or adding validation in service layer.
     */

    /**
     * Get the product being produced.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created this production record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate qty_good_ball = qty_produced_ball - qty_reject_ball.
     * Should be done in migration or service layer to ensure data consistency.
     */
    public function calculateQtyGood(): float
    {
        return $this->qty_produced_ball - $this->qty_reject_ball;
    }
}
