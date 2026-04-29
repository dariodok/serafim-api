<?php

namespace App\Http\Controllers;

use App\Models\ProductoVenta;

class ProductoVentaPublicController extends Controller
{
    public function index()
    {
        $productos = ProductoVenta::with(['imagenes' => function($q) {
            $q->where('activo', true)->orderBy('orden');
        }])
        ->where('activo', true)
        ->where('visible', true)
        ->paginate(15);

        return response()->json($productos);
    }

    public function show($id)
    {
        $producto = ProductoVenta::with(['imagenes' => function($q) {
            $q->where('activo', true)->orderBy('orden');
        }])
        ->where('activo', true)
        ->where('visible', true)
        ->findOrFail($id);

        return response()->json($producto);
    }
}
