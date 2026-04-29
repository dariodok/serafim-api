<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\Venta;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class MercadoPagoCheckoutProService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CustomerNotificationService $notifications,
    )
    {
    }

    public function createPreferenceForVenta(Venta $venta): array
    {
        $venta->loadMissing(['usuario', 'detalles', 'pagos']);

        $remainingAmount = $this->resolvePendingAmount($venta);
        $hasEffectivePayments = $this->effectivePaidAmount($venta->pagos) > 0;

        if ($remainingAmount <= 0) {
            throw ValidationException::withMessages([
                'venta' => 'La venta ya no tiene saldo pendiente para generar un checkout.',
            ]);
        }

        $externalReference = $venta->numero_venta;
        $items = $this->buildPreferenceItems($venta, $remainingAmount);

        $payload = [
            'items' => $items,
            'payer' => [
                'name' => $venta->usuario?->nombre,
                'surname' => $venta->usuario?->apellido,
                'email' => $venta->usuario?->email,
            ],
            'external_reference' => $externalReference,
            'notification_url' => route('mercadopago.webhook'),
            'back_urls' => config('services.mercadopago.back_urls'),
            'auto_return' => 'approved',
            'statement_descriptor' => 'SERAFIM',
            'metadata' => [
                'venta_id' => $venta->id,
                'numero_venta' => $venta->numero_venta,
            ],
        ];

        if (!$hasEffectivePayments && (float) $venta->costo_envio > 0) {
            $payload['shipments'] = [
                'cost' => (float) $venta->costo_envio,
                'mode' => 'not_specified',
            ];
        }

        $response = $this->request()
            ->post('/checkout/preferences', $payload)
            ->throw();

        $data = $response->json();

        $venta->update([
            'mercado_pago_preference_id' => $data['id'] ?? null,
            'mercado_pago_external_reference' => $externalReference,
            'mercado_pago_init_point' => $data['init_point'] ?? null,
            'mercado_pago_sandbox_init_point' => $data['sandbox_init_point'] ?? null,
            'mercado_pago_checkout_status' => 'generated',
            'mercado_pago_last_sync_at' => now(),
            'medio_pago' => 'Mercado Pago',
        ]);

        return $data;
    }

    public function createPreferenceForPago(Pago $pago): array
    {
        $pago->loadMissing(['venta.usuario', 'venta.detalles', 'venta.pagos']);

        if ($pago->medio_pago !== 'Mercado Pago') {
            throw ValidationException::withMessages([
                'pago' => 'Solo se puede generar Checkout Pro para pagos de Mercado Pago.',
            ]);
        }

        if ((float) $pago->monto <= 0) {
            throw ValidationException::withMessages([
                'pago' => 'El pago debe tener un monto mayor a cero para generar Checkout Pro.',
            ]);
        }

        if (in_array($pago->estado, ['pagado', 'reembolsado'], true)) {
            throw ValidationException::withMessages([
                'pago' => 'El pago ya no admite generar un nuevo checkout.',
            ]);
        }

        $venta = $pago->venta;
        $externalReference = sprintf('pago-%s', $pago->id);
        $payload = [
            'items' => $this->buildPreferenceItemsForPago($pago),
            'payer' => [
                'name' => $venta->usuario?->nombre,
                'surname' => $venta->usuario?->apellido,
                'email' => $venta->usuario?->email,
            ],
            'external_reference' => $externalReference,
            'notification_url' => route('mercadopago.webhook'),
            'back_urls' => config('services.mercadopago.back_urls'),
            'auto_return' => 'approved',
            'statement_descriptor' => 'SERAFIM',
            'metadata' => [
                'venta_id' => $venta->id,
                'numero_venta' => $venta->numero_venta,
                'pago_id' => $pago->id,
            ],
        ];

        $response = $this->request()
            ->post('/checkout/preferences', $payload)
            ->throw();

        $data = $response->json();
        $this->updatePagoMercadoPagoMetadata($pago, [
            'preference_id' => $data['id'] ?? null,
            'external_reference' => $externalReference,
            'init_point' => $data['init_point'] ?? null,
            'sandbox_init_point' => $data['sandbox_init_point'] ?? null,
            'checkout_status' => 'generated',
            'last_sync_at' => now()?->toIso8601String(),
        ]);

        $pago->update([
            'estado' => 'pendiente',
            'fecha_pago' => null,
        ]);

        return $data;
    }

    public function syncVentaPayments(Venta $venta): Venta
    {
        $venta->loadMissing('pagos');

        $externalReference = $venta->mercado_pago_external_reference ?: sprintf('venta-%s', $venta->id);

        $response = $this->request()
            ->get('/v1/payments/search', [
                'external_reference' => $externalReference,
                'sort' => 'date_created',
                'criteria' => 'desc',
                'limit' => 50,
            ])
            ->throw();

        $results = $response->json('results', []);

        foreach ($results as $paymentData) {
            $this->syncPaymentData($venta, $paymentData);
        }

        $venta->refresh();
        $venta->update([
            'mercado_pago_last_sync_at' => now(),
            'mercado_pago_checkout_status' => $this->resolveCheckoutStatus($venta),
            'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
            'estado_venta' => $this->resolveSaleStatusAfterPayment($venta->fresh('pagos')),
        ]);

        return $venta->fresh(['usuario', 'datosFacturacion', 'detalles.productoVenta.imagenes', 'pagos', 'envios', 'comprobantes']);
    }

    public function syncPago(Pago $pago): Pago
    {
        $pago->loadMissing('venta.pagos');

        $externalReference = (string) data_get($pago->datos_externos, 'mercado_pago.external_reference', '');

        if ($externalReference === '') {
            throw ValidationException::withMessages([
                'pago' => 'El pago todavia no tiene un checkout generado para sincronizar.',
            ]);
        }

        $response = $this->request()
            ->get('/v1/payments/search', [
                'external_reference' => $externalReference,
                'sort' => 'date_created',
                'criteria' => 'desc',
                'limit' => 50,
            ])
            ->throw();

        $results = $response->json('results', []);

        foreach ($results as $paymentData) {
            $this->syncPaymentData($pago->venta, $paymentData, $pago);
        }

        $pago->refresh();
        $this->updatePagoMercadoPagoMetadata($pago, [
            'checkout_status' => $this->resolvePagoCheckoutStatus($pago),
            'last_sync_at' => now()?->toIso8601String(),
        ]);

        $this->refreshVentaPaymentState($pago->venta->fresh('pagos'));

        return $pago->fresh(['venta.usuario', 'venta.pagos']);
    }

    public function handlePaymentWebhook(string|int $paymentId): ?Venta
    {
        $response = $this->request()->get(sprintf('/v1/payments/%s', $paymentId))->throw();
        $paymentData = $response->json();
        $externalReference = $paymentData['external_reference'] ?? null;

        if (!$externalReference) {
            return null;
        }

        $pago = Pago::query()
            ->where('datos_externos->mercado_pago->external_reference', $externalReference)
            ->first();

        if ($pago) {
            $this->syncPaymentData($pago->venta, $paymentData, $pago);
            $pago->refresh();
            $this->updatePagoMercadoPagoMetadata($pago, [
                'checkout_status' => $this->mapMercadoPagoStatusToCheckoutStatus($paymentData['status'] ?? null),
                'last_sync_at' => now()?->toIso8601String(),
            ]);
            $this->refreshVentaPaymentState($pago->venta->fresh('pagos'));

            return $pago->venta->fresh(['pagos']);
        }

        $venta = Venta::query()
            ->where('mercado_pago_external_reference', $externalReference)
            ->first();

        if (!$venta) {
            return null;
        }

        $this->syncPaymentData($venta, $paymentData);

        $venta->refresh();
        $venta->update([
            'mercado_pago_last_sync_at' => now(),
            'mercado_pago_checkout_status' => $this->mapMercadoPagoStatusToCheckoutStatus($paymentData['status'] ?? null),
            'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
            'estado_venta' => $this->resolveSaleStatusAfterPayment($venta->fresh('pagos')),
        ]);

        return $venta->fresh(['pagos']);
    }

    public function validateWebhookSignature(string $signatureHeader, string $requestId, string $dataId): bool
    {
        $secret = (string) config('services.mercadopago.webhook_secret');

        if ($secret === '' || $signatureHeader === '' || $requestId === '' || $dataId === '') {
            return true;
        }

        $signatureParts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                return [$key => $value];
            });

        $ts = $signatureParts->get('ts');
        $v1 = $signatureParts->get('v1');

        if (!$ts || !$v1) {
            return false;
        }

        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $requestId, $ts);
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    private function syncPaymentData(Venta $venta, array $paymentData, ?Pago $targetPago = null): void
    {
        $payment = $targetPago ?: Pago::query()->firstOrNew([
            'venta_id' => $venta->id,
            'referencia_externa' => (string) ($paymentData['id'] ?? ''),
        ]);
        $wasExisting = $payment->exists;
        $originalStatus = (string) $payment->estado;
        $originalAmount = (float) $payment->monto;
        $existingMercadoPagoMetadata = (array) data_get($payment->datos_externos, 'mercado_pago', []);

        $payment->fill([
            'medio_pago' => 'Mercado Pago',
            'estado' => $this->mapMercadoPagoStatus($paymentData['status'] ?? null),
            'monto' => (float) ($paymentData['transaction_amount'] ?? 0),
            'moneda' => $paymentData['currency_id'] ?? $venta->moneda,
            'es_manual' => false,
            'fecha_pago' => $paymentData['date_approved'] ?? $paymentData['date_created'] ?? null,
            'referencia_secundaria' => (string) Arr::get($paymentData, 'order.id', Arr::get($paymentData, 'order_id')),
            'datos_externos' => [
                'mercado_pago' => array_filter(array_merge($existingMercadoPagoMetadata, [
                    'external_reference' => $paymentData['external_reference'] ?? $existingMercadoPagoMetadata['external_reference'] ?? null,
                    'checkout_status' => $this->mapMercadoPagoStatusToCheckoutStatus($paymentData['status'] ?? null),
                    'last_sync_at' => now()?->toIso8601String(),
                ]), fn ($value) => $value !== null && $value !== ''),
                'mercado_pago_id' => $paymentData['id'] ?? null,
                'status' => $paymentData['status'] ?? null,
                'status_detail' => $paymentData['status_detail'] ?? null,
                'payment_method_id' => $paymentData['payment_method_id'] ?? null,
                'payment_type_id' => $paymentData['payment_type_id'] ?? null,
                'preference_id' => $paymentData['preference_id'] ?? null,
                'order' => $paymentData['order'] ?? null,
                'raw' => $paymentData,
            ],
            'observaciones' => $paymentData['status_detail'] ?? null,
        ]);

        $payment->save();

        if (
            !$wasExisting
            || $originalStatus !== (string) $payment->estado
            || abs($originalAmount - (float) $payment->monto) > 0.009
        ) {
            $this->notifications->sendPaymentRecorded($payment->fresh('venta.usuario'), 'mercado pago');
        }
    }

    private function request()
    {
        $token = (string) config('services.mercadopago.access_token');

        if ($token === '') {
            throw ValidationException::withMessages([
                'mercado_pago' => 'Falta configurar MERCADO_PAGO_ACCESS_TOKEN.',
            ]);
        }

        return $this->http
            ->baseUrl(rtrim((string) config('services.mercadopago.base_url'), '/'))
            ->acceptJson()
            ->withToken($token)
            ->timeout(20);
    }

    private function resolvePendingAmount(Venta $venta): float
    {
        $paidAmount = $this->effectivePaidAmount($venta->pagos);
        return round(max((float) $venta->total - $paidAmount, 0), 2);
    }

    private function buildPreferenceItemsForPago(Pago $pago): array
    {
        $venta = $pago->venta;
        $amount = round((float) $pago->monto, 2);
        $currency = $pago->moneda ?: $venta->moneda;

        $canUseDetailedItems = abs($amount - (float) $venta->total) <= 0.009
            && $this->effectivePaidAmount($venta->pagos->reject(fn (Pago $item) => $item->id === $pago->id)) <= 0;

        if ($canUseDetailedItems) {
            $detailedItems = $this->buildDetailedPreferenceItems($venta);

            if (!empty($detailedItems)) {
                return $detailedItems;
            }
        }

        return [[
            'id' => (string) $pago->id,
            'title' => $this->buildCheckoutTitle($venta, $amount < (float) $venta->total),
            'description' => $this->buildCheckoutDescriptionForPago($venta, $pago),
            'quantity' => 1,
            'currency_id' => $currency,
            'unit_price' => $amount,
        ]];
    }

    private function buildPreferenceItems(Venta $venta, float $remainingAmount): array
    {
        $hasPartialPayments = $this->effectivePaidAmount($venta->pagos) > 0;

        if (!$hasPartialPayments) {
            $detailedItems = $this->buildDetailedPreferenceItems($venta);

            if (!empty($detailedItems)) {
                return $detailedItems;
            }
        }

        return [[
            'id' => (string) $venta->id,
            'title' => $this->buildCheckoutTitle($venta, $hasPartialPayments),
            'description' => $this->buildCheckoutDescription($venta, $hasPartialPayments),
            'quantity' => 1,
            'currency_id' => $venta->moneda,
            'unit_price' => $remainingAmount,
        ]];
    }

    private function buildDetailedPreferenceItems(Venta $venta): array
    {
        $currency = $venta->moneda ?: 'ARS';
        $details = $venta->detalles->values();
        $baseSubtotal = (float) $details->sum(fn ($detalle) => (float) $detalle->subtotal);
        $remainingDiscount = round((float) $venta->descuento, 2);
        $lastIndex = $details->count() - 1;

        if ($details->isEmpty() || $baseSubtotal <= 0) {
            return [];
        }

        $items = [];

        foreach ($details as $index => $detalle) {
            $lineSubtotal = round((float) $detalle->subtotal, 2);

            if ($lineSubtotal <= 0) {
                continue;
            }

            $discountShare = 0.0;
            if ($remainingDiscount > 0) {
                if ($index === $lastIndex) {
                    $discountShare = min($remainingDiscount, $lineSubtotal);
                } else {
                    $discountShare = min(
                        round(((float) $venta->descuento * $lineSubtotal) / $baseSubtotal, 2),
                        $lineSubtotal
                    );
                }
            }

            $remainingDiscount = round(max($remainingDiscount - $discountShare, 0), 2);
            $lineNet = round(max($lineSubtotal - $discountShare, 0), 2);

            if ($lineNet <= 0) {
                continue;
            }

            $title = trim((string) $detalle->nombre_producto);
            $quantity = max(1, (int) $detalle->cantidad);

            $items[] = [
                'id' => sprintf('%s-%s', $venta->id, $detalle->id),
                'title' => $quantity > 1 ? sprintf('%s x%d', $title, $quantity) : $title,
                'description' => $this->buildDetailedLineDescription($detalle, $currency, $discountShare),
                'quantity' => 1,
                'currency_id' => $currency,
                'unit_price' => $lineNet,
            ];
        }

        return $items;
    }

    private function buildCheckoutTitle(Venta $venta, bool $isPendingBalance): string
    {
        $names = $venta->detalles
            ->pluck('nombre_producto')
            ->filter()
            ->unique()
            ->values();

        $summary = match ($names->count()) {
            0 => 'Compra Serafim',
            1 => (string) $names->first(),
            2 => sprintf('%s y %s', $names[0], $names[1]),
            default => sprintf('%s + %d mas', $names[0], $names->count() - 1),
        };

        $title = $isPendingBalance
            ? sprintf('Saldo pendiente %s', $summary)
            : $summary;

        return substr($title, 0, 120);
    }

    private function buildCheckoutDescription(Venta $venta, bool $isPendingBalance): string
    {
        $currency = $venta->moneda ?: 'ARS';

        $detailLines = $venta->detalles
            ->map(function ($detalle) use ($currency) {
                $name = trim((string) $detalle->nombre_producto);
                $quantity = (int) $detalle->cantidad;
                $subtotal = (float) $detalle->subtotal;

                if ($name === '') {
                    return null;
                }

                return sprintf(
                    '%s x%d (%s)',
                    $name,
                    $quantity,
                    $this->formatMoney($subtotal, $currency)
                );
            })
            ->filter()
            ->values()
            ->all();

        $segments = [];

        $segments[] = $isPendingBalance
            ? sprintf('Saldo pendiente venta %s', $venta->numero_venta)
            : sprintf('Venta %s', $venta->numero_venta);

        $segments[] = 'Productos: ' . (!empty($detailLines) ? implode(', ', $detailLines) : 'Sin detalle');

        if ((float) $venta->costo_envio > 0) {
            $segments[] = 'Envio: ' . $this->formatMoney((float) $venta->costo_envio, $currency);
        }

        if ((float) $venta->descuento > 0) {
            $segments[] = 'Descuento: -' . $this->formatMoney((float) $venta->descuento, $currency);
        }

        if ($isPendingBalance) {
            $segments[] = 'Saldo a cobrar: ' . $this->formatMoney($this->resolvePendingAmount($venta), $currency);
        } else {
            $segments[] = 'Total: ' . $this->formatMoney((float) $venta->total, $currency);
        }

        return substr(implode(' | ', $segments), 0, 255);
    }

    private function buildCheckoutDescriptionForPago(Venta $venta, Pago $pago): string
    {
        $currency = $pago->moneda ?: $venta->moneda;

        $detailLines = $venta->detalles
            ->map(function ($detalle) use ($currency) {
                $name = trim((string) $detalle->nombre_producto);
                $quantity = (int) $detalle->cantidad;
                $subtotal = (float) $detalle->subtotal;

                if ($name === '') {
                    return null;
                }

                return sprintf('%s x%d (%s)', $name, $quantity, $this->formatMoney($subtotal, $currency));
            })
            ->filter()
            ->values()
            ->all();

        $segments = [
            sprintf('Pago venta %s', $venta->numero_venta),
            'Productos: ' . (!empty($detailLines) ? implode(', ', $detailLines) : 'Sin detalle'),
        ];

        if ((float) $venta->costo_envio > 0) {
            $segments[] = 'Envio: ' . $this->formatMoney((float) $venta->costo_envio, $currency);
        }

        if ((float) $venta->descuento > 0) {
            $segments[] = 'Descuento: -' . $this->formatMoney((float) $venta->descuento, $currency);
        }

        $segments[] = 'Pago a cobrar: ' . $this->formatMoney((float) $pago->monto, $currency);

        return substr(implode(' | ', $segments), 0, 255);
    }

    private function buildDetailedLineDescription($detalle, string $currency, float $discountShare): string
    {
        $segments = [];
        $segments[] = sprintf('Cantidad %d', max(1, (int) $detalle->cantidad));
        $segments[] = sprintf('Unitario %s', $this->formatMoney((float) $detalle->precio_unitario, $currency));

        if ($discountShare > 0) {
            $segments[] = sprintf('Descuento aplicado %s', $this->formatMoney($discountShare, $currency));
        }

        return substr(implode(' | ', $segments), 0, 255);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($amount, 2, '.', ''));
    }

    private function effectivePaidAmount($pagos): float
    {
        return (float) $pagos
            ->filter(fn (Pago $pago) => in_array($pago->estado, ['pagado', 'parcial'], true))
            ->sum(fn (Pago $pago) => (float) $pago->monto);
    }

    private function resolveSalePaymentStatus(Venta $venta): string
    {
        $paidAmount = $this->effectivePaidAmount($venta->pagos);
        $total = (float) $venta->total;

        if ($paidAmount >= $total && $total > 0) {
            return 'pagado';
        }

        return 'pendiente';
    }

    private function refreshVentaPaymentState(Venta $venta): void
    {
        $venta->update([
            'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
            'estado_venta' => $this->resolveSaleStatusAfterPayment($venta->fresh('pagos')),
            'mercado_pago_last_sync_at' => now(),
        ]);
    }

    private function resolvePagoCheckoutStatus(Pago $pago): string
    {
        return match ((string) $pago->estado) {
            'pagado' => 'approved',
            'cancelado' => 'rejected',
            'reembolsado' => 'refunded',
            default => 'generated',
        };
    }

    private function updatePagoMercadoPagoMetadata(Pago $pago, array $metadata): void
    {
        $current = (array) $pago->datos_externos;
        $currentMercadoPago = (array) ($current['mercado_pago'] ?? []);
        $current['mercado_pago'] = array_filter(
            array_merge($currentMercadoPago, $metadata),
            fn ($value) => $value !== null && $value !== ''
        );

        $pago->update([
            'datos_externos' => $current,
        ]);
    }

    private function resolveSaleStatusAfterPayment(Venta $venta): string
    {
        if ($venta->estado_venta === 'pendiente' && $this->resolveSalePaymentStatus($venta) === 'pagado') {
            return 'confirmada';
        }

        return $venta->estado_venta;
    }

    private function resolveCheckoutStatus(Venta $venta): string
    {
        $salePaymentStatus = $this->resolveSalePaymentStatus($venta->fresh('pagos'));

        return match ($salePaymentStatus) {
            'pagado' => 'approved',
            default => 'generated',
        };
    }

    private function mapMercadoPagoStatus(?string $status): string
    {
        return match ($status) {
            'approved' => 'pagado',
            'in_process', 'authorized' => 'pendiente',
            'pending' => 'pendiente',
            'refunded', 'charged_back' => 'reembolsado',
            'cancelled', 'rejected' => 'cancelado',
            default => 'pendiente',
        };
    }

    private function mapMercadoPagoStatusToCheckoutStatus(?string $status): string
    {
        return match ($status) {
            'approved' => 'approved',
            'in_process', 'authorized', 'pending' => 'pending',
            'refunded', 'charged_back' => 'refunded',
            'cancelled', 'rejected' => 'rejected',
            default => 'generated',
        };
    }
}
