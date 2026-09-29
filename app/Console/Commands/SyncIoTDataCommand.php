<?php

namespace App\Console\Commands;

use App\Services\IotDataService;
use Illuminate\Console\Command;

class SyncIoTDataCommand extends Command
{
    protected $signature = 'iot:sync
        {--device= : Only process this one device (sim_number/code/id) for debugging}';

    protected $description = 'Pull the latest IoT readings from the gateway and save them';

    public function handle(IotDataService $iot): int
    {
        $summary = $iot->syncFromGateway($this->option('device') ?: null);

        if ($summary['total'] === 0) {
            $this->warn('No telemetry received from IoT gateway.');

            return self::SUCCESS;
        }

        foreach ($summary['details'] as $d) {
            if (isset($d['freezer_code'])) {
                $this->line("  saved: {$d['freezer_code']} -> {$d['estimated_stock_ball']} ball");
            } elseif (isset($d['freezer_id'])) {
                $this->line("  saved: freezer#{$d['freezer_id']} -> {$d['estimated_stock_ball']} ball");
            } else {
                $this->error("  ignored: {$d['reason']} [".($d['device'] ?? '-').']');
            }
        }

        $this->info("IoT sync done: {$summary['saved']} saved, {$summary['ignored']} ignored.");

        return $summary['ignored'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}