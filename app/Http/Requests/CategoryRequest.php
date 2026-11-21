<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Validation\Rule;

class CategoryRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function routeParam(): string
    {
        return 'category';
    }

    public function rules(): array
    {
        $parentId = $this->input('parent_id');

        return [
            'name' => [
                'required', 'string', 'max:120',
                // Unique among siblings (same parent) within the business.
                Rule::unique('categories', 'name')->where('business_id', $this->businessId())
                    ->where(fn ($q) => $parentId ? $q->where('parent_id', $parentId) : $q->whereNull('parent_id'))
                    ->whereNull('deleted_at')->ignore($this->ignoreId()),
            ],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $this->businessId())->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
