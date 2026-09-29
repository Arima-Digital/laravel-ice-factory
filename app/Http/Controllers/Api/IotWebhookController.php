<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IotDataService;
use Illuminate\Http\Request;

class IotWebhookController extends Controller
{
    /**
     * Receive a single telemetry reading pushed by an IoT device (PUSH model).
     *
     * Accepts identification by `device` (sim_number/code) OR `freezer_id`.
     */
    public function store(Request $request, IotDataService $iot)
    {
        $data = $request->validate([
            'device' => 'sometimes|string',
            'freezer_id' => 'sometimes|integer',
            'weight_kg' => 'required|numeric|min:0',
            'temperature_c' => 'required|numeric',
            'door_status' => 'sometimes|in:OPEN,CLOSED',
            'logged_at' => 'sometimes|date',
        ]);

        if (blank($data['device'] ?? null) && blank($data['freezer_id'] ?? null)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'device' => ['Provide either device (sim_number/code) or freezer_id.'],
                ],
            ], 422);
        }

        $result = $iot->saveIoTData(
            $data['device'] ?? $data['freezer_id'],
            (float) $data['weight_kg'],
            (float) $data['temperature_c'],
            $data['door_status'] ?? 'CLOSED',
            $data['logged_at'] ?? null,
        );

        if (!$result) {
            return response()->json([
                'message' => 'Freezer not found',
                'error' => 'No freezer matches the given device identifier.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'IoT data saved successfully',
            'data' => $result,
        ], 201);
    }

    /**
     * Trigger a PULL cycle from the clock (Dokploy scheduler / cron).
     * Plain endpoint on purpose: same logic as `php artisan iot:sync`.
     */
    public function sync(Request $request, IotDataService $iot)
    {
        $summary = $iot->syncFromGateway();

        if ($summary['total'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No telemetry received from IoT gateway.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'IoT sync completed',
            'data' => $summary,
        ], 200);
    }
}