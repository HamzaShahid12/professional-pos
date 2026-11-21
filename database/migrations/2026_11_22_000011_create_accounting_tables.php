<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 12);                 // asset | liability | equity | revenue | expense
            $table->string('system_key', 40)->nullable(); // how the code finds it (cash, receivable ...); survives renumbering
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'system_key']);
            $table->index(['business_id', 'type']);
        });

        // Immutable: a mistake is fixed with a reversing entry, never by editing.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_no', 30);
            $table->date('entry_date');
            $table->string('description', 255);
            $table->string('kind', 10)->default('auto'); // auto | manual | reversal
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->decimal('total', 18, 4);              // sum of debits (= sum of credits)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['business_id', 'entry_no']);
            $table->index(['business_id', 'entry_date']);
            $table->index(['business_id', 'source_type', 'source_id']);
            $table->unique('reverses_id'); // an entry can be reversed once
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'account_id']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
