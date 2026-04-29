<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImagenProductoBase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminImagenesProductoBaseController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'producto_base_id' => 'required|exists:productos_base,id',
            'imagen'           => 'required|image|max:5120', // Up to 5MB
            'orden'            => 'nullable|integer',
            'principal'        => 'boolean'
        ]);

        $file = $request->file('imagen');
        // Usamos el disco 'public'
        $path = $file->store('productos-base', 'public');

        $imagen = ImagenProductoBase::create([
            'producto_base_id' => $request->producto_base_id,
            'disco'            => 'public',
            'ruta'             => $path,
            'texto_alternativo'=> $file->getClientOriginalName(),
            'orden'            => $request->orden ?? 0,
            'principal'        => $request->boolean('principal'),
            'activo'           => true,
        ]);

        // Retornar la URL pública completa en vez de solo el path
        $imagen->url = asset('storage/' . $path);

        return response()->json($imagen, 201);
    }

    public function update(Request $request, string $id)
    {
        $imagen = ImagenProductoBase::findOrFail($id);
        
        $request->validate([
            'orden' => 'nullable|integer',
            'principal' => 'boolean',
            'activo' => 'boolean',
        ]);

        if ($request->boolean('principal')) {
            ImagenProductoBase::where('producto_base_id', $imagen->producto_base_id)
                ->where('id', '!=', $id)
                ->update(['principal' => false]);
        }

        $imagen->update($request->only(['orden', 'principal', 'activo']));
        $imagen->url = asset('storage/' . $imagen->ruta);
        return response()->json($imagen);
    }

    public function destroy(string $id)
    {
        $imagen = ImagenProductoBase::findOrFail($id);
        
        if (Storage::disk($imagen->disco)->exists($imagen->ruta)) {
            Storage::disk($imagen->disco)->delete($imagen->ruta);
        }
        
        $imagen->delete();

        return response()->json(['mensaje' => 'Imagen eliminada']);
    }
}
