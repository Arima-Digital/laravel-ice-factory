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
        Schema::create('freezer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freezer_id')->comment('Freezer ID FK');
            $table->decimal('weight_kg', 8, 2)->comment('Weight reading from load cell (kg)');
            $table->decimal('temperature_c', 5, 2)->comment('Temperature reading (Celsius)');
            $table->enum('door_status', ['OPEN', 'CLOSED'])->comment('Door status from sensor');
            $table->timestamp('logged_at')->comment('When IoT sensor data was received');
            
            // Constraints
            $table->foreign('freezer_id')->references('id')->on('freezers')->onDelete('cascade');
            
            // Indexes
            $table->index('freezer_id');
            $table->index('logged_at');
            $table->index(['freezer_id', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freezer_logs');
    }
};
