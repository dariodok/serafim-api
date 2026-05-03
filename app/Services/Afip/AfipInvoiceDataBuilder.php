<?php

namespace App\Services\Afip;

use App\Models\ComprobanteFacturacion;
use App\Models\DatoFacturacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AfipInvoiceDataBuilder
{
    private const VOUCHER_TYPES = [
        'factura_a' => ['code' => 1, 'letter' => 'A', 'label' => 'Factura A'],
        'factura_b' => ['code' => 6, 'letter' => 'B', 'label' => 'Factura B'],
        'nota_credito_a' => ['code' => 3, 'letter' => 'A', 'label' => 'Nota de credito A'],
        'nota_credito_b' => ['code' => 8, 'letter' => 'B', 'label' => 'Nota de credito B'],
    ];

    private const IVA_CONDITIONS = [
        1 => ['responsable inscripto', 'iva responsable inscripto', 'ri'],
        4 => ['iva sujeto exento', 'sujeto exento', 'exento', 'iva exento'],
        5 => ['consumidor final', 'cf', 'final'],
        6 => ['responsable monotributo', 'monotributo', 'monotributista'],
        7 => ['sujeto no categorizado', 'no categorizado'],
        8 => ['proveedor del exterior'],
        9 => ['cliente del exterior'],
        10 => ['iva liberado', 'iva liberado ley 19640', 'ley 19640'],
        13 => ['monotributista social'],
        15 => ['iva no alcanzado', 'no alcanzado'],
        16 => ['monotributo trabajador independiente promovido', 'trabajador independiente promovido'],
    ];

    public function buildInvoiceDraft(Venta $venta, ?string $requestedType = null): array
    {
        $venta->loadMissing('datosFacturacion');

        $dato = $venta->datosFacturacion;

        if (! $dato) {
            throw ValidationException::withMessages([
                'datos_facturacion_id' => 'La venta no tiene datos de facturacion asociados.',
            ]);
        }

        $localType = $this->resolveInvoiceType($dato, $requestedType);
        $voucher = self::VOUCHER_TYPES[$localType];
        $receiver = $this->resolveReceiver($dato, $localType);
        $amounts = $this->splitGrossAmount((float) $venta->total);

        return $this->buildDraft(
            venta: $venta,
            dato: $dato,
            localType: $localType,
            voucher: $voucher,
            receiver: $receiver,
            amounts: $amounts,
        );
    }

    public function buildCreditNoteDraft(ComprobanteFacturacion $invoice, ?string $requestedType = null): array
    {
        $invoice->loadMissing('venta.datosFacturacion');

        if (! in_array($invoice->tipo_comprobante, ['factura_a', 'factura_b'], true)) {
            throw ValidationException::withMessages([
                'comprobante' => 'Solo se puede emitir una nota de credito sobre una factura.',
            ]);
        }

        if ($invoice->estado !== 'autorizado' || ! $invoice->cae || ! $invoice->numero_comprobante) {
            throw ValidationException::withMessages([
                'comprobante' => 'La factura debe estar autorizada por AFIP antes de emitir una nota de credito.',
            ]);
        }

        $venta = $invoice->venta;
        $dato = $venta?->datosFacturacion;

        if (! $venta || ! $dato) {
            throw ValidationException::withMessages([
                'comprobante' => 'No se encontraron la venta o los datos de facturacion originales.',
            ]);
        }

        $localType = $this->resolveCreditNoteType($invoice, $requestedType);
        $voucher = self::VOUCHER_TYPES[$localType];
        $receiver = [
            'doc_type' => (int) $invoice->codigo_tipo_documento,
            'doc_type_label' => (string) $invoice->tipo_documento,
            'doc_number' => (int) ($invoice->numero_documento ?: $invoice->cuit ?: 0),
            'iva_condition_id' => (int) $invoice->condicion_iva_receptor_id,
        ];
        $amounts = [
            'net' => round((float) $invoice->subtotal, 2),
            'vat' => round((float) $invoice->importe_iva, 2),
            'total' => round((float) $invoice->total, 2),
        ];

        $draft = $this->buildDraft(
            venta: $venta,
            dato: $dato,
            localType: $localType,
            voucher: $voucher,
            receiver: $receiver,
            amounts: $amounts,
        );

        $draft['associated_invoice'] = $invoice;
        $draft['request']['FeDetReq']['FECAEDetRequest']['CbtesAsoc'] = [
            'CbteAsoc' => [
                'Tipo' => (int) $invoice->codigo_tipo_comprobante,
                'PtoVta' => (int) $invoice->punto_venta,
                'Nro' => (int) $invoice->numero_comprobante,
                'CbteFch' => $invoice->fecha_emision?->format('Ymd'),
            ],
        ];

        return $draft;
    }

    public function resolveInvoiceType(DatoFacturacion $dato, ?string $requestedType = null): string
    {
        if ($requestedType !== null && trim($requestedType) !== '') {
            return $this->normalizeRequestedType($requestedType, false);
        }

        $ivaConditionId = $this->resolveIvaConditionId((string) $dato->condicion_iva);

        if (in_array($ivaConditionId, [1, 6, 13, 16], true) && $this->onlyDigits((string) $dato->cuit) !== '') {
            return 'factura_a';
        }

        return 'factura_b';
    }

    private function resolveCreditNoteType(ComprobanteFacturacion $invoice, ?string $requestedType = null): string
    {
        if ($requestedType !== null && trim($requestedType) !== '') {
            return $this->normalizeRequestedType($requestedType, true);
        }

        return $invoice->tipo_comprobante === 'factura_a'
            ? 'nota_credito_a'
            : 'nota_credito_b';
    }

    private function normalizeRequestedType(string $type, bool $creditNote): string
    {
        $normalized = Str::of($type)
            ->lower()
            ->ascii()
            ->replace([' ', '-', '.'], '_')
            ->toString();

        $aliases = [
            'a' => 'factura_a',
            'fa' => 'factura_a',
            'factura_a' => 'factura_a',
            'b' => 'factura_b',
            'fb' => 'factura_b',
            'factura_b' => 'factura_b',
            'na' => 'nota_credito_a',
            'nca' => 'nota_credito_a',
            'nota_credito_a' => 'nota_credito_a',
            'nota_de_credito_a' => 'nota_credito_a',
            'nb' => 'nota_credito_b',
            'ncb' => 'nota_credito_b',
            'nota_credito_b' => 'nota_credito_b',
            'nota_de_credito_b' => 'nota_credito_b',
        ];

        $localType = $aliases[$normalized] ?? null;

        if (! $localType || ! isset(self::VOUCHER_TYPES[$localType])) {
            throw ValidationException::withMessages([
                'tipo_comprobante' => 'El tipo de comprobante solicitado no es valido.',
            ]);
        }

        if ($creditNote && ! str_starts_with($localType, 'nota_credito_')) {
            throw ValidationException::withMessages([
                'tipo_comprobante' => 'Para esta operacion se debe indicar una nota de credito A o B.',
            ]);
        }

        if (! $creditNote && ! str_starts_with($localType, 'factura_')) {
            throw ValidationException::withMessages([
                'tipo_comprobante' => 'Para esta operacion se debe indicar una factura A o B.',
            ]);
        }

        return $localType;
    }

    private function resolveReceiver(DatoFacturacion $dato, string $localType): array
    {
        $ivaConditionId = $this->resolveIvaConditionId((string) $dato->condicion_iva);
        $cuit = $this->onlyDigits((string) $dato->cuit);
        $dni = $this->onlyDigits((string) $dato->numero_documento);

        if (str_ends_with($localType, '_a')) {
            if (! $this->isValidCuit($cuit)) {
                throw ValidationException::withMessages([
                    'cuit' => 'Para emitir Factura A o Nota de Credito A se requiere un CUIT valido del receptor.',
                ]);
            }

            if (! in_array($ivaConditionId, [1, 6, 13, 16], true)) {
                throw ValidationException::withMessages([
                    'condicion_iva' => 'Solo responsables inscriptos o monotributistas pueden recibir comprobante A.',
                ]);
            }

            return [
                'doc_type' => 80,
                'doc_type_label' => 'CUIT',
                'doc_number' => (int) $cuit,
                'iva_condition_id' => $ivaConditionId,
            ];
        }

        if ($this->isValidCuit($cuit)) {
            return [
                'doc_type' => 80,
                'doc_type_label' => 'CUIT',
                'doc_number' => (int) $cuit,
                'iva_condition_id' => $ivaConditionId,
            ];
        }

        if ($dni !== '') {
            return [
                'doc_type' => 96,
                'doc_type_label' => 'DNI',
                'doc_number' => (int) $dni,
                'iva_condition_id' => $ivaConditionId,
            ];
        }

        return [
            'doc_type' => 99,
            'doc_type_label' => 'Consumidor Final',
            'doc_number' => 0,
            'iva_condition_id' => 5,
        ];
    }

    private function resolveIvaConditionId(string $condition): int
    {
        $normalized = Str::of($condition)->lower()->ascii()->squish()->toString();

        foreach (self::IVA_CONDITIONS as $id => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $id;
            }
        }

        throw ValidationException::withMessages([
            'condicion_iva' => 'La condicion de IVA no esta mapeada para facturacion electronica.',
        ]);
    }

    private function buildDraft(
        Venta $venta,
        DatoFacturacion $dato,
        string $localType,
        array $voucher,
        array $receiver,
        array $amounts,
    ): array {
        $date = CarbonImmutable::now((string) config('afip.timezone'))->format('Ymd');
        $pointOfSale = (int) config('afip.point_of_sale');
        $currency = (string) config('afip.currency_id', 'PES');
        $currencyRate = (float) config('afip.currency_rate', 1);

        return [
            'venta' => $venta,
            'dato_facturacion' => $dato,
            'local_type' => $localType,
            'afip_type' => (int) $voucher['code'],
            'letter' => $voucher['letter'],
            'label' => $voucher['label'],
            'point_of_sale' => $pointOfSale,
            'receiver' => $receiver,
            'amounts' => $amounts,
            'currency' => $currency,
            'currency_rate' => $currencyRate,
            'issue_date' => $date,
            'fiscal_address' => $this->formatFiscalAddress($dato),
            'request' => [
                'FeCabReq' => [
                    'CantReg' => 1,
                    'PtoVta' => $pointOfSale,
                    'CbteTipo' => (int) $voucher['code'],
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => [
                        'Concepto' => (int) config('afip.concept', 1),
                        'DocTipo' => $receiver['doc_type'],
                        'DocNro' => $receiver['doc_number'],
                        'CbteDesde' => 0,
                        'CbteHasta' => 0,
                        'CbteFch' => $date,
                        'ImpTotal' => $amounts['total'],
                        'ImpTotConc' => 0,
                        'ImpNeto' => $amounts['net'],
                        'ImpOpEx' => 0,
                        'ImpTrib' => 0,
                        'ImpIVA' => $amounts['vat'],
                        'MonId' => $currency,
                        'MonCotiz' => $currencyRate,
                        'CondicionIVAReceptorId' => $receiver['iva_condition_id'],
                        'Iva' => [
                            'AlicIva' => [
                                'Id' => (int) config('afip.vat_afip_id', 5),
                                'BaseImp' => $amounts['net'],
                                'Importe' => $amounts['vat'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function splitGrossAmount(float $gross): array
    {
        if ($gross <= 0) {
            throw ValidationException::withMessages([
                'total' => 'El total a facturar debe ser mayor a cero.',
            ]);
        }

        $vatRate = (float) config('afip.vat_rate', 0.21);
        $net = round($gross / (1 + $vatRate), 2);
        $vat = round($gross - $net, 2);

        return [
            'net' => $net,
            'vat' => $vat,
            'total' => round($net + $vat, 2),
        ];
    }

    private function formatFiscalAddress(DatoFacturacion $dato): string
    {
        $street = trim(sprintf('%s %s', $dato->calle, $dato->numero));
        $floor = trim(collect([$dato->piso, $dato->departamento])->filter()->implode(' '));
        $city = trim(collect([$dato->codigo_postal, $dato->localidad, $dato->provincia])->filter()->implode(' - '));

        return collect([$street, $floor, $city])
            ->filter()
            ->implode(', ');
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function isValidCuit(string $value): bool
    {
        if (! preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $value[$index]) * $weight;
        }

        $mod = 11 - ($sum % 11);
        $checkDigit = $mod === 11 ? 0 : ($mod === 10 ? 9 : $mod);

        return $checkDigit === (int) $value[10];
    }
}
