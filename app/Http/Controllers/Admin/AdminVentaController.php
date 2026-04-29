<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatoFacturacion;
use App\Models\Domicilio;
use App\Models\ProductoVenta;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\CustomerNotificationService;
use App\Services\MercadoPagoCheckoutProService;
use App\Services\ShippingOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminVentaController extends Controller
{
    private const ESTADOS_VENTA = [
        'pendiente',
        'confirmada',
        'en_preparacion',
        'lista_para_entrega',
        'entregada',
        'cancelada',
    ];

    private const ESTADOS_PAGO = [
        'pendiente',
        'pagado',
        'reembolsado',
        'cancelado',
    ];

    public function __construct(
        private readonly MercadoPagoCheckoutProService $mercadoPago,
        private readonly CustomerNotificationService $notifications,
        private readonly ShippingOperationService $shippingOperations,
    )
    {
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = Venta::query()
            ->with(['usuario', 'pagos', 'envios'])
            ->withCount(['detalles', 'pagos', 'envios'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('numero_venta', 'like', "%{$search}%")
                    ->orWhere('estado_venta', 'like', "%{$search}%")
                    ->orWhere('estado_pago', 'like', "%{$search}%")
                    ->orWhereHas('usuario', function ($usuarioQuery) use ($search) {
                        $usuarioQuery
                            ->where('nombre', 'like', "%{$search}%")
                            ->orWhere('apellido', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'datos_facturacion_id' => ['nullable', 'exists:datos_facturacion,id'],
            'tipo_entrega' => ['required', 'in:domicilio,retiro'],
            'domicilio_id' => ['nullable', 'exists:domicilios,id'],
            'medio_pago' => ['nullable', 'string', 'max:100'],
            'moneda' => ['nullable', 'string', 'max:10'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'costo_envio' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_venta_id' => ['required', 'exists:productos_venta,id'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
        ]);

        $venta = DB::transaction(function () use ($data) {
            $usuario = Usuario::with([
                'telefonos' => fn ($query) => $query->where('activo', true)->orderByDesc('principal'),
                'domicilios' => fn ($query) => $query->where('activo', true)->orderByDesc('principal'),
                'datosFacturacion' => fn ($query) => $query->where('activo', true)->orderByDesc('principal'),
            ])->findOrFail($data['usuario_id']);

            $datoFacturacion = null;
            if (!empty($data['datos_facturacion_id'])) {
                $datoFacturacion = DatoFacturacion::query()
                    ->where('usuario_id', $usuario->id)
                    ->findOrFail($data['datos_facturacion_id']);
            }

            $domicilio = null;
            if ($data['tipo_entrega'] === 'domicilio') {
                if (empty($data['domicilio_id'])) {
                    throw ValidationException::withMessages([
                        'domicilio_id' => 'Selecciona un domicilio para la entrega.',
                    ]);
                }

                $domicilio = Domicilio::query()
                    ->where('usuario_id', $usuario->id)
                    ->findOrFail($data['domicilio_id']);
            }

            $productIds = collect($data['detalles'])
                ->pluck('producto_venta_id')
                ->unique()
                ->values();

            $productos = ProductoVenta::query()
                ->whereIn('id', $productIds)
                ->where('activo', true)
                ->get()
                ->keyBy('id');

            if ($productos->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'detalles' => 'Uno o mas productos ya no estan disponibles para vender.',
                ]);
            }

            $detalles = collect($data['detalles'])->map(function (array $detalle) use ($productos) {
                $producto = $productos->get($detalle['producto_venta_id']);
                $cantidad = (int) $detalle['cantidad'];
                $precioUnitario = (float) $producto->precio;
                $subtotal = round($precioUnitario * $cantidad, 2);

                return [
                    'producto_venta_id' => $producto->id,
                    'sku_producto' => $producto->sku,
                    'nombre_producto' => $producto->nombre,
                    'precio_unitario' => $precioUnitario,
                    'cantidad' => $cantidad,
                    'subtotal' => $subtotal,
                    'peso_gramos' => (int) ($producto->peso_gramos ?? 0) * $cantidad,
                ];
            });

            $subtotal = round($detalles->sum('subtotal'), 2);
            $descuento = round((float) ($data['descuento'] ?? 0), 2);
            $costoEnvio = round((float) ($data['costo_envio'] ?? 0), 2);
            $moneda = $data['moneda'] ?? 'ARS';
            $total = round($subtotal - $descuento + ($data['tipo_entrega'] === 'domicilio' ? $costoEnvio : 0), 2);

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'descuento' => 'El descuento no puede dejar el total en negativo.',
                ]);
            }

            $venta = Venta::create([
                'numero_venta' => $this->generateSaleNumber(),
                'usuario_id' => $usuario->id,
                'datos_facturacion_id' => $datoFacturacion?->id,
                'tipo_entrega' => $data['tipo_entrega'],
                'estado_venta' => 'pendiente',
                'estado_pago' => 'pendiente',
                'medio_pago' => $data['medio_pago'] ?? null,
                'moneda' => $moneda,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'costo_envio' => $data['tipo_entrega'] === 'domicilio' ? $costoEnvio : 0,
                'total' => $total,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            foreach ($detalles as $detalle) {
                VentaDetalle::create([
                    'venta_id' => $venta->id,
                    'producto_venta_id' => $detalle['producto_venta_id'],
                    'sku_producto' => $detalle['sku_producto'],
                    'nombre_producto' => $detalle['nombre_producto'],
                    'precio_unitario' => $detalle['precio_unitario'],
                    'cantidad' => $detalle['cantidad'],
                    'subtotal' => $detalle['subtotal'],
                ]);
            }

            if ($domicilio) {
                $telefono = $usuario->telefonos->first()?->numero;

                $envio = $venta->envios()->create([
                    'domicilio_id' => $domicilio->id,
                    'proveedor' => 'manual',
                    'servicio' => null,
                    'estado' => 'pendiente',
                    'costo_envio' => $costoEnvio,
                    'moneda' => $moneda,
                    'peso_gramos' => $detalles->sum('peso_gramos'),
                    'destinatario' => $domicilio->destinatario,
                    'telefono' => $domicilio->telefono_contacto ?: $telefono,
                    'provincia' => $domicilio->provincia,
                    'localidad' => $domicilio->localidad,
                    'codigo_postal' => $domicilio->codigo_postal,
                    'calle' => $domicilio->calle,
                    'numero' => $domicilio->numero,
                    'piso' => $domicilio->piso,
                    'departamento' => $domicilio->departamento,
                    'referencia' => $domicilio->referencia,
                    'observaciones' => 'Envio generado desde alta administrativa de compra.',
                ]);

                $this->shippingOperations->ensureSuggestedPackages($envio, $venta->fresh('detalles.productoVenta'));
                $this->shippingOperations->registerStatusEvent(
                    $envio,
                    (string) $envio->estado,
                    'sistema',
                    'Envio inicial generado al registrar la compra',
                );
            }

            return $this->loadVenta($venta->id);
        });

        $this->notifications->sendSaleCreated($venta);

        return response()->json($venta, 201);
    }

    public function show(string $id)
    {
        return response()->json($this->loadVenta($id));
    }

    public function actualizarEstado(Request $request, string $id)
    {
        $venta = Venta::findOrFail($id);

        $data = $request->validate([
            'estado_venta' => ['sometimes', 'string', 'in:' . implode(',', self::ESTADOS_VENTA)],
            'estado_pago' => ['sometimes', 'string', 'in:' . implode(',', self::ESTADOS_PAGO)],
            'observaciones' => 'nullable|string',
        ]);

        $venta->update($data);

        return response()->json($this->loadVenta($venta->id));
    }

    public function generarCheckoutPro(string $id)
    {
        $venta = Venta::with(['usuario', 'detalles', 'pagos'])->findOrFail($id);

        $preference = $this->mercadoPago->createPreferenceForVenta($venta);
        $loadedVenta = $this->loadVenta($venta->id);
        $checkoutLink = $preference['sandbox_init_point'] ?? $preference['init_point'] ?? '';

        if ($checkoutLink !== '') {
            $this->notifications->sendCheckoutLinkGenerated($loadedVenta, $checkoutLink);
        }

        return response()->json([
            'preference_id' => $preference['id'] ?? null,
            'init_point' => $preference['init_point'] ?? null,
            'sandbox_init_point' => $preference['sandbox_init_point'] ?? null,
            'venta' => $loadedVenta,
        ]);
    }

    public function sincronizarCheckoutPro(string $id)
    {
        $venta = Venta::findOrFail($id);

        return response()->json($this->mercadoPago->syncVentaPayments($venta));
    }

    private function generateSaleNumber(): string
    {
        do {
            $numero = 'VTA-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        } while (Venta::query()->where('numero_venta', $numero)->exists());

        return $numero;
    }

    private function loadVenta(string|int $id): Venta
    {
        return Venta::with([
            'usuario',
            'datosFacturacion',
            'detalles.productoVenta.imagenes',
            'pagos',
            'envios.bultos',
            'envios.eventos',
            'comprobantes',
        ])->findOrFail($id);
    }
}
