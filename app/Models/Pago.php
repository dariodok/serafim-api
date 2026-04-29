<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'venta_id',
        'medio_pago',
        'estado',
        'monto',
        'moneda',
        'es_manual',
        'fecha_pago',
        'referencia_externa',
        'referencia_secundaria',
        'comprobante_manual',
        'datos_externos',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'es_manual' => 'boolean',
            'fecha_pago' => 'datetime',
            'datos_externos' => 'json',
        ];
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }
}
