<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Administrador;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Administrador::updateOrCreate(
            ['email' => 'admin@serafim.com'],
            [
                'nombre' => 'Super',
                'apellido' => 'Admin',
                'password' => Hash::make('password'),
                'rol' => 'superadmin',
                'activo' => true
            ]
        );
    }
}
