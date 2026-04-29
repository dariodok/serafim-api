<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDomicilioRequest;
use App\Http\Requests\UpdateDomicilioRequest;
use Illuminate\Http\Request;

class ClienteDomicilioController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->domicilios()->where('activo', true)->get());
    }

    public function store(StoreDomicilioRequest $request)
    {
        $domicilio = $request->user()->domicilios()->create($request->validated());
        return response()->json($domicilio, 201);
    }

    public function show(Request $request, $id)
    {
        $domicilio = $request->user()->domicilios()->where('activo', true)->findOrFail($id);
        return response()->json($domicilio);
    }

    public function update(UpdateDomicilioRequest $request, $id)
    {
        $domicilio = $request->user()->domicilios()->where('activo', true)->findOrFail($id);
        $domicilio->update($request->validated());
        return response()->json($domicilio);
    }

    public function destroy(Request $request, $id)
    {
        $domicilio = $request->user()->domicilios()->findOrFail($id);
        // Borrado físico permitido para domicilios según las reglas
        $domicilio->delete();
        return response()->json(['mensaje' => 'Eliminado exitosamente']);
    }
}
