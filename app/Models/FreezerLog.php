<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreezerLog extends Model
{
    protected $table = 'freezer_logs';
    public $timestamps = false;

    protected $fillable = [
        'freezer_id',
        'weight_kg',
        'temperature_c',
        'door_status',
        'logged_at',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
        'temperature_c' => 'decimal:2',
        'logged_at' => 'datetime',
    ];

    /**
     * Get the freezer this log belongs to.
     */
    public function freezer(): BelongsTo
    {
        return $this->belongsTo(Freezer::class);
    }
}
