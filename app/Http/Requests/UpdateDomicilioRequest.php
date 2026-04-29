<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomicilioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorize en el controller verificará la propiedad
    }

    public function rules(): array
    {
        return [
            'alias'             => 'nullable|string|max:255',
            'destinatario'      => 'string|max:255',
            'telefono_contacto' => 'nullable|string|max:255',
            'provincia'         => 'string|max:255',
            'localidad'         => 'string|max:255',
            'codigo_postal'     => 'string|max:50',
            'calle'             => 'string|max:255',
            'numero'            => 'string|max:50',
            'piso'              => 'nullable|string|max:50',
            'departamento'      => 'nullable|string|max:50',
            'referencia'        => 'nullable|string',
            'principal'         => 'boolean',
        ];
    }
}
