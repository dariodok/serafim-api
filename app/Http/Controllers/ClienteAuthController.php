<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistroClienteRequest;
use App\Http\Requests\LoginClienteRequest;
use App\Models\Usuario;
use App\Services\CustomerNotificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class ClienteAuthController extends Controller
{
    public function __construct(private readonly CustomerNotificationService $notifications)
    {
    }

    public function registro(RegistroClienteRequest $request)
    {
        $usuario = Usuario::create([
            'nombre'   => $request->nombre,
            'apellido' => $request->apellido,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $usuario->createToken('cliente-token', ['role:cliente'])->plainTextToken;
        $this->notifications->sendWelcomeEmail($usuario, 'registro web');

        return response()->json([
            'mensaje' => 'Registro exitoso',
            'token'   => $token,
            'usuario' => $usuario
        ], 201);
    }

    public function login(LoginClienteRequest $request)
    {
        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            return response()->json(['mensaje' => 'Credenciales incorrectas'], 401);
        }

        if (!$usuario->activo) {
            return response()->json(['mensaje' => 'Cuenta inactiva'], 403);
        }

        $token = $usuario->createToken('cliente-token', ['role:cliente'])->plainTextToken;

        return response()->json([
            'mensaje' => 'Login exitoso',
            'token'   => $token,
            'usuario' => $usuario
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
