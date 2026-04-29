<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDetalle extends Model
{
    protected $table = 'venta_detalles';

    protected $fillable = [
        'venta_id',
        'producto_venta_id',
        'sku_producto',
        'nombre_producto',
        'precio_unitario',
        'cantidad',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'cantidad' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function productoVenta()
    {
        return $this->belongsTo(ProductoVenta::class);
    }
}
