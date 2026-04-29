<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginAdminRequest;
use App\Models\Administrador;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function login(LoginAdminRequest $request)
    {
        $admin = Administrador::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json(['mensaje' => 'Credenciales incorrectas'], 401);
        }

        if (!$admin->activo) {
            return response()->json(['mensaje' => 'Cuenta inactiva'], 403);
        }

        $admin->update(['ultimo_acceso_en' => now()]);

        $token = $admin->createToken('admin-token', ['role:admin', "rol:{$admin->rol}"])->plainTextToken;

        return response()->json([
            'mensaje' => 'Login exitoso',
            'token'   => $token,
            'admin'   => $admin
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['mensaje' => 'Sesión cerrada exitosamente']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
