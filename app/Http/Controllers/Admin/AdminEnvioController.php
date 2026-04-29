<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domicilio;
use App\Models\Envio;
use App\Models\Venta;
use App\Services\CustomerNotificationService;
use App\Services\ShippingOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminEnvioController extends Controller
{
    private const ESTADOS_ENVIO = [
        'pendiente',
        'generado',
        'despachado',
        'en_transito',
        'entregado',
        'cancelado',
    ];

    public function __construct(
        private readonly CustomerNotificationService $notifications,
        private readonly ShippingOperationService $shippingOperations,
    )
    {
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = Envio::with(['venta.usuario', 'domicilio', 'bultos', 'eventos'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('proveedor', 'like', "%{$search}%")
                    ->orWhere('servicio', 'like', "%{$search}%")
                    ->orWhere('estado', 'like', "%{$search}%")
                    ->orWhere('codigo_seguimiento', 'like', "%{$search}%")
                    ->orWhere('destinatario', 'like', "%{$search}%")
                    ->orWhere('localidad', 'like', "%{$search}%")
                    ->orWhere('provincia', 'like', "%{$search}%")
                    ->orWhereHas('venta', function ($ventaQuery) use ($search) {
                        $ventaQuery
                            ->where('numero_venta', 'like', "%{$search}%")
                            ->orWhereHas('usuario', function ($usuarioQuery) use ($search) {
                                $usuarioQuery
                                    ->where('nombre', 'like', "%{$search}%")
                                    ->orWhere('apellido', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $envios = $query->paginate($perPage);

        return response()->json($envios);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $venta = Venta::findOrFail($data['venta_id']);

        $envio = DB::transaction(function () use ($data, $venta) {
            $domicilio = $this->resolveDomicilio($venta, $data['domicilio_id'] ?? null);
            $payload = $this->preparePayload($data, $venta, $domicilio);

            $envio = Envio::create($payload);
            $this->shippingOperations->ensureSuggestedPackages($envio, $venta);
            $this->shippingOperations->registerStatusEvent(
                $envio,
                (string) $envio->estado,
                'admin',
                'Envio creado manualmente',
            );
            $this->syncVentaStatusFromEnvios($venta->fresh('envios'));

            return $envio->load(['venta.usuario', 'domicilio', 'bultos', 'eventos']);
        });

        $this->notifications->sendShippingUpdate($envio);

        return response()->json($envio, 201);
    }

    public function show(string $id)
    {
        return response()->json(
            Envio::with(['venta.usuario', 'domicilio', 'bultos', 'eventos'])
                ->findOrFail($id)
        );
    }

    public function update(Request $request, string $id)
    {
        $envio = Envio::findOrFail($id);
        $previousStatus = $envio->estado;
        $data = $request->validate($this->rules(true));

        $updatedEnvio = DB::transaction(function () use ($data, $envio, $previousStatus) {
            $venta = isset($data['venta_id'])
                ? Venta::findOrFail($data['venta_id'])
                : $envio->venta()->firstOrFail();

            $domicilio = array_key_exists('domicilio_id', $data)
                ? $this->resolveDomicilio($venta, $data['domicilio_id'])
                : ($envio->domicilio_id ? $this->resolveDomicilio($venta, $envio->domicilio_id) : null);

            $payload = $this->preparePayload($data, $venta, $domicilio, $envio);

            $envio->update($payload);
            $this->shippingOperations->ensureSuggestedPackages($envio->fresh(), $venta);

            if ($previousStatus !== $envio->estado) {
                $this->shippingOperations->registerStatusEvent(
                    $envio,
                    (string) $envio->estado,
                    'admin',
                    sprintf('Estado actualizado desde %s', $previousStatus),
                    ['previous_status' => $previousStatus]
                );
            }

            $this->syncVentaStatusFromEnvios($venta->fresh('envios'));

            return $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']);
        });

        $this->notifications->sendShippingUpdate($updatedEnvio, $previousStatus);

        return response()->json($updatedEnvio);
    }

    public function cancelar(Request $request, string $id)
    {
        $envio = Envio::findOrFail($id);
        $previousStatus = $envio->estado;

        $data = $request->validate([
            'motivo_estado' => 'nullable|string',
            'observaciones' => 'nullable|string',
        ]);

        DB::transaction(function () use ($envio, $data, $previousStatus) {
            $envio->update([
                'estado' => 'cancelado',
                'motivo_estado' => $data['motivo_estado'] ?? $envio->motivo_estado,
                'observaciones' => $data['observaciones'] ?? $envio->observaciones,
                'fecha_cancelacion' => now(),
            ]);

            $this->shippingOperations->registerStatusEvent(
                $envio,
                'cancelado',
                'admin',
                'Envio cancelado manualmente',
                ['previous_status' => $previousStatus]
            );

            $venta = $envio->venta;
            if ($venta) {
                $this->syncVentaStatusFromEnvios($venta->fresh('envios'));
            }
        });

        $cancelledEnvio = $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']);
        $this->notifications->sendShippingUpdate($cancelledEnvio, $previousStatus);

        return response()->json($cancelledEnvio);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'venta_id' => "{$required}|exists:ventas,id",
            'domicilio_id' => 'nullable|exists:domicilios,id',
            'proveedor' => "{$required}|string|max:255",
            'servicio' => 'nullable|string|max:255',
            'estado' => "{$required}|in:" . implode(',', self::ESTADOS_ENVIO),
            'motivo_estado' => 'nullable|string',
            'referencia_externa' => 'nullable|string|max:255',
            'codigo_seguimiento' => 'nullable|string|max:255',
            'codigo_bulto' => 'nullable|string|max:255',
            'url_etiqueta' => 'nullable|string|max:255',
            'archivo_etiqueta' => 'nullable|string|max:255',
            'costo_envio' => 'nullable|numeric|min:0',
            'moneda' => 'nullable|string|max:10',
            'peso_gramos' => 'nullable|integer|min:0',
            'alto_cm' => 'nullable|numeric|min:0',
            'ancho_cm' => 'nullable|numeric|min:0',
            'largo_cm' => 'nullable|numeric|min:0',
            'destinatario' => "{$required}|string|max:255",
            'telefono' => 'nullable|string|max:255',
            'provincia' => "{$required}|string|max:255",
            'localidad' => "{$required}|string|max:255",
            'codigo_postal' => "{$required}|string|max:255",
            'calle' => "{$required}|string|max:255",
            'numero' => "{$required}|string|max:255",
            'piso' => 'nullable|string|max:255',
            'departamento' => 'nullable|string|max:255',
            'referencia' => 'nullable|string',
            'fecha_generacion' => 'nullable|date',
            'fecha_cancelacion' => 'nullable|date',
            'fecha_despacho' => 'nullable|date',
            'fecha_entrega' => 'nullable|date',
            'respuesta_ultima_api' => 'nullable|array',
            'datos_adicionales' => 'nullable|array',
            'observaciones' => 'nullable|string',
        ];
    }

    private function resolveDomicilio(Venta $venta, mixed $domicilioId): ?Domicilio
    {
        if (empty($domicilioId)) {
            return null;
        }

        $domicilio = Domicilio::query()
            ->where('usuario_id', $venta->usuario_id)
            ->find($domicilioId);

        if (!$domicilio) {
            throw ValidationException::withMessages([
                'domicilio_id' => 'El domicilio seleccionado no pertenece al usuario de la venta.',
            ]);
        }

        return $domicilio;
    }

    private function preparePayload(array $data, Venta $venta, ?Domicilio $domicilio = null, ?Envio $envio = null): array
    {
        $payload = array_merge($envio?->toArray() ?? [], $data, [
            'venta_id' => $venta->id,
            'domicilio_id' => $domicilio?->id,
        ]);

        $estado = $payload['estado'] ?? $envio?->estado ?? 'pendiente';
        $payload['estado'] = $estado;

        if (!$envio && empty($payload['fecha_generacion'])) {
            $payload['fecha_generacion'] = now();
        }

        if (in_array($estado, ['despachado', 'en_transito', 'entregado'], true) && empty($payload['fecha_despacho'])) {
            $payload['fecha_despacho'] = now();
        }

        if ($estado === 'entregado' && empty($payload['fecha_entrega'])) {
            $payload['fecha_entrega'] = now();
        }

        if ($estado === 'cancelado' && empty($payload['fecha_cancelacion'])) {
            $payload['fecha_cancelacion'] = now();
        }

        if ($estado !== 'cancelado' && array_key_exists('fecha_cancelacion', $payload) && !$envio?->fecha_cancelacion) {
            $payload['fecha_cancelacion'] = null;
        }

        return $payload;
    }

    private function syncVentaStatusFromEnvios(Venta $venta): void
    {
        if ($venta->tipo_entrega !== 'domicilio') {
            return;
        }

        $statuses = $venta->envios->pluck('estado')->filter()->values();

        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->contains('entregado')) {
            $venta->update(['estado_venta' => 'entregada']);
            return;
        }

        if ($statuses->every(fn (string $status) => $status === 'cancelado')) {
            $venta->update(['estado_venta' => 'cancelada']);
            return;
        }

        if ($statuses->contains(fn (string $status) => in_array($status, ['despachado', 'en_transito'], true))) {
            $venta->update(['estado_venta' => 'lista_para_entrega']);
        }
    }
}
