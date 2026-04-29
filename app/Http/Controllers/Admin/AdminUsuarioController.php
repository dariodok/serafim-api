<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\CustomerNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUsuarioController extends Controller
{
    public function __construct(private readonly CustomerNotificationService $notifications)
    {
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = Usuario::query()
            ->with([
                'telefonos' => fn ($query) => $query->where('activo', true)->orderByDesc('principal'),
                'domicilios' => fn ($query) => $query->where('activo', true)->orderByDesc('principal'),
            ])
            ->withCount(['ventas', 'telefonos', 'domicilios', 'datosFacturacion'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('nombre', 'like', "%{$search}%")
                    ->orWhere('apellido', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $usuarios = $query->paginate($perPage);

        $usuarios->getCollection()->transform(function (Usuario $usuario) {
            $telefonoPrincipal = $usuario->telefonos->first();
            $domicilioPrincipal = $usuario->domicilios->first();

            $usuario->telefono_principal = $telefonoPrincipal?->numero;
            $usuario->domicilio_principal = $domicilioPrincipal
                ? trim("{$domicilioPrincipal->calle} {$domicilioPrincipal->numero}")
                : null;

            return $usuario;
        });

        return response()->json($usuarios);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $usuario = Usuario::create([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'password' => $data['password'],
            'activo' => $data['activo'] ?? true,
        ]);

        $createdUsuario = $this->loadUsuario($usuario->id);
        $this->notifications->sendWelcomeEmail($createdUsuario, 'alta administrativa', $data['password']);

        return response()->json($createdUsuario, 201);
    }

    public function show(string $id)
    {
        return response()->json($this->loadUsuario($id));
    }

    public function update(Request $request, string $id)
    {
        $usuario = Usuario::findOrFail($id);
        $data = $request->validate($this->rules($usuario));

        $payload = [
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'activo' => $data['activo'] ?? $usuario->activo,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $usuario->update($payload);

        return response()->json($this->loadUsuario($usuario->id));
    }

    public function destroy(string $id)
    {
        $usuario = Usuario::findOrFail($id);
        $usuario->update(['activo' => false]);
        $usuario->tokens()->delete();

        return response()->json(['mensaje' => 'Usuario desactivado exitosamente']);
    }

    private function loadUsuario(int|string $id): Usuario
    {
        return Usuario::with([
            'telefonos' => fn ($query) => $query->orderByDesc('principal')->orderByDesc('id'),
            'domicilios' => fn ($query) => $query->orderByDesc('principal')->orderByDesc('id'),
            'datosFacturacion' => fn ($query) => $query->orderByDesc('principal')->orderByDesc('id'),
            'ventas.detalles.productoVenta',
            'ventas.pagos',
            'ventas.envios.bultos',
            'ventas.envios.eventos',
        ])
            ->withCount(['ventas', 'telefonos', 'domicilios', 'datosFacturacion'])
            ->findOrFail($id);
    }

    private function rules(?Usuario $usuario = null): array
    {
        $isUpdate = $usuario !== null;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('usuarios', 'email')->ignore($usuario?->id),
            ],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:8'],
            'activo' => ['boolean'],
        ];
    }
}
