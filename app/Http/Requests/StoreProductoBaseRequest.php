<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductoBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'          => 'required|string|max:255|unique:productos_base,sku',
            'nombre'       => 'required|string|max:255',
            'descripcion'  => 'nullable|string',
            'stock_actual' => 'integer|min:0',
            'stock_minimo' => 'integer|min:0',
            'activo'       => 'boolean',
        ];
    }
}
