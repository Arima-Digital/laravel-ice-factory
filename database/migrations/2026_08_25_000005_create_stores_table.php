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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Store code (e.g., RSA-001)');
            $table->string('name')->comment('Store name');
            $table->string('owner_name')->comment('Store owner name');
            $table->string('phone')->comment('Store phone number');
            $table->text('address')->comment('Store address');
            $table->decimal('latitude', 11, 8)->nullable()->comment('Latitude for map');
            $table->decimal('longitude', 11, 8)->nullable()->comment('Longitude for map');
            $table->timestamps();
            
            // Indexes
            $table->index('code');
            $table->index('owner_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
