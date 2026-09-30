<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A delivery used to hold no destination at all: the plan recorded which
     * driver, which vehicle and how much to load, and the store only appeared
     * once the driver was standing in front of it, so nothing in between could
     * say where the driver was meant to be going. These rows are the plan the
     * BRD describes, written when the plan is created and consumed as the
     * driver works through it.
     */
    public function up(): void
    {
        Schema::create('delivery_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->comment('Delivery ID FK');
            $table->foreignId('store_id')->comment('Store destination ID FK');
            $table->unsignedInteger('sequence')->comment('Visit order, 1-based, starting from the warehouse');
            $table->decimal('planned_qty_ball', 8, 2)->nullable()->comment('Suggested load for this store, advisory only');
            $table->enum('status', ['PENDING', 'VISITED', 'SKIPPED'])->default('PENDING')->comment('PENDING=not driven yet, VISITED=confirmed, SKIPPED=passed over');
            $table->timestamp('visited_at')->nullable()->comment('When the driver confirmed this stop');
            $table->text('notes')->nullable()->comment('Reason a stop was skipped, or driver remark');
            $table->timestamps();

            // Constraints
            $table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');

            // A store is visited at most once per delivery, and the visit order
            // is the route, so both are unique within a delivery.
            $table->unique(['delivery_id', 'store_id'], 'delivery_stops_delivery_store_unique');
            $table->unique(['delivery_id', 'sequence'], 'delivery_stops_delivery_sequence_unique');

            // Indexes
            $table->index('delivery_id');
            $table->index(['status', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_stops');
    }
};
