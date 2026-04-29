<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Administrador extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'administradores';

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'password',
        'rol',
        'activo',
        'ultimo_acceso_en',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_acceso_en' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }
}
