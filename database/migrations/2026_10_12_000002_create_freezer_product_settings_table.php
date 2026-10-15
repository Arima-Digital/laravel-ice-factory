<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The target mix a freezer should be refilled with, per product.
     *
     * This is deliberately kept apart from freezer_product_compositions, which
     * is the running picture of what is actually inside a freezer and is written
     * by every confirmation. Mixing the two would let an automatic figure and a
     * hand-set figure overwrite each other, so the target lives on its own.
     */
    public function up(): void
    {
        Schema::create('freezer_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freezer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['freezer_id', 'product_id']);
        });

        // An order note the admin keeps against the freezer, so a request from
        // the store owner ("just bring the 10 kg") travels with the freezer
        // instead of living only in someone's memory.
        Schema::table('freezers', function (Blueprint $table) {
            $table->text('load_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('freezers', function (Blueprint $table) {
            $table->dropColumn('load_note');
        });

        Schema::dropIfExists('freezer_product_settings');
    }
};
