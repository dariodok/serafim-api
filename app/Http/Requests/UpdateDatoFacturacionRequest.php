<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesFiscalIdentity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDatoFacturacionRequest extends FormRequest
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
            'tipo_persona' => 'string|max:50',
            'razon_social' => 'nullable|string|max:255',
            'nombre_completo' => 'nullable|string|max:255',
            'tipo_documento' => 'nullable|string|in:DNI',
            'numero_documento' => 'nullable|string|max:50',
            'cuit' => 'nullable|string|max:50',
            'afip_id_persona' => 'nullable|string|max:20',
            'condicion_iva' => 'string|max:100',
            'condicion_iva_receptor_id' => 'nullable|integer|min:1|max:99',
            'afip_estado_clave' => 'nullable|string|max:50',
            'afip_ultima_consulta_at' => 'nullable|date',
            'afip_datos' => 'nullable|array',
            'afip_consulta_fiscal_id' => 'nullable|exists:afip_consultas_fiscales,id',
            'email_facturacion' => 'nullable|email|max:255',
            'provincia' => 'string|max:255',
            'localidad' => 'string|max:255',
            'codigo_postal' => 'string|max:50',
            'calle' => 'string|max:255',
            'numero' => 'string|max:50',
            'piso' => 'nullable|string|max:50',
            'departamento' => 'nullable|string|max:50',
            'principal' => 'boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $this->validateFiscalIdentity($validator, false);
    }
}
