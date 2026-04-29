<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Telefono extends Model
{
    protected $table = 'telefonos';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'numero',
        'principal',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
