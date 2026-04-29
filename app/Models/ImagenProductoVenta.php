<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImagenProductoVenta extends Model
{
    protected $table = 'imagenes_productos_venta';

    protected $fillable = [
        'producto_venta_id',
        'disco',
        'ruta',
        'texto_alternativo',
        'orden',
        'principal',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function productoVenta()
    {
        return $this->belongsTo(ProductoVenta::class);
    }
}
