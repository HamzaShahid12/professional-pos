<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/** Base for category/brand/unit/tax/warehouse requests: policy-based authorization for create vs update. */
abstract class MasterRequest extends FormRequest
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Route parameter name that holds the bound model on update. */
    abstract protected function routeParam(): string;

    public function authorize(): bool
    {
        $model = $this->route($this->routeParam());

        return $model instanceof Model
            ? $this->user()->can('update', $model)
            : $this->user()->can('create', $this->modelClass());
    }

    protected function businessId(): ?int
    {
        return app(TenantContext::class)->businessId();
    }

    protected function ignoreId(): ?int
    {
        $model = $this->route($this->routeParam());

        return $model instanceof Model ? $model->getKey() : null;
    }
}
