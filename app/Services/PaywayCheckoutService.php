<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\Venta;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PaywayCheckoutService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function createCheckoutForPago(Pago $pago): array
    {
        $pago->loadMissing(['venta.usuario', 'venta.detalles', 'venta.pagos']);

        if ($pago->medio_pago !== 'Payway') {
            throw ValidationException::withMessages([
                'pago' => 'Solo se puede generar checkout para pagos de Payway.',
            ]);
        }

        if ((float) $pago->monto <= 0) {
            throw ValidationException::withMessages([
                'pago' => 'El pago debe tener un monto mayor a cero para generar checkout.',
            ]);
        }

        if (in_array($pago->estado, ['pagado', 'reembolsado'], true)) {
            throw ValidationException::withMessages([
                'pago' => 'El pago ya no admite generar un nuevo checkout.',
            ]);
        }

        $venta = $pago->venta;
        $externalReference = sprintf('pago-%s', $pago->id);
        $payload = $this->buildCheckoutPayload($venta, $pago, $externalReference);

        $response = $this->request()
            ->post('/link', $payload);

        if ($response->failed()) {
            $this->throwValidationFromResponse($response);
        }

        $data = $response->json() ?: [];
        $link = $this->resolveCheckoutLink($data);
        $checkoutId = (string) ($data['id'] ?? '');
        $checkoutHash = (string) ($data['hash'] ?? $data['checkout_hash'] ?? '');

        $this->updatePagoPaywayMetadata($pago, [
            'checkout_id' => $checkoutId !== '' ? $checkoutId : null,
            'checkout_hash' => $checkoutHash !== '' ? $checkoutHash : null,
            'external_reference' => $externalReference,
            'checkout_status' => 'generated',
            'checkout_link' => $link !== '' ? $link : null,
            'last_sync_at' => now()?->toIso8601String(),
            'raw' => $data,
        ]);

        $pago->update([
            'estado' => 'pendiente',
            'fecha_pago' => null,
        ]);

        return $data;
    }

    public function extractCheckoutLinkFromPago(Pago $pago): string
    {
        return (string) data_get($pago->datos_externos, 'payway.checkout_link', '');
    }

    public function backfillStoredCheckoutLinks(): int
    {
        $updated = 0;

        Pago::query()
            ->where('medio_pago', 'Payway')
            ->whereNotNull('datos_externos')
            ->orderBy('id')
            ->each(function (Pago $pago) use (&$updated) {
                $existingLink = (string) data_get($pago->datos_externos, 'payway.checkout_link', '');
                $rawLink = (string) data_get($pago->datos_externos, 'payway.raw.payment_link', '');

                if ($existingLink !== '' || $rawLink === '') {
                    return;
                }

                $this->updatePagoPaywayMetadata($pago, [
                    'checkout_link' => $rawLink,
                ]);

                $updated++;
            });

        return $updated;
    }

    public function syncPago(Pago $pago): Pago
    {
        $pago->loadMissing('venta.pagos');

        if ($pago->medio_pago !== 'Payway') {
            throw ValidationException::withMessages([
                'pago' => 'Solo se puede verificar manualmente pagos de Payway.',
            ]);
        }

        $paymentId = (string) data_get($pago->datos_externos, 'payway.payment_id', '');
        $paymentData = $paymentId !== ''
            ? $this->fetchPaymentInfo($paymentId)
            : $this->findPaymentByExternalReference($pago);

        if ($paymentData === null) {
            $this->updatePagoPaywayMetadata($pago, [
                'checkout_status' => data_get($pago->datos_externos, 'payway.checkout_status', 'generated'),
                'last_sync_at' => now()?->toIso8601String(),
            ]);

            return $pago->fresh(['venta.usuario', 'venta.pagos']);
        }

        $this->syncPaymentData($pago, $paymentData);
        $this->refreshVentaPaymentState($pago->venta->fresh('pagos'));

        return $pago->fresh(['venta.usuario', 'venta.pagos']);
    }

    private function buildCheckoutPayload(Venta $venta, Pago $pago, string $externalReference): array
    {
        $currency = $pago->moneda ?: $venta->moneda ?: 'ARS';
        $totalPrice = round((float) $pago->monto, 2);
        $notificationUrl = trim((string) config('services.payway.notifications_url')) ?: route('payway.webhook');
        $redirectUrl = trim((string) config('services.payway.redirect_url'));
        $installments = $this->resolveInstallments();
        $templateId = $this->resolveTemplateId();

        $payload = [
            'origin_platform' => 'serafim-api',
            'site_transaction_id' => $externalReference,
            'currency' => $currency,
            'total_price' => $totalPrice,
            'site' => (string) config('services.payway.site_id'),
            'notifications_url' => $notificationUrl,
            'template_id' => $templateId,
            'installments' => $installments,
            'plan_gobierno' => $this->normalizeBoolean(config('services.payway.plan_gobierno'), false),
            'public_apikey' => (string) config('services.payway.public_api_key'),
            'auth_3ds' => $this->normalizeBoolean(config('services.payway.auth_3ds'), false),
        ];

        $successUrl = trim((string) config('services.payway.success_url'));
        $cancelUrl = trim((string) config('services.payway.cancel_url'));

        if ($successUrl !== '' && $cancelUrl !== '') {
            $payload['success_url'] = $successUrl;
            $payload['cancel_url'] = $cancelUrl;
        } elseif ($redirectUrl !== '') {
            $payload['redirect_url'] = $redirectUrl;
        }

        $products = $this->buildProducts($venta, $pago);
        if (!empty($products)) {
            $payload['products'] = $products;
        } else {
            $payload['payment_description'] = $this->buildPaymentDescription($venta, $pago);
        }

        $paymentMethodId = config('services.payway.payment_method_id');
        if ($paymentMethodId !== null && $paymentMethodId !== '') {
            $payload['id_payment_method'] = (int) $paymentMethodId;
        }

        return $payload;
    }

    private function findPaymentByExternalReference(Pago $pago): ?array
    {
        $externalReference = (string) (
            data_get($pago->datos_externos, 'payway.external_reference')
            ?: sprintf('pago-%s', $pago->id)
        );

        $response = $this->gatewayRequest()
            ->get('/payments', [
                'offset' => 0,
                'pageSize' => 20,
                'siteOperationId' => $externalReference,
                'merchantId' => (string) config('services.payway.site_id'),
            ]);

        if ($response->failed()) {
            $this->throwGatewayValidationFromResponse($response, 'No se pudo consultar el listado de pagos de Payway.');
        }

        $results = $this->extractPaymentResults($response->json() ?: []);
        if ($results === []) {
            return null;
        }

        usort($results, function (array $left, array $right) {
            $leftDate = strtotime((string) ($left['date_approved'] ?? $left['created_date'] ?? $left['date'] ?? '1970-01-01'));
            $rightDate = strtotime((string) ($right['date_approved'] ?? $right['created_date'] ?? $right['date'] ?? '1970-01-01'));

            return $rightDate <=> $leftDate;
        });

        return $results[0];
    }

    private function fetchPaymentInfo(string $paymentId): ?array
    {
        $response = $this->gatewayRequest()->get(sprintf('/payments/%s', $paymentId));

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            $this->throwGatewayValidationFromResponse($response, 'No se pudo consultar el pago de Payway.');
        }

        return $response->json() ?: null;
    }

    private function buildProducts(Venta $venta, Pago $pago): array
    {
        $amount = round((float) $pago->monto, 2);
        $isPendingBalance = $amount < (float) $venta->total;

        // Payway valida estrictamente que total_price sea igual a la suma de products.
        // Para evitar descalces por descuentos, envio o interpretacion de quantity/value,
        // usamos una unica linea resumen por el monto exacto del pago.
        return [[
            'id' => (int) $pago->id,
            'value' => $amount,
            'description' => $this->buildCheckoutTitle($venta, $isPendingBalance),
            'quantity' => 1,
        ]];
    }

    private function buildPaymentDescription(Venta $venta, Pago $pago): string
    {
        $currency = $pago->moneda ?: $venta->moneda ?: 'ARS';

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

    private function resolveInstallments(): array
    {
        $raw = (string) config('services.payway.installments', '1');

        $items = collect(explode(',', $raw))
            ->map(fn (string $item) => (int) trim($item))
            ->filter(fn (int $item) => $item > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($items)) {
            return [1];
        }

        // Este endpoint exige un unico valor de cuotas por request.
        return [(int) $items[0]];
    }

    private function resolveTemplateId(): int
    {
        $configured = (int) config('services.payway.template_id', 1);

        // En checkout-payment-button/link el API acepta 1 (sin CS) o 2 (con CS).
        return in_array($configured, [1, 2], true) ? $configured : 1;
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

    private function formatMoney(float $amount, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($amount, 2, '.', ''));
    }

    private function syncPaymentData(Pago $pago, array $paymentData): void
    {
        $venta = $pago->venta;
        $paymentId = (string) ($paymentData['id'] ?? data_get($paymentData, 'payment_id', ''));
        $amount = $this->resolvePaymentAmount($paymentData, (float) $pago->monto);
        $status = (string) ($paymentData['status'] ?? '');
        $existingPaywayMetadata = (array) data_get($pago->datos_externos, 'payway', []);

        $pago->update([
            'medio_pago' => 'Payway',
            'estado' => $this->mapPaywayStatus($status),
            'monto' => $amount,
            'moneda' => (string) ($paymentData['currency'] ?? $paymentData['currency_id'] ?? $pago->moneda ?? $venta->moneda),
            'fecha_pago' => $paymentData['date_approved'] ?? $paymentData['created_date'] ?? $paymentData['date'] ?? $pago->fecha_pago,
            'referencia_externa' => $paymentId !== '' ? $paymentId : $pago->referencia_externa,
            'referencia_secundaria' => (string) (
                $paymentData['ticket'] ?? $paymentData['authorization_code'] ?? $pago->referencia_secundaria ?? ''
            ) ?: null,
            'observaciones' => (string) ($paymentData['status_details'] ?? $paymentData['status_detail'] ?? $pago->observaciones ?? '') ?: null,
            'datos_externos' => array_merge((array) $pago->datos_externos, [
                'payway' => array_filter(array_merge($existingPaywayMetadata, [
                    'payment_id' => $paymentId !== '' ? $paymentId : null,
                    'status' => $status !== '' ? $status : null,
                    'status_details' => $paymentData['status_details'] ?? $paymentData['status_detail'] ?? null,
                    'external_reference' => $paymentData['site_transaction_id'] ?? $existingPaywayMetadata['external_reference'] ?? null,
                    'checkout_status' => $this->mapPaywayStatusToCheckoutStatus($status),
                    'checkout_link' => $this->resolveCheckoutLink($paymentData) ?: ($existingPaywayMetadata['checkout_link'] ?? null),
                    'last_sync_at' => now()?->toIso8601String(),
                    'raw_payment' => $paymentData,
                ]), fn ($value) => $value !== null && $value !== ''),
            ]),
        ]);
    }

    private function resolvePaymentAmount(array $paymentData, float $fallback): float
    {
        $value = $paymentData['transaction_amount']
            ?? $paymentData['amount']
            ?? data_get($paymentData, 'operation.amount')
            ?? $fallback;

        return round((float) $value, 2);
    }

    private function extractPaymentResults(array $response): array
    {
        $candidates = [
            $response['results'] ?? null,
            $response['payments'] ?? null,
            data_get($response, 'data.results'),
            data_get($response, 'data.payments'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    private function mapPaywayStatus(?string $status): string
    {
        return match ($status) {
            'approved', 'accredited', 'processed', 'closed' => 'pagado',
            'refunded', 'partial_refunded', 'charged_back' => 'reembolsado',
            'rejected', 'cancelled', 'voided', 'expired' => 'cancelado',
            default => 'pendiente',
        };
    }

    private function mapPaywayStatusToCheckoutStatus(?string $status): string
    {
        return match ($status) {
            'approved', 'accredited', 'processed', 'closed' => 'approved',
            'refunded', 'partial_refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled', 'voided', 'expired' => 'rejected',
            default => 'pending',
        };
    }

    private function refreshVentaPaymentState(Venta $venta): void
    {
        $venta->update([
            'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
        ]);
    }

    private function resolveSalePaymentStatus(Venta $venta): string
    {
        $paidAmount = (float) $venta->pagos
            ->filter(fn (Pago $item) => in_array($item->estado, ['pagado', 'parcial'], true))
            ->sum(fn (Pago $item) => (float) $item->monto);

        $total = (float) $venta->total;

        if ($paidAmount >= $total && $total > 0) {
            return 'pagado';
        }

        return 'pendiente';
    }

    private function resolveCheckoutLink(array $response): string
    {
        $direct = Arr::first([
            $response['payment_link'] ?? null,
            $response['link'] ?? null,
            $response['url'] ?? null,
            data_get($response, 'raw.payment_link'),
            data_get($response, 'data.link'),
            data_get($response, 'data.url'),
            data_get($response, 'response.link'),
            data_get($response, 'response.url'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        return is_string($direct) ? trim($direct) : '';
    }

    private function request()
    {
        $privateApiKey = trim((string) config('services.payway.private_api_key'));
        $baseUrl = rtrim(trim((string) config('services.payway.checkout_base_url')), '/');
        $siteId = trim((string) config('services.payway.site_id'));
        $templateId = trim((string) config('services.payway.template_id'));
        $publicApiKey = trim((string) config('services.payway.public_api_key'));
        $successUrl = trim((string) config('services.payway.success_url'));
        $cancelUrl = trim((string) config('services.payway.cancel_url'));

        if ($privateApiKey === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_PRIVATE_API_KEY.',
            ]);
        }

        if ($publicApiKey === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_PUBLIC_API_KEY.',
            ]);
        }

        if ($siteId === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_SITE_ID.',
            ]);
        }

        if ($templateId === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_TEMPLATE_ID.',
            ]);
        }

        if ($successUrl === '' || $cancelUrl === '') {
            throw ValidationException::withMessages([
                'payway' => 'Configura PAYWAY_SUCCESS_URL y PAYWAY_CANCEL_URL para operar checkout.',
            ]);
        }

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_CHECKOUT_BASE_URL.',
            ]);
        }

        return $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders([
                'apikey' => $privateApiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(20);
    }

    private function gatewayRequest()
    {
        $privateApiKey = trim((string) config('services.payway.private_api_key'));
        $baseUrl = rtrim(trim((string) config('services.payway.api_base_url')), '/');

        if ($privateApiKey === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_PRIVATE_API_KEY.',
            ]);
        }

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'payway' => 'Falta configurar PAYWAY_API_BASE_URL.',
            ]);
        }

        return $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders([
                'apikey' => $privateApiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(20);
    }

    private function updatePagoPaywayMetadata(Pago $pago, array $metadata): void
    {
        $current = (array) $pago->datos_externos;
        $currentPayway = (array) ($current['payway'] ?? []);
        $current['payway'] = array_filter(
            array_merge($currentPayway, $metadata),
            fn ($value) => $value !== null && $value !== ''
        );

        $pago->update([
            'datos_externos' => $current,
        ]);
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $parsed ?? $default;
        }

        return $default;
    }

    private function throwValidationFromResponse(Response $response): never
    {
        $message = (string) $response->json('description', 'No se pudo generar el checkout de Payway.');
        $errors = collect($response->json('validation_errors', []))
            ->map(function ($item) {
                $code = (string) ($item['code'] ?? 'validation_error');
                $param = (string) ($item['param'] ?? 'unknown');
                $detail = (string) ($item['message'] ?? '');
                return trim(sprintf('%s (%s)%s', $code, $param, $detail !== '' ? ": {$detail}" : ''));
            })
            ->filter()
            ->values()
            ->all();

        throw ValidationException::withMessages([
            'payway' => $errors !== []
                ? sprintf('%s Detalle: %s', $message, implode(' | ', $errors))
                : $message,
        ]);
    }

    private function throwGatewayValidationFromResponse(Response $response, string $fallbackMessage): never
    {
        $body = $response->json() ?: [];
        $message = (string) ($body['description'] ?? $body['message'] ?? $fallbackMessage);

        throw ValidationException::withMessages([
            'payway' => $message,
        ]);
    }
}
