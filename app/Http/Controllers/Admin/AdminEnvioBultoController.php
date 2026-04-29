<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use App\Models\EnvioBulto;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminEnvioBultoController extends Controller
{
    public function index(Envio $envio)
    {
        return response()->json(
            $envio->bultos()->orderBy('numero_bulto')->get()
        );
    }

    public function store(Request $request, Envio $envio)
    {
        $data = $request->validate($this->rules($envio));

        $bulto = $envio->bultos()->create($data);

        return response()->json($bulto, 201);
    }

    public function update(Request $request, Envio $envio, string $bulto)
    {
        $envioBulto = $this->resolveBulto($envio, $bulto);
        $data = $request->validate($this->rules($envio, $envioBulto, true));

        $envioBulto->update($data);

        return response()->json($envioBulto->fresh());
    }

    public function destroy(Envio $envio, string $bulto)
    {
        $envioBulto = $this->resolveBulto($envio, $bulto);
        $envioBulto->delete();

        return response()->json(['ok' => true]);
    }

    private function resolveBulto(Envio $envio, string $bultoId): EnvioBulto
    {
        return $envio->bultos()->findOrFail($bultoId);
    }

    private function rules(Envio $envio, ?EnvioBulto $bulto = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'numero_bulto' => [
                $required,
                'integer',
                'min:1',
                Rule::unique('envio_bultos', 'numero_bulto')
                    ->where(fn ($query) => $query->where('envio_id', $envio->id))
                    ->ignore($bulto?->id),
            ],
            'estado' => "{$required}|string|max:255",
            'referencia_externa' => 'nullable|string|max:255',
            'codigo_seguimiento' => 'nullable|string|max:255',
            'codigo_bulto' => 'nullable|string|max:255',
            'url_etiqueta' => 'nullable|string|max:255',
            'archivo_etiqueta' => 'nullable|string|max:255',
            'formato_etiqueta' => 'nullable|string|max:255',
            'valor_declarado' => 'nullable|numeric|min:0',
            'peso_gramos' => 'nullable|integer|min:0',
            'alto_cm' => 'nullable|numeric|min:0',
            'ancho_cm' => 'nullable|numeric|min:0',
            'largo_cm' => 'nullable|numeric|min:0',
            'respuesta_ultima_api' => 'nullable|array',
            'datos_adicionales' => 'nullable|array',
        ];
    }
}
