<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->business_id)) {
                $businessId = $context->businessId();
                if (! $businessId) {
                    throw new LogicException(class_basename($model).' cannot be created without a business context.');
                }
                $model->business_id = $businessId;
            } elseif (! $context->isUnscoped() && $context->businessId() && (int) $model->business_id !== $context->businessId()) {
                throw new LogicException('Cross-business write blocked for '.class_basename($model).'.');
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
