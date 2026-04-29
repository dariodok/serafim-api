<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaywayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Webhook Payway recibido.', [
            'event' => $request->input('event'),
            'operation_id' => $request->input('operation_id'),
            'reference' => $request->input('site_transaction_id'),
            'payload_keys' => array_keys((array) $request->all()),
        ]);

        return response()->json(['message' => 'Notification processed.'], Response::HTTP_OK);
    }
}
