<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductoBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('productos_base');

        return [
            'sku'          => ['string', 'max:255', Rule::unique('productos_base', 'sku')->ignore($id)],
            'nombre'       => 'string|max:255',
            'descripcion'  => 'nullable|string',
            'stock_actual' => 'integer|min:0',
            'stock_minimo' => 'integer|min:0',
            'activo'       => 'boolean',
        ];
    }
}
