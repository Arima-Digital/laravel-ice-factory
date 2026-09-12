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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->nullable()->comment('Settlement ID FK');
            $table->foreignId('store_id')->comment('Store ID FK');
            $table->decimal('amount', 15, 2)->comment('Payment amount');
            $table->enum('method', ['CASH', 'TRANSFER', 'QRIS'])->comment('Payment method');
            $table->string('reference')->nullable()->comment('Payment reference (bank ref, QRIS ref, etc)');
            $table->enum('status', ['CONFIRMED', 'VOID'])->default('CONFIRMED')->comment('Payment status');
            $table->timestamp('paid_at')->comment('When payment was made');
            $table->timestamps();
            
            // Constraints
            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('restrict');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('restrict');
            
            // Indexes
            $table->index('settlement_id');
            $table->index('store_id');
            $table->index('status');
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
