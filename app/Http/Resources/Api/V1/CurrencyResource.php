<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Currency */
class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimal_places,
            'exchange_rate' => (string) $this->exchange_rate, // string: never a float
            'is_base' => $this->is_base,
            'is_active' => $this->is_active,
            'history' => $this->whenLoaded('rates', fn () => $this->rates->map(fn ($r) => [
                'rate' => (string) $r->rate,
                'effective_at' => $r->effective_at?->toIso8601String(),
                'source' => $r->source,
            ])->values()),
        ];
    }
}
