<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTelefonoRequest;
use App\Http\Requests\UpdateTelefonoRequest;
use Illuminate\Http\Request;

class ClienteTelefonoController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->telefonos()->where('activo', true)->get());
    }

    public function store(StoreTelefonoRequest $request)
    {
        $telefono = $request->user()->telefonos()->create($request->validated());
        return response()->json($telefono, 201);
    }

    public function show(Request $request, $id)
    {
        $telefono = $request->user()->telefonos()->where('activo', true)->findOrFail($id);
        return response()->json($telefono);
    }

    public function update(UpdateTelefonoRequest $request, $id)
    {
        $telefono = $request->user()->telefonos()->where('activo', true)->findOrFail($id);
        $telefono->update($request->validated());
        return response()->json($telefono);
    }

    public function destroy(Request $request, $id)
    {
        $telefono = $request->user()->telefonos()->findOrFail($id);
        $telefono->delete();
        return response()->json(['mensaje' => 'Eliminado exitosamente']);
    }
}
