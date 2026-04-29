<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImagenProductoVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminImagenesProductoVentaController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'producto_venta_id' => 'required|exists:productos_venta,id',
            'imagen'            => 'required|image|max:5120',
            'orden'             => 'nullable|integer',
            'principal'         => 'boolean'
        ]);

        $file = $request->file('imagen');
        $path = $file->store('productos-venta', 'public');

        $imagen = ImagenProductoVenta::create([
            'producto_venta_id' => $request->producto_venta_id,
            'disco'             => 'public',
            'ruta'              => $path,
            'texto_alternativo' => $file->getClientOriginalName(),
            'orden'             => $request->orden ?? 0,
            'principal'         => $request->boolean('principal'),
            'activo'            => true,
        ]);

        $imagen->url = asset('storage/' . $path);

        return response()->json($imagen, 201);
    }

    public function update(Request $request, string $id)
    {
        $imagen = ImagenProductoVenta::findOrFail($id);
        
        $request->validate([
            'orden' => 'nullable|integer',
            'principal' => 'boolean',
            'activo' => 'boolean',
        ]);

        if ($request->boolean('principal')) {
            ImagenProductoVenta::where('producto_venta_id', $imagen->producto_venta_id)
                ->where('id', '!=', $id)
                ->update(['principal' => false]);
        }

        $imagen->update($request->only(['orden', 'principal', 'activo']));
        $imagen->url = asset('storage/' . $imagen->ruta);
        return response()->json($imagen);
    }

    public function destroy(string $id)
    {
        $imagen = ImagenProductoVenta::findOrFail($id);
        
        if (Storage::disk($imagen->disco)->exists($imagen->ruta)) {
            Storage::disk($imagen->disco)->delete($imagen->ruta);
        }
        
        $imagen->delete();

        return response()->json(['mensaje' => 'Imagen eliminada']);
    }
}
