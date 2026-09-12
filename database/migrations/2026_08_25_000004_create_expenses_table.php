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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date')->comment('Date of expense');
            $table->enum('category', ['FUEL', 'SALARY', 'ELECTRICITY', 'PACKAGING', 'MAINTENANCE', 'OTHER'])
                ->comment('Expense category');
            $table->decimal('amount', 15, 2)->comment('Expense amount');
            $table->text('description')->nullable()->comment('Expense description/notes');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict')->comment('User who created expense record');
            $table->enum('status', ['DRAFT', 'POSTED', 'CANCELLED'])->default('DRAFT')->comment('DRAFT=editing, POSTED=finalized, CANCELLED=void');
            $table->timestamps();
            
            // Indexes
            $table->index('expense_date');
            $table->index('category');
            $table->index('status');
            $table->index(['expense_date', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
