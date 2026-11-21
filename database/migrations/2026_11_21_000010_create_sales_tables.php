<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All sale amounts are in the business base currency.
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // null = walk-in
            $table->string('reference_no', 30); // HLD-... while held, INV-... once completed
            $table->string('status', 12)->default('completed'); // held | completed | voided
            $table->decimal('subtotal', 18, 4)->default(0);      // net of tax, after line discounts
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);    // money kept (tendered minus change)
            $table->decimal('change_due', 18, 4)->default(0);
            $table->decimal('balance_due', 18, 4)->default(0);   // sold on credit
            $table->decimal('refunded_cash', 18, 4)->default(0); // cash/bank refunds already paid out for returns
            $table->decimal('cogs_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('unpaid'); // paid | partial | unpaid
            $table->dateTime('sold_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
            $table->index(['business_id', 'status', 'sold_at']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'branch_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('name', 200);  // snapshots: receipts never change when the catalogue does
            $table->string('sku', 60)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('net_amount', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->json('tax_snapshot')->nullable();
            $table->decimal('cost_total', 18, 4)->default(0); // cost of goods sold for the whole line
            $table->boolean('tracks_stock')->default(true);
            $table->decimal('returned_qty', 18, 4)->default(0);
            $table->decimal('refunded_total', 18, 4)->default(0);
            $table->decimal('returned_cost', 18, 4)->default(0);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 12);
            $table->decimal('amount', 18, 4);   // amount kept (change already deducted from cash)
            $table->string('reference', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('refund_method', 12); // account | cash | bank | card | cheque | other
            $table->decimal('refund_total', 18, 4)->default(0);
            $table->date('return_date');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('refund_amount', 18, 4);
            $table->decimal('cost_amount', 18, 4)->default(0);
        });
    }

    public function down(): void
    {
        foreach (['sale_return_items', 'sale_returns', 'sale_payments', 'sale_items', 'sales'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
