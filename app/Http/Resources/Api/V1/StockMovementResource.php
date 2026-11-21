<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'product_id' => $this->product_id,
            'product' => $this->product?->name,
            'sku' => $this->product?->sku,
            'variant_id' => $this->variant_id,
            'warehouse_id' => $this->warehouse_id,
            'batch_id' => $this->batch_id,
            'quantity' => (string) $this->quantity,
            'unit_cost' => (string) $this->unit_cost,
            'total_cost' => (string) $this->total_cost,
            'balance_after' => (string) $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
