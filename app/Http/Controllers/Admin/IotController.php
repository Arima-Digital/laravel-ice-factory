<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Freezer;
use App\Models\FreezerLog;
use Illuminate\Http\Request;

class IotController extends Controller
{
    /**
     * Return dummy/current IoT telemetry for the seeded freezers.
     * Used to preview what `iot:sync` produces (and to test suggestions).
     */
    public function testData(Request $request)
    {
        $freezers = Freezer::with(['store', 'product'])->get();

        $data = $freezers->map(function (Freezer $freezer) {
            return [
                'device' => $freezer->sim_number ?: $freezer->code,
                'freezer_id' => $freezer->id,
                'freezer_code' => $freezer->code,
                'store_code' => $freezer->store?->code,
                'weight_kg' => $freezer->last_weight_kg,
                'temperature_c' => $freezer->last_temperature_c,
                'door_status' => $freezer->last_door_status,
                'last_seen_at' => $freezer->last_seen_at?->toDateTimeString(),
                'estimated_stock_ball' => round($freezer->estimated_stock_ball, 2),
                'suggested_delivery_ball' => round($freezer->suggested_delivery_ball, 2),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'IoT test data retrieved successfully',
            'data' => $data,
        ], 200);
    }

    /**
     * Show the IoT pipeline health: last sync time, active freezers and
     * the confidence distribution used by the delivery suggestion.
     */
    public function status(Request $request)
    {
        $freezers = Freezer::all();
        $lastSyncRaw = FreezerLog::query()->max('logged_at');
        $lastSync = $lastSyncRaw ? \Carbon\Carbon::parse($lastSyncRaw) : null;

        $high = $medium = $low = 0;

        foreach ($freezers as $freezer) {
            if (!$freezer->last_seen_at) {
                $low++;

                continue;
            }

            $minutes = now()->diffInMinutes($freezer->last_seen_at);

            if ($minutes <= 60) {
                $high++;
            } elseif ($minutes <= 120) {
                $medium++;
            } else {
                $low++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'IoT status retrieved successfully',
            'data' => [
                'last_sync_at' => $lastSync?->toDateTimeString(),
                'total_freezers' => $freezers->count(),
                'freezers_with_data' => $freezers->filter(fn ($f) => $f->last_seen_at !== null)->count(),
                'confidence' => [
                    'HIGH' => $high,
                    'MEDIUM' => $medium,
                    'LOW' => $low,
                ],
            ],
        ], 200);
    }
}