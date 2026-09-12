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
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->comment('Delivery ID FK');
            $table->foreignId('store_id')->comment('Store destination ID FK');
            $table->foreignId('freezer_id')->comment('Freezer ID FK (where restocked)');
            $table->decimal('confirmed_stock_before_ball', 8, 2)->comment('Stock confirmed before restock (driver input)');
            $table->decimal('delivered_qty_ball', 8, 2)->comment('Quantity delivered to this freezer');
            $table->timestamp('visited_at')->nullable()->comment('When driver visited this freezer');
            
            // Constraints
            $table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->foreign('freezer_id')->references('id')->on('freezers')->onDelete('restrict');
            
            // Indexes
            $table->index('delivery_id');
            $table->index(['store_id', 'freezer_id']);
            $table->index('visited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};
