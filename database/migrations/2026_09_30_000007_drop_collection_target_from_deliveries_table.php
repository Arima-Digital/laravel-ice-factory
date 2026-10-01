<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the per-delivery collection target.
     *
     * The BRD has no such field on a delivery plan. What it does mention is a
     * collection target on two other screens: the admin dashboard (BRD:127
     * "Target collection: Rp 1.5M") and the progress screen. Both are company
     * daily figures, not a per-run promise, and neither is derivable from a
     * single delivery.
     *
     * The field had already been taken out of the API: it is not in the create
     * or update validation, so a payload carrying it is ignored and the value is
     * never written. What was left behind was the empty column, the model casts
     * and the Swagger examples, which is why the old field kept reappearing in
     * request bodies. This removes the residue so it cannot be mistaken for a
     * real input again.
     *
     * If a collection target is ever needed, it belongs to a period (a day, a
     * month), not to one van's run, and should be added with that scope rather
     * than revived here.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('deliveries', 'collection_target')) {
            return;
        }

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('collection_target');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('collection_target', 15, 2)
                ->nullable()
                ->after('initial_qty_loaded_ball')
                ->comment('Target collection in rupiah for this delivery');
        });
    }
};
