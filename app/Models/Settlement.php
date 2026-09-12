<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    protected $fillable = [
        'store_id',
        'current_sales_amount',
        'amount_paid',
        'outstanding',
        'status',
    ];

    protected $casts = [
        'current_sales_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'outstanding' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the store this settlement belongs to.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the payments for this settlement.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
