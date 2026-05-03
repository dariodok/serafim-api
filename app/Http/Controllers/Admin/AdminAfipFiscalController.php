<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatoFacturacion;
use App\Models\Usuario;
use App\Services\Afip\AfipFiscalConsultationService;
use Illuminate\Http\Request;

class AdminAfipFiscalController extends Controller
{
    public function lookup(Request $request, AfipFiscalConsultationService $consultations)
    {
        $data = $request->validate([
            'documento' => ['required', 'string', 'max:20'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'usuario_id' => ['nullable', 'exists:usuarios,id'],
            'datos_facturacion_id' => ['nullable', 'exists:datos_facturacion,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        $usuario = isset($data['usuario_id']) ? Usuario::find($data['usuario_id']) : null;
        $datoFacturacion = null;

        if (isset($data['datos_facturacion_id'])) {
            $datoQuery = DatoFacturacion::query();

            if ($usuario) {
                $datoQuery->where('usuario_id', $usuario->id);
            }

            $datoFacturacion = $datoQuery->find($data['datos_facturacion_id']);
        }

        $result = $consultations->lookupByDocument(
            document: $data['documento'],
            name: $data['nombre'] ?? null,
            usuario: $usuario,
            datoFacturacion: $datoFacturacion,
            force: (bool) ($data['force'] ?? false),
        );

        return response()->json([
            'consulta' => $result['consulta'],
            'candidates' => $result['candidates'],
            'cached' => $result['cached'],
            'message' => $result['error'],
        ], $result['error'] ? 422 : 200);
    }

    public function usuarioHistory(Usuario $usuario)
    {
        return response()->json(
            $usuario->afipConsultasFiscales()
                ->with('datosFacturacion:id,usuario_id,alias,razon_social,nombre_completo,cuit,numero_documento,condicion_iva')
                ->orderByDesc('consultado_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
        );
    }

    public function refreshUsuario(Request $request, Usuario $usuario, AfipFiscalConsultationService $consultations)
    {
        $data = $request->validate([
            'datos_facturacion_id' => ['nullable', 'exists:datos_facturacion,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? true);

        if (! empty($data['datos_facturacion_id'])) {
            $datoFacturacion = $usuario->datosFacturacion()->findOrFail($data['datos_facturacion_id']);

            try {
                $consulta = $consultations->refreshDatoFacturacion($datoFacturacion, $force, true);
            } catch (\Throwable $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return response()->json([
                'consultas' => $consulta ? [$consulta->fresh()] : [],
                'datos_facturacion' => [$datoFacturacion->fresh()],
            ]);
        }

        try {
            $results = $consultations->refreshUsuario($usuario, $force, true);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'consultas' => collect($results)->pluck('consulta')->filter()->values(),
            'datos_facturacion' => $usuario->datosFacturacion()->orderByDesc('principal')->orderByDesc('id')->get(),
        ]);
    }
}
