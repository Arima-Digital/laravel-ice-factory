<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Vehicle code (e.g., VEH-001)');
            $table->string('plate_number')->unique()->comment('License plate number');
            $table->string('name')->comment('Vehicle name/label');
            $table->timestamps();
            
            // Indexes
            $table->index('code');
            $table->index('plate_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
