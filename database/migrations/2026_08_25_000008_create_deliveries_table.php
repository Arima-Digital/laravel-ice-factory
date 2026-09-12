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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->date('delivery_date')->comment('Delivery date');
            $table->foreignId('driver_id')->comment('Driver user ID FK');
            $table->foreignId('vehicle_id')->comment('Vehicle ID FK');
            $table->foreignId('warehouse_id')->comment('Warehouse source ID FK');
            $table->decimal('initial_qty_loaded_ball', 8, 2)->comment('Quantity loaded from warehouse (audit trail)');
            $table->decimal('total_qty_delivered_ball', 8, 2)->default(0)->comment('Total delivered to all freezers');
            $table->decimal('total_qty_returned_ball', 8, 2)->default(0)->comment('Total returned to warehouse');
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('DRAFT')->comment('Delivery status');
            $table->text('notes')->nullable()->comment('Delivery notes');
            $table->timestamp('started_at')->nullable()->comment('When driver started delivery');
            $table->timestamp('completed_at')->nullable()->comment('When driver completed delivery');
            $table->timestamps();
            
            // Constraints
            $table->foreign('driver_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('restrict');
            
            // Indexes
            $table->index('delivery_date');
            $table->index('driver_id');
            $table->index('status');
            $table->index(['delivery_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
