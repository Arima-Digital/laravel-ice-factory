<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the arrival and departure times to a planned stop.
     *
     * A stop was only ever marked VISITED by the first freezer confirmation,
     * which left a store the driver walked into, found nothing to sell and drove
     * away from stuck at PENDING forever: the only thing that could close a stop
     * was recording goods in it. The BRD has arriving and leaving as their own
     * steps, so both are recorded as timestamps here.
     *
     * The status column keeps its three values on purpose. Whether a stop is
     * currently being served is a question about these two timestamps, not a
     * fourth status, and expanding the enum would mean a stored procedure
     * rewrite for no extra meaning.
     */
    public function up(): void
    {
        Schema::table('delivery_stops', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('visited_at')->comment('When the driver arrived at this store');
            $table->timestamp('departed_at')->nullable()->after('arrived_at')->comment('When the driver left this store');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_stops', function (Blueprint $table) {
            $table->dropColumn(['arrived_at', 'departed_at']);
        });
    }
};
