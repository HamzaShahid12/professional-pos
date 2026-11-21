<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->text('description')->nullable();
            // Prices are in the business BASE currency. 4 decimals keeps unit costs exact.
            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->decimal('wholesale_price', 18, 4)->nullable();
            $table->decimal('min_selling_price', 18, 4)->nullable();
            $table->decimal('min_stock', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('has_variants')->default(false);
            $table->softDeletes();
            $table->timestamps();

            // Archived (soft-deleted) products keep their SKU/barcode reserved so history stays unambiguous.
            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'category_id']);
            $table->index(['business_id', 'brand_id']);
        });

        Schema::create('product_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained()->cascadeOnDelete();

            $table->unique(['product_id', 'tax_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120); // e.g. "Red / L"
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            // null = inherit the parent product's price
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->decimal('wholesale_price', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'sku']);
            $table->unique(['business_id', 'barcode']);
            $table->index('product_id');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // Append-only price audit trail.
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('price_type', 24); // purchase_price | selling_price | wholesale_price | min_selling_price
            $table->decimal('old_price', 18, 4)->nullable();
            $table->decimal('new_price', 18, 4)->nullable(); // null = price cleared
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_taxes');
        Schema::dropIfExists('products');
    }
};
