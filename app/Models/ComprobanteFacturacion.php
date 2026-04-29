<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteFacturacion extends Model
{
    protected $table = 'comprobantes_facturacion';

    protected $fillable = [
        'venta_id',
        'datos_facturacion_id',
        'tipo_comprobante',
        'punto_venta',
        'numero_comprobante',
        'fecha_emision',
        'estado',
        'razon_social',
        'nombre_completo',
        'tipo_documento',
        'numero_documento',
        'cuit',
        'condicion_iva',
        'email_facturacion',
        'domicilio_fiscal',
        'subtotal',
        'importe_iva',
        'total',
        'cae',
        'cae_vencimiento',
        'respuesta_externa',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'subtotal' => 'decimal:2',
            'importe_iva' => 'decimal:2',
            'total' => 'decimal:2',
            'cae_vencimiento' => 'datetime',
            'respuesta_externa' => 'json',
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
}
