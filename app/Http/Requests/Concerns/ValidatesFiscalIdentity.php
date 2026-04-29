<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesFiscalIdentity
{
    protected function inferFiscalFields(): array
    {
        $cuit = $this->onlyDigits((string) $this->input('cuit', ''));
        $dni = $this->onlyDigits((string) $this->input('numero_documento', ''));
        $payload = [];

        if ($this->has('cuit')) {
            $payload['cuit'] = $cuit !== '' ? $cuit : null;
        }

        if ($cuit !== '' && strlen($cuit) === 11) {
            $prefijo = (int) substr($cuit, 0, 2);

            if ($prefijo >= 30) {
                $payload['tipo_persona'] = 'juridica';
                $payload['tipo_documento'] = null;
                $payload['numero_documento'] = null;
            } else {
                $payload['tipo_persona'] = 'fisica';
                $payload['tipo_documento'] = 'DNI';
                $payload['numero_documento'] = $this->normalizeDocumentNumber(substr($cuit, 2, 8));
            }

            return $payload;
        }

        if ($dni !== '') {
            $payload['tipo_persona'] = 'fisica';
            $payload['tipo_documento'] = 'DNI';
            $payload['numero_documento'] = $this->normalizeDocumentNumber($dni);
        }

        return $payload;
    }

    protected function validateFiscalIdentity(Validator $validator, bool $strict = true): void
    {
        $validator->after(function (Validator $validator) use ($strict) {
            $tipoDocumento = $this->input('tipo_documento');
            $numeroDocumento = (string) $this->input('numero_documento', '');
            $cuit = (string) $this->input('cuit', '');
            $tipoPersona = $this->input('tipo_persona');
            $normalizedDni = $this->onlyDigits($numeroDocumento);

            if ($strict && $this->onlyDigits($cuit) === '' && $normalizedDni === '') {
                $validator->errors()->add('cuit', 'Ingresa un CUIT/CUIL o un DNI.');
            }

            if ($tipoPersona === 'juridica' && $this->onlyDigits($cuit) === '') {
                $validator->errors()->add('cuit', 'Para una persona juridica debes informar un CUIT.');
            }

            if ($tipoPersona === 'fisica' && ($strict || $this->has('numero_documento') || $this->has('cuit'))) {
                if ($tipoDocumento === 'DNI') {
                    if ($normalizedDni === '' || strlen($normalizedDni) < 7) {
                        $validator->errors()->add('numero_documento', 'El DNI debe tener al menos 7 numeros.');
                    }
                } else {
                    $validator->errors()->add('numero_documento', 'Ingresa un DNI valido.');
                }
            }

            if (($strict || $this->has('cuit')) && $cuit !== '') {
                $normalizedCuit = $this->onlyDigits($cuit);

                if (!$this->isValidCuitCuil($normalizedCuit)) {
                    $validator->errors()->add('cuit', 'Ingresa un CUIT o CUIL valido.');
                }

                if (
                    $tipoPersona === 'fisica'
                    && $tipoDocumento === 'DNI'
                    && $normalizedDni !== ''
                    && $normalizedCuit !== ''
                    && $this->isValidCuitCuil($normalizedCuit)
                ) {
                    $dni = str_pad($normalizedDni, 8, '0', STR_PAD_LEFT);

                    if (substr($normalizedCuit, 2, 8) !== $dni) {
                        $validator->errors()->add('cuit', 'En una persona fisica, el CUIT o CUIL debe corresponder al DNI informado.');
                    }
                }
            }
        });
    }

    protected function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    protected function normalizeDocumentNumber(string $value): string
    {
        $normalized = ltrim($this->onlyDigits($value), '0');

        return $normalized === '' ? '0' : $normalized;
    }

    protected function isValidCuitCuil(string $value): bool
    {
        if (!preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $value[$index]) * $weight;
        }

        $mod = 11 - ($sum % 11);
        $checkDigit = $mod === 11 ? 0 : ($mod === 10 ? 9 : $mod);

        return $checkDigit === (int) $value[10];
    }
}
