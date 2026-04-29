<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoBase extends Model
{
    protected $table = 'productos_base';

    protected $fillable = [
        'sku',
        'nombre',
        'descripcion',
        'stock_actual',
        'stock_minimo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'stock_actual' => 'integer',
            'stock_minimo' => 'integer',
        ];
    }

    public function imagenes()
    {
        return $this->hasMany(ImagenProductoBase::class, 'producto_base_id');
    }

    public function componentes()
    {
        return $this->hasMany(ProductoVentaComponente::class, 'producto_base_id');
    }
}
