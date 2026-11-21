<?php

namespace App\Rules;

use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * SKU / barcode must be unique across products AND variants of the current business.
 * Soft-deleted (archived) rows still count, matching the database unique indexes.
 */
class UniqueCode implements ValidationRule
{
    public function __construct(
        private string $column,
        private ?int $ignoreProductId = null,
        private ?int $ignoreVariantId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $businessId = app(TenantContext::class)->businessId();

        $inProducts = DB::table('products')->where('business_id', $businessId)->where($this->column, $value)
            ->when($this->ignoreProductId, fn ($q) => $q->where('id', '!=', $this->ignoreProductId))->exists();
        $inVariants = DB::table('product_variants')->where('business_id', $businessId)->where($this->column, $value)
            ->when($this->ignoreVariantId, fn ($q) => $q->where('id', '!=', $this->ignoreVariantId))->exists();

        if ($inProducts || $inVariants) {
            $fail('This :attribute is already used by another product or variant.');
        }
    }
}
