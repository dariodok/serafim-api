<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelefonoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo'      => 'nullable|string|max:50',
            'numero'    => 'string|max:50',
            'principal' => 'boolean',
        ];
    }
}
