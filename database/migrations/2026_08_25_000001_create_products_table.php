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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Product code: ICE-10, ICE-20, etc');
            $table->string('name')->comment('Product name: Es Kristal 10 KG, etc');
            $table->decimal('weight_kg', 8, 2)->comment('Weight per unit in KG');
            $table->decimal('selling_price', 15, 2)->comment('Selling price per unit');
            $table->timestamps();
            
            // Indexes
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
