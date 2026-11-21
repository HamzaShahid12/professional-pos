<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Exports in the same column layout the importer reads, so an export can be edited and re-imported. */
class ProductsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Product::query()->with(['category', 'brand', 'unit', 'taxes'])->orderBy('name');
    }

    public function headings(): array
    {
        return ['name', 'sku', 'barcode', 'category', 'brand', 'unit', 'purchase_price', 'selling_price',
            'wholesale_price', 'min_selling_price', 'min_stock', 'tax', 'description', 'active'];
    }

    public function map($product): array
    {
        return [
            $product->name, $product->sku, $product->barcode, $product->category?->name, $product->brand?->name,
            $product->unit?->short_name, $product->purchase_price, $product->selling_price, $product->wholesale_price,
            $product->min_selling_price, $product->min_stock, $product->taxes->pluck('name')->implode(', '),
            $product->description, $product->is_active ? 'yes' : 'no',
        ];
    }
}
