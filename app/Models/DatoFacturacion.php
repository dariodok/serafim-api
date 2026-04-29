<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatoFacturacion extends Model
{
    protected $table = 'datos_facturacion';

    protected $fillable = [
        'usuario_id',
        'alias',
        'tipo_persona',
        'razon_social',
        'nombre_completo',
        'tipo_documento',
        'numero_documento',
        'cuit',
        'condicion_iva',
        'email_facturacion',
        'provincia',
        'localidad',
        'codigo_postal',
        'calle',
        'numero',
        'piso',
        'departamento',
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
