<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImagenProductoBase extends Model
{
    protected $table = 'imagenes_productos_base';

    protected $fillable = [
        'producto_base_id',
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

    public function productoBase()
    {
        return $this->belongsTo(ProductoBase::class);
    }
}
