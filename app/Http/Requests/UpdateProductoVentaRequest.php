<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductoVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('productos_ventum');

        return [
            'sku'         => ['string', 'max:255', Rule::unique('productos_venta', 'sku')->ignore($id)],
            'nombre'      => 'string|max:255',
            'descripcion' => 'nullable|string',
            'precio'      => 'numeric|min:0',
            'peso_gramos' => 'nullable|integer|min:0',
            'alto_cm'     => 'nullable|numeric|min:0',
            'ancho_cm'    => 'nullable|numeric|min:0',
            'largo_cm'    => 'nullable|numeric|min:0',
            'activo'      => 'boolean',
            'visible'     => 'boolean',
        ];
    }
}
