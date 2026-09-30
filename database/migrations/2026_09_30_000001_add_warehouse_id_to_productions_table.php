<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every production batch a warehouse.
     *
     * A batch used to be warehouse-less, so all stock maths was global: every
     * warehouse reported the same number. With more than one warehouse in play
     * that lets a delivery be loaded from a warehouse that is actually empty.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('product_id')
                ->constrained('warehouses')
                ->onDelete('restrict')
                ->comment('Warehouse holding this batch. NULL = not yet assigned to any warehouse.');
        });

        // Backfill only when the answer is unambiguous. With exactly one
        // warehouse every existing batch provably belongs to it, so pointing
        // them at that warehouse keeps their stock visible. With two or more we
        // cannot know, so we leave them NULL and let the API report them under
        // unassigned_production_qty instead of silently guessing.
        $warehouseIds = DB::table('warehouses')->pluck('id');

        if ($warehouseIds->count() === 1) {
            DB::table('productions')
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $warehouseIds->first()]);
        }
    }

    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
