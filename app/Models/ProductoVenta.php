<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoVenta extends Model
{
    protected $table = 'productos_venta';

    protected $fillable = [
        'sku',
        'nombre',
        'descripcion',
        'precio',
        'peso_gramos',
        'alto_cm',
        'ancho_cm',
        'largo_cm',
        'activo',
        'visible',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'alto_cm' => 'decimal:2',
            'ancho_cm' => 'decimal:2',
            'largo_cm' => 'decimal:2',
            'peso_gramos' => 'integer',
            'activo' => 'boolean',
            'visible' => 'boolean',
        ];
    }

    public function imagenes()
    {
        return $this->hasMany(ImagenProductoVenta::class, 'producto_venta_id');
    }

    public function componentes()
    {
        return $this->hasMany(ProductoVentaComponente::class, 'producto_venta_id');
    }
}
