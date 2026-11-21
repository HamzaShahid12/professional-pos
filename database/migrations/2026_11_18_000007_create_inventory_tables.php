<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free, per-business document numbers (ADJ-000001, TRF-000001, later INV-000001...).
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'doc_type']);
        });

        // Current stock per warehouse/product/variant. `total_value` is the BOOK value of that stock and always
        // equals SUM(stock_movements.total_cost) for the same key, so valuation reconciles with the ledger.
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // variant_id or 0: lets the unique index work even though NULLs are distinct in MySQL.
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('total_value', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id', 'variant_key']);
            $table->index(['business_id', 'product_id']);
        });

        // Cost layers / batches. Used for FIFO costing and for batch + expiry tracking (first-expired-first-out).
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('batch_number', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 18, 4)->default(0); // remaining
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'variant_id', 'quantity'], 'batches_lookup');
            $table->index(['business_id', 'expiry_date']);
        });

        // Immutable stock ledger: one row per quantity change (per cost layer for outbound movements).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            // opening | purchase_received | sale | sale_return | purchase_return | adjustment_in | adjustment_out | transfer_out | transfer_in
            $table->string('type', 24);
            $table->decimal('quantity', 18, 4);      // signed: + in, - out
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0); // signed like quantity
            $table->decimal('balance_after', 18, 4);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'product_id', 'warehouse_id', 'created_at'], 'movements_lookup');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['business_id', 'type']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->string('direction', 3);   // in | out
            $table->string('reason', 24);     // opening_stock | correction | found | damaged | expired | other
            $table->date('adjusted_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
        });

        // Single-step transfers: stock leaves the source and enters the destination in one transaction.
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('status', 16)->default('completed');
            $table->date('transferred_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft'); // draft | completed | cancelled
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->decimal('system_qty', 18, 4)->default(0);   // snapshot when the count was created
            $table->decimal('expected_qty', 18, 4)->nullable(); // live system quantity at completion
            $table->decimal('counted_qty', 18, 4)->nullable();  // entered by the counter; null = not counted
            $table->decimal('difference', 18, 4)->nullable();   // counted - expected, set on completion

            $table->unique(['stock_count_id', 'product_id', 'variant_key']);
        });
    }

    public function down(): void
    {
        foreach (['stock_count_items', 'stock_counts', 'stock_transfer_items', 'stock_transfers', 'stock_adjustment_items',
            'stock_adjustments', 'stock_movements', 'product_batches', 'stocks', 'document_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
