<?php

namespace Database\Seeders;

use App\Models\Freezer;
use App\Models\FreezerLog;
use Illuminate\Database\Seeder;

/**
 * Mock IoT telemetry for the freezers that ALREADY exist.
 *
 * Run it yourself on demand (it never truncates anything):
 *     php artisan db:seed --class=IotMockSeeder
 */
class IotMockSeeder extends Seeder
{
    public function run(): void
    {
        $freezers = Freezer::query()->get();

        if ($freezers->isEmpty()) {
            $this->command->warn('No freezers found — skip IotMockSeeder.');

            return;
        }

        foreach ($freezers as $freezer) {
            $productWeight = $freezer->product?->weight_kg ?? 10.0;
            $balls = random_int(2, 9);
            $netWeight = $productWeight * $balls;
            $weight = round($freezer->tare_weight_kg + $netWeight + random_int(0, 50) / 100, 2);
            $loggedAt = now()->subMinutes(random_int(1, 25));

            FreezerLog::create([
                'freezer_id' => $freezer->id,
                'weight_kg' => $weight,
                'temperature_c' => round(-18.5 + random_int(-25, 25) / 10, 2),
                'door_status' => 'CLOSED',
                'logged_at' => $loggedAt->toDateTimeString(),
            ]);

            $freezer->update([
                'last_weight_kg' => $weight,
                'last_temperature_c' => round(-18.5 + random_int(-25, 25) / 10, 2),
                'last_door_status' => 'CLOSED',
                'last_seen_at' => $loggedAt->toDateTimeString(),
            ]);
        }

        $this->command->info("IotMockSeeder: mock telemetry written for {$freezers->count()} freezers.");
    }
}