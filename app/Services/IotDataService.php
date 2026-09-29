<?php

namespace App\Services;

use App\Models\Freezer;
use App\Models\FreezerLog;
use Illuminate\Support\Facades\Log;

class IotDataService
{
    /**
     * Pull the latest readings from the IoT gateway (PULL model).
     *
     * BE SURE: for now this returns mock data. When the boss provides the
     * real gateway spec, only this method and config/iot.php change:
     *
     *     return Http::withHeaders(['Authorization' => 'Bearer '.config('iot.gateway_token')])
     *         ->get(config('iot.gateway_url'))
     *         ->json();
     */
    public function pullFromIoTGateway(): array
    {
        return $this->mockGatewayData();
    }

    /**
     * ADAPTER — translate the raw gateway response into the standard internal
     * telemetry shape. All downstream logic (saveIoTData) only ever sees this shape.
     *
     * @return array<int, array{
     *     device: string|null,
     *     freezer_id: int|null,
     *     weight_kg: float,
     *     temperature_c: float,
     *     door_status: string,
     *     logged_at: string
     * }>
     */
    public function parseGatewayResponse(array $raw): array
    {
        $parsed = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $parsed[] = [
                'device' => $entry['device']
                    ?? $entry['sim_number']
                    ?? $entry['code']
                    ?? null,
                'freezer_id' => $entry['freezer_id'] ?? null,
                'weight_kg' => (float) ($entry['weight_kg'] ?? $entry['weight'] ?? 0),
                'temperature_c' => (float) ($entry['temperature_c'] ?? $entry['temp'] ?? 0),
                'door_status' => strtoupper((string) ($entry['door_status'] ?? $entry['door'] ?? 'CLOSED')),
                'logged_at' => $entry['logged_at'] ?? $entry['timestamp'] ?? now()->toDateTimeString(),
            ];
        }

        return $parsed;
    }

    /**
     * Save one telemetry reading: resolve the freezer, append a freezer_logs
     * row and update the freezers.last_* snapshot (incl. last_seen_at).
     *
     * @param int|string $deviceOrFreezerId freezer id, sim_number or code
     * @return array<string, mixed>|null null when the freezer is unknown (no auto-create)
     */
    public function saveIoTData(int|string $deviceOrFreezerId, float $weightKg, float $temperatureC, string $doorStatus, ?string $loggedAt = null): ?array
    {
        $freezer = $this->resolveFreezer($deviceOrFreezerId);

        if (!$freezer) {
            Log::warning('IoT telemetry ignored: freezer not found.', [
                'identifier' => $deviceOrFreezerId,
                'weight_kg' => $weightKg,
            ]);

            return null;
        }

        $doorStatus = strtoupper(trim($doorStatus));
        if (!in_array($doorStatus, ['OPEN', 'CLOSED'], true)) {
            $doorStatus = 'CLOSED';
        }

        if ($weightKg < 0) {
            $weightKg = 0;
        }

        $loggedAtTs = $loggedAt ?: now()->toDateTimeString();

        FreezerLog::create([
            'freezer_id' => $freezer->id,
            'weight_kg' => $weightKg,
            'temperature_c' => $temperatureC,
            'door_status' => $doorStatus,
            'logged_at' => $loggedAtTs,
        ]);

        $freezer->update([
            'last_weight_kg' => $weightKg,
            'last_temperature_c' => $temperatureC,
            'last_door_status' => $doorStatus,
            'last_seen_at' => $loggedAtTs,
        ]);

        $freezer->refresh();

        return [
            'freezer_id' => $freezer->id,
            'freezer_code' => $freezer->code,
            'estimated_stock_ball' => $freezer->estimated_stock_ball,
            'suggested_delivery_ball' => $freezer->suggested_delivery_ball,
        ];
    }

    /**
     * Full PULL cycle used by both `php artisan iot:sync` and the
     * `/api/iot/sync` trigger endpoint: fetch -> parse -> save each reading.
     */
    public function syncFromGateway(?string $onlyDevice = null): array
    {
        $raw = $this->pullFromIoTGateway();
        $telemetry = $this->parseGatewayResponse($raw);

        $details = [];
        $saved = 0;
        $ignored = 0;

        foreach ($telemetry as $reading) {
            $identifier = $reading['device'] ?? $reading['freezer_id'];

            if ($identifier === null) {
                $ignored++;
                $details[] = ['device' => null, 'status' => 'ignored', 'reason' => 'no device identifier'];

                continue;
            }

            if ($onlyDevice !== null && (string) $identifier !== (string) $onlyDevice) {
                continue;
            }

            $result = $this->saveIoTData(
                $identifier,
                $reading['weight_kg'],
                $reading['temperature_c'],
                $reading['door_status'],
                $reading['logged_at'],
            );

            if ($result) {
                $saved++;
                $details[] = [
                    'freezer_id' => $result['freezer_id'],
                    'freezer_code' => $result['freezer_code'],
                    'estimated_stock_ball' => $result['estimated_stock_ball'],
                    'suggested_delivery_ball' => $result['suggested_delivery_ball'],
                ];
            } else {
                $ignored++;
                $details[] = ['device' => (string) $identifier, 'status' => 'ignored', 'reason' => 'freezer not found'];
            }
        }

        return [
            'total' => count($telemetry),
            'saved' => $saved,
            'ignored' => $ignored,
            'details' => $details,
        ];
    }

    /**
     * Resolve a freezer from either its DB id, sim_number or code.
     *
     * sim_number can be a pure-digit phone number, so it is checked BEFORE
     * falling back to an id lookup.
     */
    protected function resolveFreezer(int|string $identifier): ?Freezer
    {
        if (is_int($identifier)) {
            return Freezer::find($identifier);
        }

        $freezer = Freezer::where('sim_number', (string) $identifier)
            ->orWhere('code', (string) $identifier)
            ->first();

        if ($freezer) {
            return $freezer;
        }

        return ctype_digit((string) $identifier) ? Freezer::find((int) $identifier) : null;
    }

    /**
     * Dummy gateway payload mirroring the seeded freezers (PULL model source).
     */
    protected function mockGatewayData(): array
    {
        $now = now();

        return [
            [
                'sim_number' => '6281234567890',
                'weight' => 82.35,
                'temp' => -18.20,
                'door' => 'CLOSED',
                'timestamp' => $now->copy()->subMinutes(2)->toDateTimeString(),
            ],
            [
                'sim_number' => '6281234567891',
                'weight' => 12.80,
                'temp' => -19.00,
                'door' => 'OPEN',
                'timestamp' => $now->copy()->subMinutes(5)->toDateTimeString(),
            ],
            [
                'sim_number' => '6281234567892',
                'weight' => 104.10,
                'temp' => -17.50,
                'door' => 'CLOSED',
                'timestamp' => $now->copy()->subMinutes(1)->toDateTimeString(),
            ],
            [
                'sim_number' => '6281234567893',
                'weight' => 71.66,
                'temp' => -18.00,
                'door' => 'CLOSED',
                'timestamp' => $now->copy()->subMinutes(8)->toDateTimeString(),
            ],
            [
                'sim_number' => '6281234567894',
                'weight' => 44.20,
                'temp' => -18.60,
                'door' => 'CLOSED',
                'timestamp' => $now->copy()->subMinutes(12)->toDateTimeString(),
            ],
        ];
    }
}