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
        // 1. Add payment_type to PAYMENTS table
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('payment_type', ['TODAY', 'PAST_DAYS', 'DEBT'])
                ->default('TODAY')
                ->after('method')
                ->comment('Type: TODAY (today sales), PAST_DAYS (previous unpaid), DEBT (old debt)');
        });

        // 2. Add photo columns to DELIVERY_ITEMS table
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->text('photo_stock_before')
                ->nullable()
                ->after('confirmed_stock_before_ball')
                ->comment('JSON array of photo paths: stock before restock (driver capture) - multiple photos supported');
            
            $table->text('photo_delivered')
                ->nullable()
                ->after('delivered_qty_ball')
                ->comment('JSON array of photo paths: goods delivered (driver capture) - multiple photos supported');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn(['photo_stock_before', 'photo_delivered']);
        });
    }
};
