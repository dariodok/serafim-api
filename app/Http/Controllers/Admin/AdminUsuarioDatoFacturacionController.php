<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatoFacturacionRequest;
use App\Http\Requests\UpdateDatoFacturacionRequest;
use App\Models\AfipConsultaFiscal;
use App\Models\DatoFacturacion;
use App\Models\Usuario;
use App\Services\Afip\AfipFiscalConsultationService;

class AdminUsuarioDatoFacturacionController extends Controller
{
    public function __construct(private readonly AfipFiscalConsultationService $afipConsultations) {}

    public function index(Usuario $usuario)
    {
        return response()->json(
            $usuario->datosFacturacion()->orderByDesc('principal')->orderByDesc('id')->get()
        );
    }

    public function store(StoreDatoFacturacionRequest $request, Usuario $usuario)
    {
        $payload = array_merge(['activo' => true], $request->validated());
        $consultaFiscalId = $payload['afip_consulta_fiscal_id'] ?? null;
        unset($payload['afip_consulta_fiscal_id']);

        $datoFacturacion = $usuario->datosFacturacion()->create($payload);

        $this->syncPrincipal($usuario, $datoFacturacion, (bool) ($payload['principal'] ?? false));
        $this->linkAfipConsulta($usuario, $datoFacturacion, $consultaFiscalId);
        $this->afipConsultations->tryRefreshDatoFacturacion($datoFacturacion->fresh());

        return response()->json($datoFacturacion->fresh(), 201);
    }

    public function update(UpdateDatoFacturacionRequest $request, Usuario $usuario, DatoFacturacion $datoFacturacion)
    {
        $datoFacturacion = $this->resolveDatoFacturacion($usuario, $datoFacturacion);
        $payload = $request->validated();
        $consultaFiscalId = $payload['afip_consulta_fiscal_id'] ?? null;
        unset($payload['afip_consulta_fiscal_id']);

        $datoFacturacion->update($payload);
        $this->syncPrincipal($usuario, $datoFacturacion, (bool) ($payload['principal'] ?? $datoFacturacion->principal));
        $this->linkAfipConsulta($usuario, $datoFacturacion->fresh(), $consultaFiscalId);
        $this->afipConsultations->tryRefreshDatoFacturacion($datoFacturacion->fresh());

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
        if (! $isPrincipal) {
            return;
        }

        $usuario->datosFacturacion()
            ->whereKeyNot($datoFacturacion->id)
            ->update(['principal' => false]);
    }

    private function linkAfipConsulta(Usuario $usuario, DatoFacturacion $datoFacturacion, mixed $consultaFiscalId): void
    {
        if (! $consultaFiscalId) {
            return;
        }

        $consulta = AfipConsultaFiscal::query()
            ->whereKey($consultaFiscalId)
            ->where(function ($query) use ($usuario) {
                $query->whereNull('usuario_id')->orWhere('usuario_id', $usuario->id);
            })
            ->first();

        if (! $consulta) {
            return;
        }

        $selectedIdPersona = $datoFacturacion->afip_id_persona ?: $datoFacturacion->cuit;
        $selection = collect($consulta->candidatos ?? [])
            ->firstWhere('id_persona', $selectedIdPersona);

        $consulta->update([
            'usuario_id' => $usuario->id,
            'datos_facturacion_id' => $datoFacturacion->id,
            'id_persona_seleccionada' => $selectedIdPersona,
            'seleccion' => $selection,
        ]);
    }
}
