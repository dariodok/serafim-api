<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Envio;
use App\Services\AndreaniService;
use Illuminate\Http\Request;

class AdminAndreaniController extends Controller
{
    public function __construct(
        private readonly AndreaniService $andreani,
    ) {
    }

    public function config()
    {
        return response()->json($this->andreani->configurationSummary());
    }

    public function suggestedPayload(Envio $envio)
    {
        return response()->json(
            $this->andreani->buildSuggestedPayloadsForEnvio($envio)
        );
    }

    public function rates(Request $request)
    {
        $data = $request->validate([
            'payload' => 'required|array',
        ]);

        return response()->json(
            $this->andreani->quote($data['payload'])
        );
    }

    public function shippingImport(Request $request)
    {
        $data = $request->validate([
            'payload' => 'required|array',
        ]);

        return response()->json(
            $this->andreani->importShipping($data['payload'])
        );
    }

    public function tracking(Request $request)
    {
        $data = $request->validate([
            'params' => 'required|array',
        ]);

        return response()->json(
            $this->andreani->track($data['params'])
        );
    }

    public function quoteEnvio(Envio $envio)
    {
        $payloads = $this->andreani->buildSuggestedPayloadsForEnvio($envio);

        return response()->json([
            'payload' => $payloads['rates'],
            'response' => $this->andreani->quote($payloads['rates']),
            'envio' => $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']),
        ]);
    }

    public function registerEnvio(Envio $envio)
    {
        $payloads = $this->andreani->buildSuggestedPayloadsForEnvio($envio);

        return response()->json([
            'payload' => $payloads['shipping_import'],
            'response' => $this->andreani->importShipping($payloads['shipping_import']),
            'envio' => $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']),
        ]);
    }

    public function trackEnvio(Envio $envio)
    {
        $payloads = $this->andreani->buildSuggestedPayloadsForEnvio($envio);

        return response()->json([
            'params' => $payloads['tracking'],
            'response' => $this->andreani->track($payloads['tracking']),
            'envio' => $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']),
        ]);
    }
}
