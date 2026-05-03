<?php

namespace App\Services\Afip;

use App\Models\ComprobanteFacturacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AfipElectronicBillingService
{
    public function __construct(
        private readonly AfipInvoiceDataBuilder $builder,
        private readonly AfipSoapClient $client,
        private readonly AfipQrGenerator $qrGenerator,
        private readonly AfipFiscalConsultationService $fiscalConsultations,
    ) {}

    public function emitInvoice(Venta $venta, ?string $requestedType = null): ComprobanteFacturacion
    {
        $this->assertEnabled();

        $venta->loadMissing(['datosFacturacion', 'comprobantes']);

        $existing = $this->authorizedInvoice($venta);

        if ($existing) {
            return $existing;
        }

        $this->refreshFiscalDataForInvoice($venta);

        $draft = $this->builder->buildInvoiceDraft($venta, $requestedType);
        $comprobante = $this->createPendingVoucher($draft);

        return $this->authorizePendingVoucher($comprobante, $draft);
    }

    public function emitInvoiceForPaidVenta(Venta $venta): ?ComprobanteFacturacion
    {
        if (! config('afip.enabled') || ! config('afip.auto_emit_on_paid')) {
            return null;
        }

        $venta->loadMissing(['pagos', 'datosFacturacion', 'comprobantes']);

        if (! $this->isVentaPaid($venta)) {
            return null;
        }

        try {
            return $this->emitInvoice($venta);
        } catch (\Throwable $exception) {
            Log::error('No se pudo emitir automaticamente la factura electronica.', [
                'venta_id' => $venta->id,
                'numero_venta' => $venta->numero_venta,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function emitFullCreditNote(
        ComprobanteFacturacion $invoice,
        ?string $requestedType = null,
    ): ComprobanteFacturacion {
        $this->assertEnabled();

        $invoice->loadMissing(['venta.datosFacturacion', 'venta.comprobantes']);

        $existing = ComprobanteFacturacion::query()
            ->where('comprobante_asociado_id', $invoice->id)
            ->where('estado', 'autorizado')
            ->whereIn('tipo_comprobante', ['nota_credito_a', 'nota_credito_b'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $draft = $this->builder->buildCreditNoteDraft($invoice, $requestedType);
        $comprobante = $this->createPendingVoucher($draft);

        return $this->authorizePendingVoucher($comprobante, $draft);
    }

    private function createPendingVoucher(array $draft): ComprobanteFacturacion
    {
        return DB::transaction(function () use ($draft) {
            /** @var Venta $venta */
            $venta = Venta::query()
                ->whereKey($draft['venta']->id)
                ->lockForUpdate()
                ->firstOrFail();

            $associatedInvoice = $draft['associated_invoice'] ?? null;

            if (! $associatedInvoice) {
                $existing = $this->authorizedInvoice($venta);

                if ($existing) {
                    return $existing;
                }

                $this->guardAgainstRecentPendingVoucher(
                    ComprobanteFacturacion::query()
                        ->where('venta_id', $venta->id)
                        ->whereIn('tipo_comprobante', ['factura_a', 'factura_b'])
                );
            } else {
                $this->guardAgainstRecentPendingVoucher(
                    ComprobanteFacturacion::query()
                        ->where('comprobante_asociado_id', $associatedInvoice->id)
                        ->whereIn('tipo_comprobante', ['nota_credito_a', 'nota_credito_b'])
                );
            }

            return ComprobanteFacturacion::create([
                'venta_id' => $venta->id,
                'datos_facturacion_id' => $draft['dato_facturacion']->id,
                'comprobante_asociado_id' => $associatedInvoice?->id,
                'tipo_comprobante' => $draft['local_type'],
                'codigo_tipo_comprobante' => $draft['afip_type'],
                'punto_venta' => (string) $draft['point_of_sale'],
                'numero_comprobante' => null,
                'fecha_emision' => $this->dateFromYmd($draft['issue_date']),
                'estado' => 'solicitando',
                'ambiente' => (string) config('afip.environment', 'homologacion'),
                'razon_social' => $draft['dato_facturacion']->razon_social,
                'nombre_completo' => $draft['dato_facturacion']->nombre_completo,
                'tipo_documento' => $draft['receiver']['doc_type_label'],
                'codigo_tipo_documento' => $draft['receiver']['doc_type'],
                'numero_documento' => (string) $draft['receiver']['doc_number'],
                'cuit' => $draft['dato_facturacion']->cuit,
                'condicion_iva' => $draft['dato_facturacion']->condicion_iva,
                'condicion_iva_receptor_id' => $draft['receiver']['iva_condition_id'],
                'email_facturacion' => $draft['dato_facturacion']->email_facturacion,
                'domicilio_fiscal' => $draft['fiscal_address'],
                'subtotal' => $draft['amounts']['net'],
                'importe_iva' => $draft['amounts']['vat'],
                'total' => $draft['amounts']['total'],
                'moneda' => $draft['currency'],
                'moneda_cotizacion' => $draft['currency_rate'],
                'cae' => null,
                'cae_vencimiento' => null,
                'solicitud_externa' => $draft['request'],
                'respuesta_externa' => null,
                'observaciones_afip' => null,
            ]);
        });
    }

    private function refreshFiscalDataForInvoice(Venta $venta): void
    {
        if (! config('afip.refresh_fiscal_on_invoice', true)) {
            return;
        }

        $venta->loadMissing('datosFacturacion');

        if (! $venta->datosFacturacion) {
            return;
        }

        $this->fiscalConsultations->refreshDatoFacturacion($venta->datosFacturacion, false, true);
        $venta->setRelation('datosFacturacion', $venta->datosFacturacion->fresh());
    }

    private function authorizePendingVoucher(ComprobanteFacturacion $comprobante, array $draft): ComprobanteFacturacion
    {
        if ($comprobante->estado === 'autorizado') {
            return $comprobante;
        }

        try {
            $request = $this->numberedRequest($draft['request']);
            $comprobante->update([
                'numero_comprobante' => (string) data_get($request, 'FeDetReq.FECAEDetRequest.CbteDesde'),
                'solicitud_externa' => $request,
            ]);

            $authorization = $this->client->authorizePrepared($request);
        } catch (\Throwable $exception) {
            $comprobante->update([
                'estado' => 'error',
                'respuesta_externa' => [
                    'error' => $exception->getMessage(),
                ],
            ]);

            throw new AfipBillingException($exception->getMessage(), $comprobante->fresh(), previous: $exception);
        }

        if (($authorization['status'] ?? null) !== 'authorized') {
            $message = $this->authorizationMessage($authorization) ?: 'AFIP rechazo el comprobante.';

            $comprobante->update([
                'estado' => 'rechazado',
                'numero_comprobante' => (string) ($authorization['number'] ?? ''),
                'solicitud_externa' => $authorization['request'] ?? $draft['request'],
                'respuesta_externa' => $authorization['response'] ?? null,
                'observaciones_afip' => $authorization['observations'] ?? null,
            ]);

            throw new AfipBillingException($message, $comprobante->fresh());
        }

        $comprobante->update([
            'estado' => 'autorizado',
            'numero_comprobante' => (string) $authorization['number'],
            'cae' => (string) $authorization['cae'],
            'cae_vencimiento' => $this->dateFromYmd((string) $authorization['cae_due_date']),
            'solicitud_externa' => $authorization['request'] ?? $draft['request'],
            'respuesta_externa' => $authorization['response'] ?? null,
            'observaciones_afip' => $authorization['observations'] ?? null,
        ]);

        $comprobante = $comprobante->fresh();
        $qrPayload = $this->qrGenerator->buildPayload($comprobante);

        $comprobante->update([
            'qr_payload' => $qrPayload,
            'qr_url' => $this->qrGenerator->buildUrl($qrPayload),
        ]);

        return $comprobante->fresh(['venta', 'datosFacturacion', 'comprobanteAsociado']);
    }

    private function authorizedInvoice(Venta $venta): ?ComprobanteFacturacion
    {
        return ComprobanteFacturacion::query()
            ->where('venta_id', $venta->id)
            ->where('estado', 'autorizado')
            ->whereIn('tipo_comprobante', ['factura_a', 'factura_b'])
            ->first();
    }

    private function numberedRequest(array $request): array
    {
        $pointOfSale = (int) data_get($request, 'FeCabReq.PtoVta');
        $voucherType = (int) data_get($request, 'FeCabReq.CbteTipo');
        $number = $this->client->nextVoucherNumber($pointOfSale, $voucherType);

        data_set($request, 'FeDetReq.FECAEDetRequest.CbteDesde', $number);
        data_set($request, 'FeDetReq.FECAEDetRequest.CbteHasta', $number);

        return $request;
    }

    private function guardAgainstRecentPendingVoucher($query): void
    {
        $pending = $query
            ->where('estado', 'solicitando')
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->first();

        if ($pending) {
            throw new AfipBillingException(
                'Ya hay una solicitud de facturacion electronica en curso para esta operacion.',
                $pending,
            );
        }
    }

    private function isVentaPaid(Venta $venta): bool
    {
        $paidAmount = (float) $venta->pagos
            ->filter(fn ($pago) => in_array((string) $pago->estado, ['pagado', 'parcial'], true))
            ->sum(fn ($pago) => (float) $pago->monto);

        return $paidAmount >= (float) $venta->total && (float) $venta->total > 0;
    }

    private function assertEnabled(): void
    {
        if (! config('afip.enabled')) {
            throw ValidationException::withMessages([
                'afip' => 'La facturacion electronica AFIP no esta habilitada.',
            ]);
        }

        if (! config('afip.cuit')) {
            throw ValidationException::withMessages([
                'afip.cuit' => 'Falta configurar AFIP_CUIT.',
            ]);
        }
    }

    private function authorizationMessage(array $authorization): ?string
    {
        $observations = $authorization['observations'] ?? [];

        if ($observations === []) {
            return null;
        }

        return collect($observations)
            ->map(fn (array $item) => trim(sprintf('(%s) %s', $item['code'] ?? 'AFIP', $item['message'] ?? '')))
            ->filter()
            ->implode(' - ');
    }

    private function dateFromYmd(?string $value): ?CarbonImmutable
    {
        if (! $value || strlen($value) !== 8) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd', $value, (string) config('afip.timezone'));
    }
}
