<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFiscalIdentity;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatoFacturacionRequest extends FormRequest
{
    use ValidatesFiscalIdentity;

    protected function prepareForValidation(): void
    {
        $this->merge($this->inferFiscalFields());
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo_persona'      => 'required|string|max:50',
            'razon_social'      => 'nullable|required_if:tipo_persona,juridica|string|max:255',
            'nombre_completo'   => 'nullable|required_if:tipo_persona,fisica|string|max:255',
            'tipo_documento'    => 'nullable|string|in:DNI',
            'numero_documento'  => 'nullable|string|max:50',
            'cuit'              => 'nullable|string|max:50',
            'condicion_iva'     => 'required|string|max:100',
            'email_facturacion' => 'nullable|email|max:255',
            'provincia'         => 'required|string|max:255',
            'localidad'         => 'required|string|max:255',
            'codigo_postal'     => 'required|string|max:50',
            'calle'             => 'required|string|max:255',
            'numero'            => 'required|string|max:50',
            'piso'              => 'nullable|string|max:50',
            'departamento'      => 'nullable|string|max:50',
            'principal'         => 'boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $this->validateFiscalIdentity($validator, true);
    }
}
