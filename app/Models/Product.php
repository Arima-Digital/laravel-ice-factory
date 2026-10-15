<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The share each weight gets in a default refill, before any freezer has a
     * setting of its own. The business fills a freezer mostly with the 10 kg
     * block and tops it up with the small 5 kg ball.
     */
    public const DEFAULT_LOAD_SHARES = [
        5 => 30,
        10 => 70,
    ];

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
     * Exposed so the frontend does not have to know that one ball equals 10 kg.
     * A 15 kg product comes back as 1.5 and a 5 kg product as 0.5.
     */
    protected $appends = [
        'ball_equivalent',
    ];

    /**
     * How many ball this product counts as, using the shared definition of
     * 1 ball = 10 kg. Not a database column, so it is derived on read.
     */
    public function getBallEquivalentAttribute(): float
    {
        return (float) $this->weight_kg / Freezer::BALL_KG;
    }

    /**
     * Get the productions of this product.
     */
    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    /**
     * Get the freezers this product has been delivered to.
     *
     * A freezer holds more than one product now, so the link runs through
     * delivery items rather than a column on the freezer.
     *
     * The pivot table accumulates one row per delivery, so delivering the same
     * product to the same freezer again repeats the freezer here; distinct()
     * collapses it back to a freezer list.
     */
    public function freezers(): BelongsToMany
    {
        return $this->belongsToMany(Freezer::class, 'delivery_items')
            ->select('freezers.*')
            ->withTimestamps(false)
            ->distinct();
    }

    /**
     * Get the individual deliveries of this product.
     */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * Get the sales of this product.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * The fallback refill mix when no freezer setting exists yet: 30% of the
     * 5 kg and 70% of the 10 kg. A product of any other weight is left out
     * rather than guessed at, so adding a new size does not silently pull the
     * default split toward it.
     *
     * @return array<int, float> product_id => percentage
     */
    public static function defaultLoadRatio(): array
    {
        return static::query()
            ->whereIn('weight_kg', array_keys(self::DEFAULT_LOAD_SHARES))
            ->get()
            ->mapWithKeys(fn (self $product) => [
                (int) $product->id => (float) self::DEFAULT_LOAD_SHARES[(int) $product->weight_kg],
            ])
            ->all();
    }
}
