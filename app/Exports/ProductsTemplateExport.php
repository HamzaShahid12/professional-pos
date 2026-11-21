<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['name', 'sku', 'barcode', 'category', 'brand', 'unit', 'purchase_price', 'selling_price',
            'wholesale_price', 'min_selling_price', 'min_stock', 'tax', 'description', 'active'];
    }

    public function array(): array
    {
        return [['Sample Product', 'SKU-001', '8901234567890', 'Beverages', 'Acme', 'pcs', '80', '100', '95', '90', '10', '', 'Optional text', 'yes']];
    }
}
