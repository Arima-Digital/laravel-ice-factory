<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'code',
        'name',
        'weight_kg',
        'selling_price',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the productions of this product.
     */
    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    /**
     * Get the freezers assigned to this product.
     */
    public function freezers(): HasMany
    {
        return $this->hasMany(Freezer::class);
    }
}
