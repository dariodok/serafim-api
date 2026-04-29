<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAdministradorController extends Controller
{
    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));

        return response()->json(
            Administrador::orderBy('apellido')
                ->orderBy('nombre')
                ->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $admin = Administrador::create($request->validate([
            'nombre' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:administradores,email',
            'password' => 'required|string|min:8',
            'rol' => 'required|string|max:100',
            'activo' => 'boolean',
        ]));

        return response()->json($admin, 201);
    }

    public function show(string $id)
    {
        return response()->json(Administrador::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $admin = Administrador::findOrFail($id);

        $admin->update($request->validate([
            'nombre' => 'sometimes|string|max:255',
            'apellido' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('administradores', 'email')->ignore($admin->id)],
            'password' => 'sometimes|string|min:8',
            'rol' => 'sometimes|string|max:100',
            'activo' => 'sometimes|boolean',
        ]));

        return response()->json($admin);
    }

    public function destroy(Request $request, string $id)
    {
        $admin = Administrador::findOrFail($id);

        if ($request->user()?->id === $admin->id) {
            return response()->json(['mensaje' => 'No podés desactivar tu propia cuenta desde esta sesión'], 422);
        }

        $admin->update(['activo' => false]);
        $admin->tokens()->delete();

        return response()->json(['mensaje' => 'Administrador desactivado exitosamente']);
    }
}
