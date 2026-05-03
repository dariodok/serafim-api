<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfipConsultaFiscal extends Model
{
    protected $table = 'afip_consultas_fiscales';

    protected $fillable = [
        'usuario_id',
        'datos_facturacion_id',
        'documento_consultado',
        'nombre_buscado',
        'cuit_consultado',
        'id_persona_seleccionada',
        'estado_resultado',
        'candidatos',
        'seleccion',
        'domicilios',
        'actividades',
        'impuestos',
        'regimenes',
        'categorias',
        'caracterizaciones',
        'relaciones',
        'a13_raw',
        'constancia_raw',
        'resultado_normalizado',
        'errores',
        'consultado_at',
    ];

    protected function casts(): array
    {
        return [
            'candidatos' => 'json',
            'seleccion' => 'json',
            'domicilios' => 'json',
            'actividades' => 'json',
            'impuestos' => 'json',
            'regimenes' => 'json',
            'categorias' => 'json',
            'caracterizaciones' => 'json',
            'relaciones' => 'json',
            'a13_raw' => 'json',
            'constancia_raw' => 'json',
            'resultado_normalizado' => 'json',
            'errores' => 'json',
            'consultado_at' => 'datetime',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }

    public function datosFacturacion()
    {
        return $this->belongsTo(DatoFacturacion::class);
    }
}
