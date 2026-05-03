<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'password',
        'activo',
        'email_verificado_en',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verificado_en' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function domicilios()
    {
        return $this->hasMany(Domicilio::class);
    }

    public function telefonos()
    {
        return $this->hasMany(Telefono::class);
    }

    public function datosFacturacion()
    {
        return $this->hasMany(DatoFacturacion::class);
    }

    public function afipConsultasFiscales()
    {
        return $this->hasMany(AfipConsultaFiscal::class);
    }

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }
}
