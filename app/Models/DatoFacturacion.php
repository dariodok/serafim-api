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
        'afip_id_persona',
        'condicion_iva',
        'condicion_iva_receptor_id',
        'afip_estado_clave',
        'afip_ultima_consulta_at',
        'afip_datos',
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
            'condicion_iva_receptor_id' => 'integer',
            'afip_ultima_consulta_at' => 'datetime',
            'afip_datos' => 'json',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function afipConsultasFiscales()
    {
        return $this->hasMany(AfipConsultaFiscal::class, 'datos_facturacion_id');
    }
}
