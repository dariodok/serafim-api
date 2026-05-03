<?php

namespace App\Services\Afip;

use App\Models\ComprobanteFacturacion;

class AfipQrGenerator
{
    public function buildPayload(ComprobanteFacturacion $comprobante): array
    {
        return [
            'ver' => 1,
            'fecha' => $comprobante->fecha_emision?->format('Y-m-d'),
            'cuit' => (int) config('afip.cuit'),
            'ptoVta' => (int) $comprobante->punto_venta,
            'tipoCmp' => (int) $comprobante->codigo_tipo_comprobante,
            'nroCmp' => (int) $comprobante->numero_comprobante,
            'importe' => round((float) $comprobante->total, 2),
            'moneda' => (string) $comprobante->moneda,
            'ctz' => round((float) $comprobante->moneda_cotizacion, 6),
            'tipoDocRec' => (int) $comprobante->codigo_tipo_documento,
            'nroDocRec' => (int) ($comprobante->numero_documento ?: $comprobante->cuit ?: 0),
            'tipoCodAut' => 'E',
            'codAut' => (int) $comprobante->cae,
        ];
    }

    public function buildUrl(array $payload): string
    {
        $baseUrl = rtrim((string) config('afip.qr_url'), '/');
        $encodedPayload = rawurlencode(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)));

        return "{$baseUrl}/?p={$encodedPayload}";
    }
}
