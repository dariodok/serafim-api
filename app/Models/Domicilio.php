<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domicilio extends Model
{
    protected $table = 'domicilios';

    protected $fillable = [
        'usuario_id',
        'alias',
        'destinatario',
        'telefono_contacto',
        'provincia',
        'localidad',
        'codigo_postal',
        'calle',
        'numero',
        'piso',
        'departamento',
        'referencia',
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
