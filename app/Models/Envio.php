<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Envio extends Model
{
    protected $table = 'envios';

    protected $fillable = [
        'venta_id',
        'domicilio_id',
        'proveedor',
        'servicio',
        'estado',
        'motivo_estado',
        'referencia_externa',
        'codigo_seguimiento',
        'codigo_bulto',
        'url_etiqueta',
        'archivo_etiqueta',
        'costo_envio',
        'moneda',
        'peso_gramos',
        'alto_cm',
        'ancho_cm',
        'largo_cm',
        'destinatario',
        'telefono',
        'provincia',
        'localidad',
        'codigo_postal',
        'calle',
        'numero',
        'piso',
        'departamento',
        'referencia',
        'fecha_generacion',
        'fecha_cancelacion',
        'fecha_despacho',
        'fecha_entrega',
        'respuesta_ultima_api',
        'datos_adicionales',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'costo_envio' => 'decimal:2',
            'peso_gramos' => 'integer',
            'alto_cm' => 'decimal:2',
            'ancho_cm' => 'decimal:2',
            'largo_cm' => 'decimal:2',
            'fecha_generacion' => 'datetime',
            'fecha_cancelacion' => 'datetime',
            'fecha_despacho' => 'datetime',
            'fecha_entrega' => 'datetime',
            'respuesta_ultima_api' => 'json',
            'datos_adicionales' => 'json',
        ];
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function domicilio()
    {
        return $this->belongsTo(Domicilio::class);
    }

    public function bultos()
    {
        return $this->hasMany(EnvioBulto::class)->orderBy('numero_bulto');
    }

    public function eventos()
    {
        return $this->hasMany(EnvioEvento::class)->orderByDesc('ocurrio_en')->orderByDesc('id');
    }
}
