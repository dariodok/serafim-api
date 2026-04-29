<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomicilioRequest;
use App\Http\Requests\UpdateDomicilioRequest;
use App\Models\Domicilio;
use App\Models\Usuario;

class AdminUsuarioDomicilioController extends Controller
{
    public function index(Usuario $usuario)
    {
        return response()->json(
            $usuario->domicilios()->orderByDesc('principal')->orderByDesc('id')->get()
        );
    }

    public function store(StoreDomicilioRequest $request, Usuario $usuario)
    {
        $payload = array_merge(['activo' => true], $request->validated());
        $domicilio = $usuario->domicilios()->create($payload);

        $this->syncPrincipal($usuario, $domicilio, (bool) ($payload['principal'] ?? false));

        return response()->json($domicilio->fresh(), 201);
    }

    public function update(UpdateDomicilioRequest $request, Usuario $usuario, Domicilio $domicilio)
    {
        $domicilio = $this->resolveDomicilio($usuario, $domicilio);
        $payload = $request->validated();

        $domicilio->update($payload);
        $this->syncPrincipal($usuario, $domicilio, (bool) ($payload['principal'] ?? $domicilio->principal));

        return response()->json($domicilio->fresh());
    }

    public function destroy(Usuario $usuario, Domicilio $domicilio)
    {
        $domicilio = $this->resolveDomicilio($usuario, $domicilio);
        $domicilio->delete();

        return response()->json(['mensaje' => 'Domicilio eliminado exitosamente']);
    }

    private function resolveDomicilio(Usuario $usuario, Domicilio $domicilio): Domicilio
    {
        abort_unless($domicilio->usuario_id === $usuario->id, 404);

        return $domicilio;
    }

    private function syncPrincipal(Usuario $usuario, Domicilio $domicilio, bool $isPrincipal): void
    {
        if (!$isPrincipal) {
            return;
        }

        $usuario->domicilios()
            ->whereKeyNot($domicilio->id)
            ->update(['principal' => false]);
    }
}
