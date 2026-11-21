<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? ['id' => $this->unit->id, 'name' => $this->unit->name, 'short_name' => $this->unit->short_name, 'allow_decimal' => $this->unit->allow_decimal] : null),
            // Money is always a string (exact decimal), in the business base currency.
            'purchase_price' => (string) $this->purchase_price,
            'selling_price' => (string) $this->selling_price,
            'wholesale_price' => $this->wholesale_price,
            'min_selling_price' => $this->min_selling_price,
            'min_stock' => (string) $this->min_stock,
            'is_active' => $this->is_active,
            'track_inventory' => $this->track_inventory,
            'track_expiry' => $this->track_expiry,
            'has_variants' => $this->has_variants,
            'image_url' => $this->whenLoaded('primaryImage', fn () => $this->image_url),
            'taxes' => $this->whenLoaded('taxes', fn () => $this->taxes->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => $t->is_inclusive,
            ])->values()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'barcode' => $v->barcode,
                'purchase_price' => $v->purchase_price, 'selling_price' => $v->selling_price,
                'wholesale_price' => $v->wholesale_price, 'is_active' => $v->is_active,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
