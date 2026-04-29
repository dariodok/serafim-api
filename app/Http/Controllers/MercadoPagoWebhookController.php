<?php

namespace App\Http\Controllers;

use App\Services\MercadoPagoCheckoutProService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(private readonly MercadoPagoCheckoutProService $mercadoPago)
    {
    }

    public function handle(Request $request)
    {
        $requestId = (string) $request->header('x-request-id', '');
        $signature = (string) $request->header('x-signature', '');
        $dataId = (string) ($request->input('data.id') ?? $request->query('data.id', ''));

        if (!$this->mercadoPago->validateWebhookSignature($signature, $requestId, $dataId)) {
            Log::warning('Webhook Mercado Pago con firma invalida.', [
                'request_id' => $requestId,
                'data_id' => $dataId,
            ]);

            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $type = (string) ($request->input('type') ?? $request->query('type', ''));
        $action = (string) ($request->input('action') ?? '');

        if (!in_array($type, ['payment'], true) && !str_contains($action, 'payment')) {
            return response()->json(['message' => 'Notification ignored.'], Response::HTTP_OK);
        }

        if ($dataId === '') {
            return response()->json(['message' => 'Missing payment id.'], Response::HTTP_OK);
        }

        $venta = $this->mercadoPago->handlePaymentWebhook($dataId);

        Log::info('Webhook Mercado Pago procesado.', [
            'payment_id' => $dataId,
            'venta_id' => $venta?->id,
        ]);

        return response()->json(['message' => 'Notification processed.'], Response::HTTP_OK);
    }
}
