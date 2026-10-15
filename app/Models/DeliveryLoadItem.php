<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product carried on a run, and how much of it.
 *
 * A freezer holds a mix of products and a load cell cannot tell them apart, so
 * the load is recorded where the decision is made, at the warehouse. This is
 * the "brought" side of the delivery, against which delivery_items is the
 * "dropped off" side.
 */
class DeliveryLoadItem extends Model
{
    protected $fillable = [
        'delivery_id',
        'product_id',
        'qty_ball',
        'qty_returned_ball',
    ];

    protected $casts = [
        'qty_ball' => 'decimal:2',
        'qty_returned_ball' => 'decimal:2',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
