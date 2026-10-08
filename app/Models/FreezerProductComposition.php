<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreezerProductComposition extends Model
{
    use HasFactory;

    protected $fillable = [
        'freezer_id',
        'product_id',
        'qty_ball',
        'delivery_item_id',
    ];

    protected $casts = [
        'qty_ball' => 'decimal:2',
    ];

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(Freezer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class);
    }
}
