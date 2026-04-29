<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoVentaComponente extends Model
{
    protected $table = 'productos_venta_componentes';

    protected $fillable = [
        'producto_venta_id',
        'producto_base_id',
        'cantidad_requerida',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_requerida' => 'integer',
        ];
    }

    public function productoVenta()
    {
        return $this->belongsTo(ProductoVenta::class);
    }

    public function productoBase()
    {
        return $this->belongsTo(ProductoBase::class);
    }
}
