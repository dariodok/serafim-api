<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatoFacturacionRequest;
use App\Http\Requests\UpdateDatoFacturacionRequest;
use App\Services\Afip\AfipFiscalConsultationService;
use Illuminate\Http\Request;

class ClienteDatoFacturacionController extends Controller
{
    public function __construct(private readonly AfipFiscalConsultationService $afipConsultations) {}

    public function index(Request $request)
    {
        return response()->json($request->user()->datosFacturacion()->where('activo', true)->get());
    }

    public function store(StoreDatoFacturacionRequest $request)
    {
        $dato = $request->user()->datosFacturacion()->create($request->validated());
        $this->afipConsultations->tryRefreshDatoFacturacion($dato->fresh());

        return response()->json($dato->fresh(), 201);
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
        $this->afipConsultations->tryRefreshDatoFacturacion($dato->fresh());

        return response()->json($dato->fresh());
    }

    public function destroy(Request $request, $id)
    {
        $dato = $request->user()->datosFacturacion()->findOrFail($id);
        $dato->delete();
        return response()->json(['mensaje' => 'Eliminado exitosamente']);
    }
}
