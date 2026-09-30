<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The BRD shows a collection target on the delivery plan ("Target
     * collection: Rp 500K") and reports progress against it, but there was
     * nowhere to record it. Nullable, because a driver route is still valid
     * when nobody set a figure.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('collection_target', 15, 2)->nullable()->after('initial_qty_loaded_ball')->comment('Target collection in rupiah for this delivery');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('collection_target');
        });
    }
};
