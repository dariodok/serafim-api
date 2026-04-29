<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatoFacturacionRequest;
use App\Http\Requests\UpdateDatoFacturacionRequest;
use App\Models\DatoFacturacion;
use App\Models\Usuario;

class AdminUsuarioDatoFacturacionController extends Controller
{
    public function index(Usuario $usuario)
    {
        return response()->json(
            $usuario->datosFacturacion()->orderByDesc('principal')->orderByDesc('id')->get()
        );
    }

    public function store(StoreDatoFacturacionRequest $request, Usuario $usuario)
    {
        $payload = array_merge(['activo' => true], $request->validated());
        $datoFacturacion = $usuario->datosFacturacion()->create($payload);

        $this->syncPrincipal($usuario, $datoFacturacion, (bool) ($payload['principal'] ?? false));

        return response()->json($datoFacturacion->fresh(), 201);
    }

    public function update(UpdateDatoFacturacionRequest $request, Usuario $usuario, DatoFacturacion $datoFacturacion)
    {
        $datoFacturacion = $this->resolveDatoFacturacion($usuario, $datoFacturacion);
        $payload = $request->validated();

        $datoFacturacion->update($payload);
        $this->syncPrincipal($usuario, $datoFacturacion, (bool) ($payload['principal'] ?? $datoFacturacion->principal));

        return response()->json($datoFacturacion->fresh());
    }

    public function destroy(Usuario $usuario, DatoFacturacion $datoFacturacion)
    {
        $datoFacturacion = $this->resolveDatoFacturacion($usuario, $datoFacturacion);
        $datoFacturacion->delete();

        return response()->json(['mensaje' => 'Dato de facturacion eliminado exitosamente']);
    }

    private function resolveDatoFacturacion(Usuario $usuario, DatoFacturacion $datoFacturacion): DatoFacturacion
    {
        abort_unless($datoFacturacion->usuario_id === $usuario->id, 404);

        return $datoFacturacion;
    }

    private function syncPrincipal(Usuario $usuario, DatoFacturacion $datoFacturacion, bool $isPrincipal): void
    {
        if (!$isPrincipal) {
            return;
        }

        $usuario->datosFacturacion()
            ->whereKeyNot($datoFacturacion->id)
            ->update(['principal' => false]);
    }
}
