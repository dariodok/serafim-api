<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteFacturacion extends Model
{
    protected $table = 'comprobantes_facturacion';

    protected $fillable = [
        'venta_id',
        'datos_facturacion_id',
        'comprobante_asociado_id',
        'tipo_comprobante',
        'codigo_tipo_comprobante',
        'punto_venta',
        'numero_comprobante',
        'fecha_emision',
        'estado',
        'ambiente',
        'razon_social',
        'nombre_completo',
        'tipo_documento',
        'codigo_tipo_documento',
        'numero_documento',
        'cuit',
        'condicion_iva',
        'condicion_iva_receptor_id',
        'email_facturacion',
        'domicilio_fiscal',
        'subtotal',
        'importe_iva',
        'total',
        'moneda',
        'moneda_cotizacion',
        'cae',
        'cae_vencimiento',
        'qr_payload',
        'qr_url',
        'solicitud_externa',
        'respuesta_externa',
        'observaciones_afip',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'codigo_tipo_comprobante' => 'integer',
            'codigo_tipo_documento' => 'integer',
            'condicion_iva_receptor_id' => 'integer',
            'subtotal' => 'decimal:2',
            'importe_iva' => 'decimal:2',
            'total' => 'decimal:2',
            'moneda_cotizacion' => 'decimal:6',
            'cae_vencimiento' => 'datetime',
            'qr_payload' => 'json',
            'solicitud_externa' => 'json',
            'respuesta_externa' => 'json',
            'observaciones_afip' => 'json',
        ];
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function datosFacturacion()
    {
        return $this->belongsTo(DatoFacturacion::class);
    }

    public function comprobanteAsociado()
    {
        return $this->belongsTo(self::class, 'comprobante_asociado_id');
    }

    public function notasCredito()
    {
        return $this->hasMany(self::class, 'comprobante_asociado_id');
    }
}
