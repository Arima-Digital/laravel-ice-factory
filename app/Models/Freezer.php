<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * The step a restock is rounded to: half a ball, which is 5 kg.
     *
     * Half a ball is the smallest unit the catalogue actually sells in, since a
     * 5 kg bag is half a ball (BRD:700). Anything finer is a number nobody can
     * act on, because there is no ice that comes in a 0.3 ball.
     */
    public const STOCK_STEP_BALL = 0.5;

    /**
     * How far two readings may differ before the stock check calls it a drift.
     *
     * One step. The stock figures are rounded to STOCK_STEP_BALL, so rounding can
     * move either side by up to half a step and the difference can come out at
     * almost a full step even when nothing is wrong. A tolerance of 0.01, which
     * is what BRD:1108 originally asked for, was below the rounding resolution
     * and would have reported almost every freezer as drifting.
     */
    public const DRIFT_TOLERANCE_BALL = self::STOCK_STEP_BALL;

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

    public function compositions(): HasMany
    {
        return $this->hasMany(FreezerProductComposition::class);
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
     * 5 kg = 0.5). The driver weighs the freezer physically and that is what
     * counts for sales, so this figure is a restock hint rather than an
     * accounting number.
     *
     * Reported in whole steps of STOCK_STEP_BALL. The figure is derived from the
     * restock rather than rounded on its own, because rounding the two numbers
     * separately lets them disagree with the capacity: a freezer showing 8.5
     * beside a suggestion of 1.95 does not add up to 10. Deriving the estimate
     * from the rounded suggestion keeps estimated + suggested = capacity exactly,
     * and the raw reading stays in the freezer_logs history.
     */
    public function getEstimatedStockBallAttribute(): float
    {
        if ($this->last_weight_kg === null) {
            return 0.0;
        }

        return $this->stock()['estimated'];
    }

    /**
     * The restock that brings the freezer back to capacity, rounded up to the
     * next whole step so the driver carries enough to fill it and has spare in
     * the truck if the physical count disagrees with the sensor. Coming up short
     * on a route costs a second trip; carrying a little extra only costs space
     * that is already reserved for ice.
     */
    public function getSuggestedDeliveryBallAttribute(): float
    {
        return $this->stock()['suggested'];
    }

    /**
     * Work out the two stock figures together, so they cannot drift apart.
     *
     * The suggested restock is rounded up to the next step and the estimate is
     * whatever the capacity has left over. Rounding the estimate upwards instead
     * would push the suggestion downwards, which is the opposite of carrying
     * spare ice: a freezer measured at 0.3 ball would be reported as 0.5 and the
     * driver would be told to bring 9.5 for a real gap of 9.7.
     *
     * A capacity that is not a whole number of steps is kept as it is: rounding
     * the suggestion up past it would tell the driver to bring more ice than the
     * freezer can physically hold, and ice does not compress.
     *
     * @return array{estimated: float, suggested: float}
     */
    private function stock(): array
    {
        $capacity = (float) $this->max_capacity_ball;

        $netWeight = (float) $this->last_weight_kg - (float) $this->tare_weight_kg;
        $measured = $this->clampToCapacity($netWeight / self::BALL_KG);

        $gap = $capacity - $measured;

        // An unrounded remainder means the gap is already a whole step, so the
        // ceiling is a no-op. Dividing and multiplying in floating point can
        // still land a hair under it, hence the epsilon.
        $steps = $gap / self::STOCK_STEP_BALL;
        $suggested = abs($steps - round($steps)) < 0.001
            ? round($steps) * self::STOCK_STEP_BALL
            : ceil($steps) * self::STOCK_STEP_BALL;

        $suggested = min($suggested, $capacity);

        return [
            'estimated' => round($capacity - $suggested, 2),
            'suggested' => round($suggested, 2),
        ];
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
