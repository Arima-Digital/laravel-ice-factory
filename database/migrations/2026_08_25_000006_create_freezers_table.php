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
        Schema::create('freezers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->comment('Store ID FK');
            $table->foreignId('product_id')->comment('Product ID FK (ice type)');
            $table->string('code')->unique()->comment('Freezer code (e.g., FRZ-RSA-001)');
            $table->string('sim_number')->nullable()->comment('SIM card number for IoT communication');
            $table->integer('max_capacity_ball')->comment('Maximum capacity in ball quantity');
            $table->decimal('tare_weight_kg', 8, 2)->comment('Empty freezer weight (kg)');
            $table->decimal('last_weight_kg', 8, 2)->nullable()->comment('Last detected total weight (kg)');
            $table->decimal('last_temperature_c', 5, 2)->nullable()->comment('Last detected temperature (Celsius)');
            $table->enum('last_door_status', ['OPEN', 'CLOSED'])->default('CLOSED')->comment('Last door status from IoT sensor');
            $table->timestamp('last_seen_at')->nullable()->comment('Last time IoT data received');
            $table->timestamps();
            
            // Constraints
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            
            // Indexes
            $table->index('store_id');
            $table->index('code');
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freezers');
    }
};
