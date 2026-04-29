<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductoBase;
use App\Http\Requests\StoreProductoBaseRequest;
use App\Http\Requests\UpdateProductoBaseRequest;
use Illuminate\Http\Request;

class AdminProductoBaseController extends Controller
{
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = ProductoBase::query()
            ->with('imagenes')
            ->withCount('imagenes')
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

    public function store(StoreProductoBaseRequest $request)
    {
        $producto = ProductoBase::create($request->validated());
        return response()->json($producto, 201);
    }

    public function show(string $id)
    {
        return response()->json(
            ProductoBase::with('imagenes')
                ->withCount('imagenes')
                ->findOrFail($id)
        );
    }

    public function update(UpdateProductoBaseRequest $request, string $id)
    {
        $producto = ProductoBase::findOrFail($id);
        $producto->update($request->validated());
        return response()->json($producto);
    }

    public function destroy(string $id)
    {
        // En productos preferimos desactivar antes que borrar si está siendo usado (regla dada)
        $producto = ProductoBase::findOrFail($id);
        $producto->update(['activo' => false]);
        return response()->json(['mensaje' => 'Producto base desactivado exitosamente']);
    }
}
