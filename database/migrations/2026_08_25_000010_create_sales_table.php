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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->comment('Store ID FK');
            $table->foreignId('freezer_id')->comment('Freezer ID FK (where sold)');
            $table->foreignId('delivery_item_id')->nullable()->comment('Associated delivery item (nullable for non-delivery sales)');
            $table->decimal('qty_ball', 8, 2)->comment('Quantity sold (in ball)');
            $table->decimal('unit_price', 12, 2)->comment('Price per ball');
            $table->decimal('total_amount', 15, 2)->comment('Total sales amount');
            $table->enum('status', ['CONFIRMED', 'VOID'])->default('CONFIRMED')->comment('Sale status');
            $table->timestamp('sold_at')->comment('When sale was recorded');
            $table->timestamps();
            
            // Constraints
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->foreign('freezer_id')->references('id')->on('freezers')->onDelete('restrict');
            $table->foreign('delivery_item_id')->references('id')->on('delivery_items')->onDelete('set null');
            
            // Indexes
            $table->index('store_id');
            $table->index('freezer_id');
            $table->index('status');
            $table->index('sold_at');
            $table->index(['store_id', 'sold_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
