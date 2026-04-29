<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteFacturacion;

class AdminComprobanteController extends Controller
{
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));

        $comprobantes = ComprobanteFacturacion::with(['venta.usuario', 'datosFacturacion'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($comprobantes);
    }

    public function show(string $id)
    {
        return response()->json(
            ComprobanteFacturacion::with(['venta.usuario', 'datosFacturacion'])
                ->findOrFail($id)
        );
    }
}
