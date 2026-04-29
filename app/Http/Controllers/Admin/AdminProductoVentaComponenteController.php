<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductoVentaComponente;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminProductoVentaComponenteController extends Controller
{
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));

        $query = ProductoVentaComponente::with(['productoVenta', 'productoBase'])
            ->orderByDesc('updated_at');

        if (request()->filled('producto_venta_id')) {
            $query->where('producto_venta_id', request('producto_venta_id'));
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'producto_venta_id' => 'required|exists:productos_venta,id',
            'producto_base_id' => 'required|exists:productos_base,id',
            'cantidad_requerida' => 'required|integer|min:1',
        ]);

        $this->ensureUniquePair($data['producto_venta_id'], $data['producto_base_id']);

        $componente = ProductoVentaComponente::create($data);

        return response()->json($componente->load(['productoVenta', 'productoBase']), 201);
    }

    public function show(string $id)
    {
        return response()->json(
            ProductoVentaComponente::with(['productoVenta', 'productoBase'])
                ->findOrFail($id)
        );
    }

    public function update(Request $request, string $id)
    {
        $componente = ProductoVentaComponente::findOrFail($id);

        $data = $request->validate([
            'producto_venta_id' => 'sometimes|exists:productos_venta,id',
            'producto_base_id' => 'sometimes|exists:productos_base,id',
            'cantidad_requerida' => 'sometimes|integer|min:1',
        ]);

        $productoVentaId = $data['producto_venta_id'] ?? $componente->producto_venta_id;
        $productoBaseId = $data['producto_base_id'] ?? $componente->producto_base_id;

        $this->ensureUniquePair($productoVentaId, $productoBaseId, $componente->id);

        $componente->update($data);

        return response()->json($componente->fresh(['productoVenta', 'productoBase']));
    }

    public function destroy(string $id)
    {
        $componente = ProductoVentaComponente::findOrFail($id);
        $componente->delete();

        return response()->json(['mensaje' => 'Componente eliminado exitosamente']);
    }

    private function ensureUniquePair(int $productoVentaId, int $productoBaseId, ?int $ignoreId = null): void
    {
        $exists = ProductoVentaComponente::query()
            ->where('producto_venta_id', $productoVentaId)
            ->where('producto_base_id', $productoBaseId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'producto_base_id' => ['Ese producto base ya está asociado al producto de venta.'],
            ]);
        }
    }
}
