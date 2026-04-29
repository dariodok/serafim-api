<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvioBulto extends Model
{
    protected $table = 'envio_bultos';

    protected $fillable = [
        'envio_id',
        'numero_bulto',
        'estado',
        'referencia_externa',
        'codigo_seguimiento',
        'codigo_bulto',
        'url_etiqueta',
        'archivo_etiqueta',
        'formato_etiqueta',
        'valor_declarado',
        'peso_gramos',
        'alto_cm',
        'ancho_cm',
        'largo_cm',
        'respuesta_ultima_api',
        'datos_adicionales',
    ];

    protected function casts(): array
    {
        return [
            'valor_declarado' => 'decimal:2',
            'peso_gramos' => 'integer',
            'alto_cm' => 'decimal:2',
            'ancho_cm' => 'decimal:2',
            'largo_cm' => 'decimal:2',
            'respuesta_ultima_api' => 'json',
            'datos_adicionales' => 'json',
        ];
    }

    public function envio()
    {
        return $this->belongsTo(Envio::class);
    }
}
