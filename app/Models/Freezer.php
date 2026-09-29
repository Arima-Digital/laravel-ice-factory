<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Freezer extends Model
{
    use HasFactory;

    /**
     * One ball equals 10 kg. This is the single definition of the ball unit and
     * every stock calculation must derive from it.
     *
     * It is a fixed unit of mass, not a physical item, so a 15 kg product counts
     * as 1.5 ball and a 5 kg product as 0.5 ball. That lets one numeric column
     * carry every product size without a separate kg column.
     */
    public const BALL_KG = 10;

    protected $fillable = [
        'store_id',
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
        'max_capacity_ball' => 'decimal:2',
        'tare_weight_kg' => 'decimal:2',
        'last_weight_kg' => 'decimal:2',
        'last_temperature_c' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Mirrors the database default so the field is always present in the API
     * response, not only after the IoT webhook has written to it.
     */
    protected $attributes = [
        'last_door_status' => 'CLOSED',
    ];

    /**
     * Computed stock values are not database columns, so they have to be
     * listed here to be included in array and JSON output.
     */
    protected $appends = [
        'estimated_stock_ball',
        'suggested_delivery_ball',
        'iot_confidence',
    ];

    /**
     * Get the store this freezer belongs to.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Products that have been delivered to this freezer.
     *
     * A freezer may hold more than one product, and a load cell cannot tell
     * which ones, so this is the record of what was actually put in. It is
     * derived from delivery items rather than stored on the freezer to keep a
     * single source of truth: the same product list cannot exist in two places
     * and disagree.
     *
     * The pivot table accumulates one row per delivery, so the same product
     * appears repeatedly; distinct() collapses it back to a product list.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'delivery_items', 'freezer_id', 'product_id')
            ->select('products.*')
            ->distinct();
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
     * Calculate estimated stock from the latest IoT weight reading.
     *
     * The net weight is divided by BALL_KG, not by the product weight, so every
     * product size resolves to a fraction of a ball (10 kg = 1, 15 kg = 1.5,
     * 5 kg = 0.5). The reading is reported as measured: it is deliberately not
     * rounded, because a load cell drifts by about the same order of magnitude
     * and rounding would imply a precision the sensor does not have. The
     * driver weighs the freezer physically and that is what counts for sales.
     *
     * Clamped to 0 and to the freezer capacity so a stale or faulty reading can
     * never report negative stock or more than the freezer can hold.
     */
    public function getEstimatedStockBallAttribute(): float
    {
        if ($this->last_weight_kg === null) {
            return 0.0;
        }

        $netWeight = (float) $this->last_weight_kg - (float) $this->tare_weight_kg;
        $estimatedStock = $netWeight / self::BALL_KG;

        return $this->clampToCapacity($estimatedStock);
    }

    /**
     * A freezer that never reported a reading has no estimate to trust, so its
     * confidence is LOW even though the staleness check would otherwise see an
     * old timestamp and call it HIGH.
     */
    public function getIotConfidenceAttribute(): string
    {
        if ($this->last_seen_at === null || $this->last_weight_kg === null) {
            return 'LOW';
        }

        $minutes = now()->diffInMinutes($this->last_seen_at);

        if ($minutes > 120) {
            return 'LOW';
        }

        return $minutes > 60 ? 'MEDIUM' : 'HIGH';
    }

    /**
     * Suggested delivery is the gap between capacity and estimated stock, so
     * restocking the freezer fills it up again.
     */
    public function getSuggestedDeliveryBallAttribute(): float
    {
        return $this->clampToCapacity(
            (float) $this->max_capacity_ball - $this->estimated_stock_ball
        );
    }

    /**
     * Keep any computed quantity inside the range the freezer can physically
     * hold, and drop floating point noise such as 0.37000000000000003.
     *
     * Two decimals is the finest step that means anything here: one hundredth
     * of a ball is 100 g, well below what a load cell can resolve. Rounding to
     * that step removes the arithmetic noise without rounding away a real
     * reading, since 0.37 and 0.37000000000000003 are the same measurement.
     */
    private function clampToCapacity(float $value): float
    {
        $bounded = max(0, min((float) $this->max_capacity_ball, $value));

        return round($bounded, 2);
    }
}
