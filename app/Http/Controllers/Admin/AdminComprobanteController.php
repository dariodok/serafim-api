<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteFacturacion;
use App\Models\Venta;
use App\Services\Afip\AfipBillingException;
use App\Services\Afip\AfipElectronicBillingService;
use Illuminate\Http\Request;

class AdminComprobanteController extends Controller
{
    public function __construct(private readonly AfipElectronicBillingService $afipBilling) {}

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $comprobantes = ComprobanteFacturacion::with(['venta.usuario', 'datosFacturacion', 'comprobanteAsociado'])
            ->when(request('estado'), fn ($query, string $estado) => $query->where('estado', $estado))
            ->when(request('tipo_comprobante'), fn ($query, string $tipo) => $query->where('tipo_comprobante', $tipo))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($subquery) use ($search) {
                    $subquery
                        ->where('numero_comprobante', 'like', "%{$search}%")
                        ->orWhere('cae', 'like', "%{$search}%")
                        ->orWhere('cuit', 'like', "%{$search}%")
                        ->orWhere('numero_documento', 'like', "%{$search}%")
                        ->orWhere('razon_social', 'like', "%{$search}%")
                        ->orWhere('nombre_completo', 'like', "%{$search}%")
                        ->orWhereHas('venta', fn ($ventaQuery) => $ventaQuery->where('numero_venta', 'like', "%{$search}%"))
                        ->orWhereHas('venta.usuario', function ($usuarioQuery) use ($search) {
                            $usuarioQuery
                                ->where('nombre', 'like', "%{$search}%")
                                ->orWhere('apellido', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($comprobantes);
    }

    public function show(string $id)
    {
        return response()->json(
            ComprobanteFacturacion::with(['venta.usuario', 'datosFacturacion', 'comprobanteAsociado', 'notasCredito'])
                ->findOrFail($id)
        );
    }

    public function emitirFacturaVenta(Request $request, string $ventaId)
    {
        $data = $request->validate([
            'tipo_comprobante' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $comprobante = $this->afipBilling->emitInvoice(
                Venta::with(['datosFacturacion', 'pagos', 'comprobantes'])->findOrFail($ventaId),
                $data['tipo_comprobante'] ?? null,
            );
        } catch (AfipBillingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'comprobante' => $exception->comprobante(),
            ], 422);
        }

        return response()->json($comprobante, $comprobante->wasRecentlyCreated ? 201 : 200);
    }

    public function emitirNotaCreditoTotal(Request $request, string $id)
    {
        $data = $request->validate([
            'tipo_comprobante' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $comprobante = $this->afipBilling->emitFullCreditNote(
                ComprobanteFacturacion::with(['venta.datosFacturacion', 'venta.comprobantes'])->findOrFail($id),
                $data['tipo_comprobante'] ?? null,
            );
        } catch (AfipBillingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'comprobante' => $exception->comprobante(),
            ], 422);
        }

        return response()->json($comprobante, $comprobante->wasRecentlyCreated ? 201 : 200);
    }
}
