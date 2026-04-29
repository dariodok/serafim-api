<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTelefonoRequest;
use App\Http\Requests\UpdateTelefonoRequest;
use App\Models\Telefono;
use App\Models\Usuario;

class AdminUsuarioTelefonoController extends Controller
{
    public function index(Usuario $usuario)
    {
        return response()->json(
            $usuario->telefonos()->orderByDesc('principal')->orderByDesc('id')->get()
        );
    }

    public function store(StoreTelefonoRequest $request, Usuario $usuario)
    {
        $payload = array_merge(['activo' => true], $request->validated());
        $telefono = $usuario->telefonos()->create($payload);

        $this->syncPrincipal($usuario, $telefono, (bool) ($payload['principal'] ?? false));

        return response()->json($telefono->fresh(), 201);
    }

    public function update(UpdateTelefonoRequest $request, Usuario $usuario, Telefono $telefono)
    {
        $telefono = $this->resolveTelefono($usuario, $telefono);
        $payload = $request->validated();

        $telefono->update($payload);
        $this->syncPrincipal($usuario, $telefono, (bool) ($payload['principal'] ?? $telefono->principal));

        return response()->json($telefono->fresh());
    }

    public function destroy(Usuario $usuario, Telefono $telefono)
    {
        $telefono = $this->resolveTelefono($usuario, $telefono);
        $telefono->delete();

        return response()->json(['mensaje' => 'Telefono eliminado exitosamente']);
    }

    private function resolveTelefono(Usuario $usuario, Telefono $telefono): Telefono
    {
        abort_unless($telefono->usuario_id === $usuario->id, 404);

        return $telefono;
    }

    private function syncPrincipal(Usuario $usuario, Telefono $telefono, bool $isPrincipal): void
    {
        if (!$isPrincipal) {
            return;
        }

        $usuario->telefonos()
            ->whereKeyNot($telefono->id)
            ->update(['principal' => false]);
    }
}
