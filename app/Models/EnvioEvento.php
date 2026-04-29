<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnvioEvento extends Model
{
    protected $table = 'envio_eventos';

    protected $fillable = [
        'envio_id',
        'estado',
        'origen',
        'descripcion',
        'payload',
        'ocurrio_en',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
            'ocurrio_en' => 'datetime',
        ];
    }

    public function envio()
    {
        return $this->belongsTo(Envio::class);
    }
}
