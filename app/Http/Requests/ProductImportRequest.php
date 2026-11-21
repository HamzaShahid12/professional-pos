<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Imports can create and update, so both permissions are needed.
        return $this->user()->can('products.create') && $this->user()->can('products.update');
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']];
    }
}
