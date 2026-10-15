<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a driver actually carries out of the warehouse, split by product.
 *
 * deliveries.initial_qty_loaded_ball is one number for the whole van, so until
 * now nothing recorded which products made up the load. The goods that went out
 * were only known per freezer, through delivery_items, which says what was
 * dropped off rather than what was on board.
 *
 * This table is the load manifest: one row per delivery per product. It is kept
 * separate from delivery_items because a load belongs to the run, not to a
 * freezer, and one product can be carried to several stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_load_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->comment('Delivery ID FK');
            $table->foreignId('product_id')->comment('Product carried on the truck');
            $table->decimal('qty_ball', 8, 2)->comment('Ball loaded onto the truck for this product');
            $table->decimal('qty_returned_ball', 8, 2)->default(0)->comment('Ball brought back for this product');
            $table->timestamps();

            $table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');

            $table->index('delivery_id');
            $table->unique(['delivery_id', 'product_id']);
        });

        // Backfill from what was delivered: on a run already in the books, the
        // goods that reached the freezers are the best evidence of what was
        // carried. One line per product per delivery, summed across every
        // freezer that received it.
        DB::statement(
            'INSERT INTO delivery_load_items (delivery_id, product_id, qty_ball, qty_returned_ball, created_at, updated_at)
             SELECT delivery_id, product_id, SUM(delivered_qty_ball), 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             FROM delivery_items
             WHERE product_id IS NOT NULL
             GROUP BY delivery_id, product_id'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_load_items');
    }
};
