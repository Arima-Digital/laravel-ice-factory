<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freezers', function (Blueprint $table) {
            // 1 ball = 10 kg, so a 15 kg product counts as 1.5 ball and a 5 kg
            // product as 0.5 ball. The column must therefore hold fractions.
            $table->decimal('max_capacity_ball', 8, 2)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('freezers', function (Blueprint $table) {
            $table->integer('max_capacity_ball')->change();
        });
    }
};
