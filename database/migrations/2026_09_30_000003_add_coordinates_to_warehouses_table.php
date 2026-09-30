<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A delivery route is ordered by walking out from the warehouse it loads
     * from, so the warehouse needs a position of its own. Stores already carry
     * coordinates; warehouses did not.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->decimal('latitude', 11, 8)->nullable()->after('name')->comment('Latitude for route planning');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude')->comment('Longitude for route planning');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
