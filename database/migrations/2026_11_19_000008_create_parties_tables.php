<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // balance: positive = the customer owes us (receivable); negative = we hold their advance/credit.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->decimal('credit_limit', 18, 4)->default(0); // 0 = no credit allowed
            $table->decimal('balance', 18, 4)->default(0);       // always equals SUM(party_ledger_entries.amount)
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'phone']);
        });

        // balance: positive = we owe the supplier (payable); negative = the supplier owes us (advance paid).
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('balance', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'name']);
        });

        // Immutable party ledger. `amount` is signed in the same direction as `balance`.
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('party_type', 10); // customer | supplier
            $table->unsignedBigInteger('party_id');
            $table->string('entry_type', 20); // opening | invoice | purchase | sale_return | purchase_return | payment | adjustment
            $table->date('entry_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('description', 255)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'party_type', 'party_id', 'id'], 'ledger_party_lookup');
            $table->index(['reference_type', 'reference_id']);
        });

        // Money received from a customer (RCP-) or paid to a supplier (PAY-). Posts one ledger entry.
        Schema::create('party_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 30);
            $table->string('party_type', 10);
            $table->unsignedBigInteger('party_id');
            $table->decimal('amount', 18, 4);
            $table->string('method', 12); // cash | bank | card | cheque | other
            $table->string('method_reference', 100)->nullable(); // cheque / transaction number
            $table->date('paid_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference_no']);
            $table->index(['business_id', 'party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        foreach (['party_payments', 'party_ledger_entries', 'suppliers', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
