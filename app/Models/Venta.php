<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'numero_venta',
        'usuario_id',
        'datos_facturacion_id',
        'tipo_entrega',
        'estado_venta',
        'estado_pago',
        'medio_pago',
        'moneda',
        'subtotal',
        'descuento',
        'costo_envio',
        'total',
        'observaciones',
        'mercado_pago_preference_id',
        'mercado_pago_external_reference',
        'mercado_pago_init_point',
        'mercado_pago_sandbox_init_point',
        'mercado_pago_checkout_status',
        'mercado_pago_last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'costo_envio' => 'decimal:2',
            'total' => 'decimal:2',
            'mercado_pago_last_sync_at' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function datosFacturacion()
    {
        return $this->belongsTo(DatoFacturacion::class);
    }

    public function detalles()
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class);
    }

    public function comprobantes()
    {
        return $this->hasMany(ComprobanteFacturacion::class);
    }

    public function envios()
    {
        return $this->hasMany(Envio::class);
    }
}
