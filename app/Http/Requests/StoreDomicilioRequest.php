<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomicilioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autenticación manejada por el middleware
    }

    public function rules(): array
    {
        return [
            'alias'             => 'nullable|string|max:255',
            'destinatario'      => 'required|string|max:255',
            'telefono_contacto' => 'nullable|string|max:255',
            'provincia'         => 'required|string|max:255',
            'localidad'         => 'required|string|max:255',
            'codigo_postal'     => 'required|string|max:50',
            'calle'             => 'required|string|max:255',
            'numero'            => 'required|string|max:50',
            'piso'              => 'nullable|string|max:50',
            'departamento'      => 'nullable|string|max:50',
            'referencia'        => 'nullable|string',
            'principal'         => 'boolean',
        ];
    }
}
