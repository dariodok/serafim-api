<?php

namespace Tests\Unit;

use App\Models\ComprobanteFacturacion;
use App\Models\DatoFacturacion;
use App\Models\Venta;
use App\Services\Afip\AfipInvoiceDataBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AfipInvoiceDataBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('afip.point_of_sale', 4);
        Config::set('afip.vat_rate', 0.21);
        Config::set('afip.vat_afip_id', 5);
        Config::set('afip.currency_id', 'PES');
        Config::set('afip.currency_rate', 1);
        Config::set('afip.concept', 1);
        Config::set('afip.timezone', 'America/Argentina/Buenos_Aires');
    }

    public function test_builds_invoice_a_from_gross_total(): void
    {
        $draft = (new AfipInvoiceDataBuilder)->buildInvoiceDraft(
            $this->ventaConDatoFacturacion('IVA Responsable Inscripto', '30712345671', null, 1210),
        );

        $detail = $draft['request']['FeDetReq']['FECAEDetRequest'];

        $this->assertSame('factura_a', $draft['local_type']);
        $this->assertSame(1, $draft['request']['FeCabReq']['CbteTipo']);
        $this->assertSame(80, $detail['DocTipo']);
        $this->assertSame(1, $detail['CondicionIVAReceptorId']);
        $this->assertSame(1000.0, $detail['ImpNeto']);
        $this->assertSame(210.0, $detail['ImpIVA']);
        $this->assertSame(1210.0, $detail['ImpTotal']);
        $this->assertSame(1, $detail['Concepto']);
    }

    public function test_builds_invoice_b_for_final_consumer_with_dni(): void
    {
        $draft = (new AfipInvoiceDataBuilder)->buildInvoiceDraft(
            $this->ventaConDatoFacturacion('Consumidor final', null, '12345678', 242),
        );

        $detail = $draft['request']['FeDetReq']['FECAEDetRequest'];

        $this->assertSame('factura_b', $draft['local_type']);
        $this->assertSame(6, $draft['request']['FeCabReq']['CbteTipo']);
        $this->assertSame(96, $detail['DocTipo']);
        $this->assertSame(12345678, $detail['DocNro']);
        $this->assertSame(5, $detail['CondicionIVAReceptorId']);
        $this->assertSame(200.0, $detail['ImpNeto']);
        $this->assertSame(42.0, $detail['ImpIVA']);
    }

    public function test_builds_invoice_b_for_exempt_receiver_even_with_cuit(): void
    {
        $draft = (new AfipInvoiceDataBuilder)->buildInvoiceDraft(
            $this->ventaConDatoFacturacion('IVA Sujeto Exento', '30712345671', null, 242),
        );

        $detail = $draft['request']['FeDetReq']['FECAEDetRequest'];

        $this->assertSame('factura_b', $draft['local_type']);
        $this->assertSame(6, $draft['request']['FeCabReq']['CbteTipo']);
        $this->assertSame(80, $detail['DocTipo']);
        $this->assertSame(30712345671, $detail['DocNro']);
        $this->assertSame(4, $detail['CondicionIVAReceptorId']);
    }

    public function test_rejects_invoice_a_for_exempt_receiver(): void
    {
        $this->expectException(ValidationException::class);

        (new AfipInvoiceDataBuilder)->buildInvoiceDraft(
            $this->ventaConDatoFacturacion('IVA Sujeto Exento', '30712345671', null, 242),
            'factura_a',
        );
    }

    public function test_builds_total_credit_note_associated_to_original_invoice(): void
    {
        $venta = $this->ventaConDatoFacturacion('IVA Responsable Inscripto', '30712345671', null, 1210);
        $invoice = new ComprobanteFacturacion([
            'tipo_comprobante' => 'factura_a',
            'codigo_tipo_comprobante' => 1,
            'punto_venta' => '4',
            'numero_comprobante' => '25',
            'fecha_emision' => now(),
            'estado' => 'autorizado',
            'tipo_documento' => 'CUIT',
            'codigo_tipo_documento' => 80,
            'numero_documento' => '30712345671',
            'condicion_iva_receptor_id' => 1,
            'subtotal' => 1000,
            'importe_iva' => 210,
            'total' => 1210,
            'cae' => '12345678901234',
        ]);
        $invoice->setRelation('venta', $venta);

        $draft = (new AfipInvoiceDataBuilder)->buildCreditNoteDraft($invoice);

        $this->assertSame('nota_credito_a', $draft['local_type']);
        $this->assertSame(3, $draft['request']['FeCabReq']['CbteTipo']);
        $this->assertSame([
            'Tipo' => 1,
            'PtoVta' => 4,
            'Nro' => 25,
            'CbteFch' => $invoice->fecha_emision->format('Ymd'),
        ], $draft['request']['FeDetReq']['FECAEDetRequest']['CbtesAsoc']['CbteAsoc']);
    }

    private function ventaConDatoFacturacion(
        string $condition,
        ?string $cuit,
        ?string $dni,
        float $total,
    ): Venta {
        $dato = new DatoFacturacion([
            'id' => 10,
            'tipo_persona' => $cuit ? 'juridica' : 'fisica',
            'razon_social' => $cuit ? 'Cliente SA' : null,
            'nombre_completo' => $cuit ? null : 'Cliente Final',
            'tipo_documento' => $dni ? 'DNI' : null,
            'numero_documento' => $dni,
            'cuit' => $cuit,
            'condicion_iva' => $condition,
            'email_facturacion' => 'cliente@example.com',
            'provincia' => 'Buenos Aires',
            'localidad' => 'La Plata',
            'codigo_postal' => '1900',
            'calle' => 'Calle 1',
            'numero' => '123',
        ]);

        $venta = new Venta([
            'id' => 20,
            'numero_venta' => 'VTA-TEST',
            'total' => $total,
            'moneda' => 'ARS',
        ]);
        $venta->setRelation('datosFacturacion', $dato);

        return $venta;
    }
}
