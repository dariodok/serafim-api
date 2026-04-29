<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ClienteVentaController extends Controller
{
    public function index(Request $request)
    {
        $ventas = $request->user()->ventas()
            ->with(['detalles.productoVenta', 'pagos', 'envios'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);
            
        return response()->json($ventas);
    }

    public function show(Request $request, $id)
    {
        $venta = $request->user()->ventas()
            ->with(['detalles.productoVenta', 'pagos', 'envios', 'comprobantes'])
            ->findOrFail($id);

        return response()->json($venta);
    }
}
