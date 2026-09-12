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
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->comment('Store ID FK');
            $table->decimal('current_sales_amount', 15, 2)->comment('Total sales amount for this settlement period');
            $table->decimal('amount_paid', 15, 2)->default(0)->comment('Total amount already paid');
            $table->decimal('outstanding', 15, 2)->comment('Outstanding balance (current_sales - amount_paid)');
            $table->enum('status', ['DRAFT', 'COMPLETED', 'VOID'])->default('DRAFT')->comment('Settlement status');
            $table->timestamps();
            
            // Constraints
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            
            // Indexes
            $table->index('store_id');
            $table->index('status');
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
