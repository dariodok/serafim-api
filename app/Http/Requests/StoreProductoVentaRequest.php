<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductoVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'         => 'required|string|max:255|unique:productos_venta,sku',
            'nombre'      => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio'      => 'required|numeric|min:0',
            'peso_gramos' => 'nullable|integer|min:0',
            'alto_cm'     => 'nullable|numeric|min:0',
            'ancho_cm'    => 'nullable|numeric|min:0',
            'largo_cm'    => 'nullable|numeric|min:0',
            'activo'      => 'boolean',
            'visible'     => 'boolean',
        ];
    }
}
