<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatoFacturacionRequest;
use App\Http\Requests\UpdateDatoFacturacionRequest;
use Illuminate\Http\Request;

class ClienteDatoFacturacionController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->datosFacturacion()->where('activo', true)->get());
    }

    public function store(StoreDatoFacturacionRequest $request)
    {
        $dato = $request->user()->datosFacturacion()->create($request->validated());
        return response()->json($dato, 201);
    }

    public function show(Request $request, $id)
    {
        $dato = $request->user()->datosFacturacion()->where('activo', true)->findOrFail($id);
        return response()->json($dato);
    }

    public function update(UpdateDatoFacturacionRequest $request, $id)
    {
        $dato = $request->user()->datosFacturacion()->where('activo', true)->findOrFail($id);
        $dato->update($request->validated());
        return response()->json($dato);
    }

    public function destroy(Request $request, $id)
    {
        $dato = $request->user()->datosFacturacion()->findOrFail($id);
        $dato->delete();
        return response()->json(['mensaje' => 'Eliminado exitosamente']);
    }
}
