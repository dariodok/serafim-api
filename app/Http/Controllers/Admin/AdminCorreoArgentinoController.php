<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use App\Services\CorreoArgentinoService;
use Illuminate\Http\Request;

class AdminCorreoArgentinoController extends Controller
{
    public function __construct(
        private readonly CorreoArgentinoService $correoArgentino,
    ) {
    }

    public function config()
    {
        return response()->json($this->correoArgentino->configurationSummary());
    }

    public function validateUser()
    {
        return response()->json(
            $this->correoArgentino->validateUser()
        );
    }

    public function agencies(Request $request)
    {
        $data = $request->validate([
            'provinceCode' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'services' => 'nullable',
        ]);

        return response()->json(
            $this->correoArgentino->agencies($data)
        );
    }

    public function suggestedPayload(Envio $envio)
    {
        return response()->json(
            $this->correoArgentino->buildSuggestedPayloadsForEnvio($envio)
        );
    }

    public function rates(Request $request)
    {
        $data = $request->validate([
            'payload' => 'required|array',
        ]);

        return response()->json(
            $this->correoArgentino->quote($data['payload'])
        );
    }

    public function shippingImport(Request $request)
    {
        $data = $request->validate([
            'payload' => 'required|array',
        ]);

        return response()->json(
            $this->correoArgentino->importShipping($data['payload'])
        );
    }

    public function tracking(Request $request)
    {
        $data = $request->validate([
            'params' => 'required|array',
        ]);

        return response()->json(
            $this->correoArgentino->track($data['params'])
        );
    }

    public function quoteEnvio(Envio $envio)
    {
        $payloads = $this->correoArgentino->buildSuggestedPayloadsForEnvio($envio);
        $response = $this->correoArgentino->quote($payloads['rates']);

        $envio->update([
            'respuesta_ultima_api' => $response,
        ]);

        return response()->json([
            'payload' => $payloads['rates'],
            'response' => $response,
            'envio' => $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']),
        ]);
    }

    public function registerEnvio(Envio $envio)
    {
        $payloads = $this->correoArgentino->buildSuggestedPayloadsForEnvio($envio);
        $response = $this->correoArgentino->importShipping($payloads['shipping_import']);
        $updatedEnvio = $this->correoArgentino->syncEnvioWithImportResponse($envio, $response, $payloads['shipping_import']);

        return response()->json([
            'payload' => $payloads['shipping_import'],
            'response' => $response,
            'envio' => $updatedEnvio,
        ]);
    }

    public function trackEnvio(Envio $envio)
    {
        $payloads = $this->correoArgentino->buildSuggestedPayloadsForEnvio($envio);
        $response = $this->correoArgentino->track($payloads['tracking']);

        $envio->update([
            'respuesta_ultima_api' => $response,
        ]);

        return response()->json([
            'params' => $payloads['tracking'],
            'response' => $response,
            'envio' => $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']),
        ]);
    }
}
