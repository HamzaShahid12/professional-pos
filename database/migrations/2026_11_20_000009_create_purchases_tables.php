<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Amounts without a suffix are in the document currency; *_base are in the business base currency.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1); // base units per 1 unit of the document currency, fixed when received
            $table->string('status', 12)->default('ordered');    // ordered | received | cancelled
            $table->date('order_date');
            $table->date('received_at')->nullable();
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->decimal('subtotal', 18, 4)->default(0);       // net of tax, after line discounts
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('total_base', 18, 4)->default(0);
            $table->decimal('paid_base', 18, 4)->default(0);      // paid at receiving; later payments go through the supplier ledger
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
            $table->index(['business_id', 'supplier_id', 'status']);
            $table->index(['business_id', 'order_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);       // as entered (tax-inclusive or not, per the product's taxes)
            $table->decimal('discount', 18, 4)->default(0); // amount off this line
            $table->decimal('net_amount', 18, 4);      // line net of tax
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);      // net + tax
            $table->decimal('line_total_base', 18, 4)->default(0);
            $table->decimal('net_unit_cost_base', 18, 4)->default(0); // stock cost per unit, set on receiving
            $table->json('tax_snapshot')->nullable();
            $table->string('batch_number', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('returned_qty', 18, 4)->default(0);
            $table->decimal('credited_base', 18, 4)->default(0); // supplier credit already issued for returns of this line
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->decimal('total_base', 18, 4)->default(0); // credit given by the supplier
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('credit_base', 18, 4);
        });
    }

    public function down(): void
    {
        foreach (['purchase_return_items', 'purchase_returns', 'purchase_items', 'purchases'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
