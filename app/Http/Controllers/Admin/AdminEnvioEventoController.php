<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use App\Models\EnvioEvento;
use Illuminate\Http\Request;

class AdminEnvioEventoController extends Controller
{
    public function index(Envio $envio)
    {
        return response()->json(
            $envio->eventos()->orderByDesc('ocurrio_en')->orderByDesc('id')->get()
        );
    }

    public function store(Request $request, Envio $envio)
    {
        $data = $request->validate($this->rules());
        $data['origen'] = $data['origen'] ?? 'admin';

        $evento = $envio->eventos()->create($data);

        return response()->json($evento, 201);
    }

    public function update(Request $request, Envio $envio, string $evento)
    {
        $envioEvento = $this->resolveEvento($envio, $evento);
        $data = $request->validate($this->rules(true));

        $envioEvento->update($data);

        return response()->json($envioEvento->fresh());
    }

    public function destroy(Envio $envio, string $evento)
    {
        $envioEvento = $this->resolveEvento($envio, $evento);
        $envioEvento->delete();

        return response()->json(['ok' => true]);
    }

    private function resolveEvento(Envio $envio, string $eventoId): EnvioEvento
    {
        return $envio->eventos()->findOrFail($eventoId);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'estado' => "{$required}|string|max:255",
            'origen' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string',
            'payload' => 'nullable|array',
            'ocurrio_en' => "{$required}|date",
        ];
    }
}
