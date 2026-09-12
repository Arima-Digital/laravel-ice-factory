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
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->date('production_date')->comment('Date of production');
            $table->decimal('qty_produced_ball', 10, 2)->comment('Quantity produced in ball unit');
            $table->decimal('qty_reject_ball', 10, 2)->default(0)->comment('Quantity rejected in ball unit');
            $table->decimal('qty_good_ball', 10, 2)->comment('Quantity good = qty_produced - qty_reject (calculated)');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict')->comment('User who created production record');
            $table->enum('status', ['DRAFT', 'POSTED', 'CANCELLED'])->default('DRAFT')->comment('DRAFT=editing, POSTED=finalized, CANCELLED=void');
            $table->timestamps();
            
            // Indexes
            $table->index('production_date');
            $table->index('status');
            $table->index(['product_id', 'production_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productions');
    }
};
