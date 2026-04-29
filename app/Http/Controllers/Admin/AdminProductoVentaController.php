<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductoVenta;
use App\Models\ProductoVentaComponente;
use App\Http\Requests\StoreProductoVentaRequest;
use App\Http\Requests\UpdateProductoVentaRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductoVentaController extends Controller
{
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = ProductoVenta::query()
            ->with(['imagenes', 'componentes.productoBase.imagenes'])
            ->withCount(['imagenes', 'componentes'])
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('nombre', 'like', "%{$search}%")
                    ->orWhere('descripcion', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(StoreProductoVentaRequest $request)
    {
        $producto = ProductoVenta::create($request->validated());
        return response()->json($producto, 201);
    }

    public function show(string $id)
    {
        return response()->json(
            ProductoVenta::with(['imagenes', 'componentes.productoBase.imagenes'])
                ->withCount(['imagenes', 'componentes'])
                ->findOrFail($id)
        );
    }

    public function update(UpdateProductoVentaRequest $request, string $id)
    {
        $producto = ProductoVenta::findOrFail($id);
        $producto->update($request->validated());
        return response()->json($producto);
    }

    public function destroy(string $id)
    {
        // Regla: desactivar antes que borrar
        $producto = ProductoVenta::findOrFail($id);
        $producto->update(['activo' => false, 'visible' => false]);
        return response()->json(['mensaje' => 'Producto de venta desactivado exitosamente']);
    }

    public function syncComponentes(Request $request, string $id)
    {
        $producto = ProductoVenta::findOrFail($id);
        
        $request->validate([
            'componentes' => 'present|array',
            'componentes.*.producto_base_id' => 'required|exists:productos_base,id',
            'componentes.*.cantidad_requerida' => 'required|integer|min:1'
        ]);

        DB::transaction(function () use ($producto, $request) {
            $producto->componentes()->delete();

            foreach ($request->componentes as $comp) {
                ProductoVentaComponente::create([
                    'producto_venta_id' => $producto->id,
                    'producto_base_id' => $comp['producto_base_id'],
                    'cantidad_requerida' => $comp['cantidad_requerida'],
                ]);
            }
        });

        return response()->json(['mensaje' => 'Componentes sincronizados']);
    }
}
