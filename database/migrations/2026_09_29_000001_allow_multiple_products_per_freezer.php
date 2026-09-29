<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one freezer hold more than one product.
 *
 * A load cell only reports total kilograms, so a freezer that mixes products
 * cannot be attributed to a single product: 60 kg could be six 10 kg bags, two
 * 15 kg bags plus three 10 kg bags, or a mix of 5 kg bags, and the reading is
 * the same. The product therefore has to be recorded where the transaction
 * happens, not on the freezer itself.
 *
 * freezers.product_id is dropped so there is a single source of truth for what
 * a freezer holds. delivery_items carries the product that was actually
 * delivered, and sales carries the product that was actually bought, which is
 * also what makes a different price per product possible.
 *
 * One ball stays 10 kg regardless of product, so the load cell maths, the
 * capacity and the suggested delivery are untouched by this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Added as nullable first so the existing rows can be backfilled from
        // the freezer's old product before the constraint and the NOT NULL are
        // applied. Doing it in one step would fail the foreign key on any
        // database that already has delivery history.
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('freezer_id')
                ->comment('Product delivered to this freezer (one row per product per freezer)');
        });

        // A sale can outlive the product it referred to, so this one stays
        // nullable and nulls out on delete. A delivery cannot: a delivery row
        // with no product would be a delivery of nothing.
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('freezer_id')
                ->comment('Product sold, needed because every product has its own price');
        });

        $this->backfillProductFromFreezer();

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['freezer_id', 'product_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index(['store_id', 'status']);
        });

        // The backfill has filled every existing row, so the column can be
        // tightened to NOT NULL now that it is safe to do so.
        //
        // A row stays null only if its freezer had no product either, which is
        // possible on a database that already had that gap. Forcing NOT NULL
        // there would abort the whole migration over rows nobody can fix, so the
        // constraint is only applied when the backfill actually completed.
        if (DB::table('delivery_items')->whereNull('product_id')->doesntExist()) {
            Schema::table('delivery_items', function (Blueprint $table) {
                $table->foreignId('product_id')
                    ->nullable(false)
                    ->change();
            });
        }

        // A sale is now recorded by the driver, so it starts life unreviewed.
        // Only CONFIRMED counts towards what a store owes, which is why
        // PENDING has to be distinguishable from the old all-or-nothing state.
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'CONFIRMED', 'VOID'])
                ->default('PENDING')
                ->comment('PENDING = recorded by driver, awaiting admin approval')
                ->change();
        });

        Schema::table('freezers', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }

    /**
     * Carry the product that was on the freezer over to the rows that recorded
     * what happened in it. A freezer only had one product before this change,
     * so that value is the correct answer for every existing row.
     */
    private function backfillProductFromFreezer(): void
    {
        DB::statement(
            'UPDATE delivery_items
             SET product_id = (SELECT product_id FROM freezers WHERE freezers.id = delivery_items.freezer_id)
             WHERE product_id IS NULL'
        );

        DB::statement(
            'UPDATE sales
             SET product_id = (SELECT product_id FROM freezers WHERE freezers.id = sales.freezer_id)
             WHERE product_id IS NULL'
        );
    }

    public function down(): void
    {
        // Reverse order of up(): the freezer column comes back first because it
        // is the value the backfill reads, then the tables that hold the product
        // themselves.
        //
        // Every freezer goes back to carrying one product, so a single value has
        // to be picked again. This has to run while delivery_items and sales
        // still hold their product_id, because that is where the answer comes
        // from: the product of the most recent delivery, or failing that the
        // most recent sale. Anything with neither stays null, which the old
        // column allowed.
        //
        // This direction loses information and cannot be undone. A freezer that
        // held two products collapses to whichever one was recorded last, and
        // rolling forward again stamps that single product onto every one of its
        // delivery items and sales. Roll back on a database whose multi-product
        // history matters and the earlier products are gone.
        Schema::table('freezers', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('store_id')
                ->comment('Product FK (ice type)');
        });

        DB::statement(
            'UPDATE freezers
             SET product_id = COALESCE(
                 (SELECT d.product_id
                    FROM delivery_items d
                   WHERE d.freezer_id = freezers.id AND d.product_id IS NOT NULL
                   ORDER BY d.id DESC LIMIT 1),
                 (SELECT s.product_id
                    FROM sales s
                   WHERE s.freezer_id = freezers.id AND s.product_id IS NOT NULL
                   ORDER BY s.id DESC LIMIT 1)
             )
             WHERE product_id IS NULL'
        );

        Schema::table('freezers', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        // Order matters here, and each step is its own statement group.
        //
        // MySQL will not drop an index while a foreign key still depends on it.
        // The composite index added by up() is the only index here that starts
        // with freezer_id, so InnoDB treats it as the index backing the freezer
        // foreign key and refuses to drop it even after the product foreign key
        // is gone. The freezer foreign key therefore goes off and back on around
        // the index removal.
        //
        // Dropping only product_id is not enough either: MySQL leaves a
        // multi-column index in place when one of its columns goes, so a later
        // up() would fail on the duplicate index name.
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropForeign(['freezer_id']);
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropIndex(['freezer_id', 'product_id']);
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn('product_id');
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->foreign('freezer_id')->references('id')->on('freezers')->restrictOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
            $table->dropIndex(['store_id', 'status']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('status', ['CONFIRMED', 'VOID'])
                ->default('CONFIRMED')
                ->change();
        });
    }
};
