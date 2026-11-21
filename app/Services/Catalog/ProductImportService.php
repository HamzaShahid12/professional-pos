<?php

namespace App\Services\Catalog;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports products from parsed spreadsheet rows (heading-row keys: name, sku, barcode, category, brand, unit,
 * purchase_price, selling_price, wholesale_price, min_selling_price, min_stock, tax, description, active).
 *
 * All-or-nothing: every row is validated first; if any row is bad NOTHING is written and the errors are returned
 * per spreadsheet row number. Rows whose SKU already exists update that product (price changes are recorded in history).
 */
class ProductImportService
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'name', 'sku', 'barcode', 'category', 'brand', 'unit', 'purchase_price', 'selling_price',
        'wholesale_price', 'min_selling_price', 'min_stock', 'tax', 'description', 'active',
    ];

    public function __construct(private ProductService $products) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, errors: array<int, string[]>}
     */
    public function import(array $rows, User $by): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => collect($r)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty()));
        if (count($rows) > self::MAX_ROWS) {
            return ['created' => 0, 'updated' => 0, 'errors' => [1 => ['A single import is limited to '.self::MAX_ROWS.' rows.']]];
        }

        $units = Unit::query()->get();
        $taxes = Tax::query()->get();
        $existing = Product::query()->get()->keyBy(fn ($p) => mb_strtolower($p->sku));

        $errors = [];
        $prepared = [];
        $seenSkus = [];
        $seenBarcodes = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // heading row is row 1
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
            $messages = [];

            $v = Validator::make($row, [
                'name' => ['required', 'string', 'max:200'],
                'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
                'barcode' => ['nullable', 'max:64'],
                'purchase_price' => ['required', 'numeric', 'min:0'],
                'selling_price' => ['required', 'numeric', 'min:0'],
                'wholesale_price' => ['nullable', 'numeric', 'min:0'],
                'min_selling_price' => ['nullable', 'numeric', 'min:0', 'lte:selling_price'],
                'min_stock' => ['nullable', 'numeric', 'min:0'],
            ]);
            if ($v->fails()) {
                $messages = array_merge($messages, $v->errors()->all());
            }

            $sku = mb_strtolower((string) ($row['sku'] ?? ''));
            $barcode = isset($row['barcode']) && $row['barcode'] !== '' ? (string) $row['barcode'] : null;
            $current = $sku !== '' ? $existing->get($sku) : null;

            if ($sku !== '' && isset($seenSkus[$sku])) {
                $messages[] = "Duplicate SKU in file (also on row {$seenSkus[$sku]}).";
            }
            $seenSkus[$sku] = $line;
            if ($barcode !== null) {
                if (isset($seenBarcodes[$barcode])) {
                    $messages[] = "Duplicate barcode in file (also on row {$seenBarcodes[$barcode]}).";
                }
                $seenBarcodes[$barcode] = $line;
                if ($this->codeTaken('barcode', $barcode, $current?->id)) {
                    $messages[] = "Barcode {$barcode} is already used by another product or variant.";
                }
            }
            if ($sku !== '' && ! $current && $this->codeTaken('sku', (string) $row['sku'], null)) {
                $messages[] = "SKU {$row['sku']} is already used by a product variant.";
            }

            $unit = null;
            if (! empty($row['unit'])) {
                $needle = mb_strtolower((string) $row['unit']);
                $unit = $units->first(fn ($u) => mb_strtolower($u->short_name) === $needle || mb_strtolower($u->name) === $needle);
                if (! $unit) {
                    $messages[] = "Unknown unit \"{$row['unit']}\". Create it under Units first.";
                }
            } elseif (! $current) {
                $messages[] = 'Unit is required for new products.';
            }

            $taxIds = null;
            if (! empty($row['tax'])) {
                $taxIds = [];
                foreach (array_filter(array_map('trim', explode(',', (string) $row['tax']))) as $name) {
                    $tax = $taxes->first(fn ($t) => mb_strtolower($t->name) === mb_strtolower($name));
                    $tax ? $taxIds[] = $tax->id : $messages[] = "Unknown tax \"{$name}\".";
                }
            }

            if ($messages) {
                $errors[$line] = $messages;

                continue;
            }
            $prepared[] = compact('row', 'current', 'unit', 'taxIds');
        }

        if ($errors) {
            ksort($errors);

            return ['created' => 0, 'updated' => 0, 'errors' => $errors];
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($prepared, $by, &$created, &$updated) {
            foreach ($prepared as ['row' => $row, 'current' => $current, 'unit' => $unit, 'taxIds' => $taxIds]) {
                $data = [
                    'name' => $row['name'], 'sku' => $row['sku'],
                    'barcode' => ($row['barcode'] ?? '') !== '' ? (string) $row['barcode'] : null,
                    'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                    'purchase_price' => (string) $row['purchase_price'], 'selling_price' => (string) $row['selling_price'],
                    'wholesale_price' => ($row['wholesale_price'] ?? '') !== '' ? (string) $row['wholesale_price'] : null,
                    'min_selling_price' => ($row['min_selling_price'] ?? '') !== '' ? (string) $row['min_selling_price'] : null,
                    'min_stock' => ($row['min_stock'] ?? '') !== '' ? (string) $row['min_stock'] : '0',
                    'is_active' => ! in_array(mb_strtolower((string) ($row['active'] ?? 'yes')), ['no', '0', 'false', 'n'], true),
                ];
                if ($unit) {
                    $data['unit_id'] = $unit->id;
                }
                if (! empty($row['category'])) {
                    $data['category_id'] = Category::query()->firstOrCreate(['name' => $row['category'], 'parent_id' => null], ['is_active' => true])->id;
                }
                if (! empty($row['brand'])) {
                    $data['brand_id'] = Brand::query()->firstOrCreate(['name' => $row['brand']], ['is_active' => true])->id;
                }
                if ($taxIds !== null) {
                    $data['tax_ids'] = $taxIds;
                }

                if ($current) {
                    $this->products->update($current, $data, [], $by);
                    $updated++;
                } else {
                    $this->products->create($data, [], $by, 'import');
                    $created++;
                }
            }
        });

        ActivityLog::record('product.imported', null, ['created' => $created, 'updated' => $updated]);

        return ['created' => $created, 'updated' => $updated, 'errors' => []];
    }

    /** Is the value used by a product (other than $ignoreProductId) or any variant in this business? */
    private function codeTaken(string $column, string $value, ?int $ignoreProductId): bool
    {
        $inProducts = DB::table('products')->where('business_id', app(\App\Support\TenantContext::class)->businessId())
            ->where($column, $value)->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))->exists();
        $inVariants = DB::table('product_variants')->where('business_id', app(\App\Support\TenantContext::class)->businessId())
            ->where($column, $value)->exists();

        return $inProducts || $inVariants;
    }
}
