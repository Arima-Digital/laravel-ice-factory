<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A delivery plan has an approval step between "written" and "running".
     *
     * A driver drafts a plan and an admin approves it, so the lifecycle needs a
     * state that says "agreed, not yet started". IN_PROGRESS cannot carry that
     * meaning: it means the van is on the road, which is the driver's own step
     * afterwards. Without POSTED, the only way to reach IN_PROGRESS would be for
     * the driver to start their own unapproved draft, and the approval would be
     * a formality.
     *
     * POSTED matches the name production and expenses already use for the same
     * idea, so "DRAFT -> POSTED -> IN_PROGRESS" reads the same across the app.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'POSTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])
                ->default('DRAFT')
                ->change();
        });
    }

    public function down(): void
    {
        // A POSTED plan is an approved plan, so it cannot be silently downgraded
        // to DRAFT on the way back. Refuse rather than invent a rule: those rows
        // need an admin's attention first.
        if (DB::table('deliveries')->where('status', 'POSTED')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: some deliveries are POSTED. Move them back to DRAFT first.'
            );
        }

        Schema::table('deliveries', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])
                ->default('DRAFT')
                ->change();
        });
    }
};
