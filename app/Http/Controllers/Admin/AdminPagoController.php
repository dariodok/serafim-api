<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pago;
use App\Models\Venta;
use App\Services\CustomerNotificationService;
use App\Services\MercadoPagoCheckoutProService;
use App\Services\PaywayCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPagoController extends Controller
{
    private const PAYMENT_STATUSES = [
        'pendiente',
        'pagado',
        'cancelado',
        'reembolsado',
    ];

    public function __construct(
        private readonly CustomerNotificationService $notifications,
        private readonly MercadoPagoCheckoutProService $mercadoPago,
        private readonly PaywayCheckoutService $payway,
    )
    {
    }

    public function index()
    {
        $perPage = max(1, min((int) request('per_page', 25), 100));
        $search = trim((string) request('q', ''));

        $query = Pago::query()
            ->with(['venta.usuario'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('medio_pago', 'like', "%{$search}%")
                    ->orWhere('estado', 'like', "%{$search}%")
                    ->orWhere('referencia_externa', 'like', "%{$search}%")
                    ->orWhere('referencia_secundaria', 'like', "%{$search}%")
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

        $pagos = $query->paginate($perPage);

        return response()->json($pagos);
    }

    public function show(string $id)
    {
        return response()->json($this->loadPago($id));
    }

    public function altaManual(Request $request)
    {
        $data = $request->validate($this->rules());
        $checkoutProvider = $this->resolveCheckoutProvider($data['medio_pago'] ?? null);

        $pago = DB::transaction(function () use ($data) {
            $venta = Venta::findOrFail($data['venta_id']);
            $estado = $this->isCheckoutManagedMethod($data['medio_pago'] ?? null)
                ? 'pendiente'
                : (string) $data['estado'];

            $this->guardAgainstOverpayment($venta, (float) $data['monto'], $estado);

            $pago = $venta->pagos()->create([
                'medio_pago' => $data['medio_pago'],
                'estado' => $estado,
                'monto' => $data['monto'],
                'moneda' => $data['moneda'] ?? $venta->moneda,
                'es_manual' => true,
                'fecha_pago' => $this->isCheckoutManagedMethod($data['medio_pago'] ?? null)
                    ? null
                    : ($data['fecha_pago'] ?? now()),
                'referencia_externa' => $data['referencia_externa'] ?? null,
                'referencia_secundaria' => $data['referencia_secundaria'] ?? null,
                'comprobante_manual' => $data['comprobante_manual'] ?? null,
                'datos_externos' => $data['datos_externos'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            $venta->update([
                'medio_pago' => $data['medio_pago'],
                'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
                'estado_venta' => $data['estado_venta'] ?? $venta->estado_venta,
            ]);

            return $this->loadPago($pago->id);
        });

        if ($checkoutProvider !== null) {
            try {
                if ($checkoutProvider === 'mercadopago') {
                    $this->mercadoPago->createPreferenceForPago($pago);
                } else {
                    $this->payway->createCheckoutForPago($pago);
                }

                $pago = $this->loadPago($pago->id);
                $checkoutLink = $this->resolveCheckoutLink($pago);

                if ($checkoutLink) {
                    $this->notifications->sendCheckoutLinkGenerated($pago->venta, $checkoutLink);
                }
            } catch (\Throwable $exception) {
                $pago->delete();
                throw $exception;
            }
        }

        $this->notifications->sendPaymentRecorded($pago, 'manual');

        return response()->json($pago, 201);
    }

    public function update(Request $request, string $id)
    {
        $pago = Pago::findOrFail($id);
        $data = $request->validate($this->rules(true));

        $updatedPago = DB::transaction(function () use ($data, $pago) {
            $venta = $pago->venta;
            $nextAmount = isset($data['monto']) ? (float) $data['monto'] : (float) $pago->monto;
            $nextStatus = isset($data['estado']) ? (string) $data['estado'] : (string) $pago->estado;

            if ($venta) {
                $this->guardAgainstOverpayment($venta, $nextAmount, $nextStatus, $pago);
            }

            $pago->update([
                'medio_pago' => $data['medio_pago'] ?? $pago->medio_pago,
                'estado' => $data['estado'] ?? $pago->estado,
                'monto' => $data['monto'] ?? $pago->monto,
                'moneda' => $data['moneda'] ?? $pago->moneda,
                'fecha_pago' => $data['fecha_pago'] ?? $pago->fecha_pago,
                'referencia_externa' => $data['referencia_externa'] ?? $pago->referencia_externa,
                'referencia_secundaria' => $data['referencia_secundaria'] ?? $pago->referencia_secundaria,
                'comprobante_manual' => $data['comprobante_manual'] ?? $pago->comprobante_manual,
                'datos_externos' => $data['datos_externos'] ?? $pago->datos_externos,
                'observaciones' => $data['observaciones'] ?? $pago->observaciones,
            ]);

            if ($venta) {
                $venta->update([
                    'medio_pago' => $data['medio_pago'] ?? $venta->medio_pago,
                    'estado_pago' => $this->resolveSalePaymentStatus($venta->fresh('pagos')),
                    'estado_venta' => $data['estado_venta'] ?? $venta->estado_venta,
                ]);
            }

            return $this->loadPago($pago->id);
        });

        $this->notifications->sendPaymentRecorded($updatedPago, 'actualizacion manual');

        return response()->json($updatedPago);
    }

    public function generarCheckoutPro(string $id)
    {
        $pago = $this->loadPago($id);
        $provider = $this->resolveCheckoutProvider($pago->medio_pago);

        if ($provider === null) {
            throw ValidationException::withMessages([
                'pago' => 'Este medio de pago no soporta checkout automatico.',
            ]);
        }

        $data = $provider === 'mercadopago'
            ? $this->mercadoPago->createPreferenceForPago($pago)
            : $this->payway->createCheckoutForPago($pago);
        $pago = $this->loadPago($id);

        $checkoutLink = $this->resolveCheckoutLink($pago);

        if ($checkoutLink) {
            $this->notifications->sendCheckoutLinkGenerated($pago->venta, $checkoutLink);
        }

        return response()->json([
            'message' => 'Checkout generado para el pago.',
            'pago' => $pago,
            'checkout' => $data,
        ]);
    }

    public function sincronizarCheckoutPro(string $id)
    {
        $pago = $this->loadPago($id);

        $provider = $this->resolveCheckoutProvider($pago->medio_pago);

        if ($provider === null) {
            throw ValidationException::withMessages([
                'pago' => 'Este medio de pago no soporta verificacion automatica.',
            ]);
        }

        $pago = $provider === 'mercadopago'
            ? $this->mercadoPago->syncPago($pago)
            : $this->payway->syncPago($pago);

        return response()->json($pago);
    }

    private function loadPago(int|string $id): Pago
    {
        return Pago::with([
            'venta.usuario',
            'venta.pagos',
            'venta.detalles.productoVenta',
            'venta.envios.bultos',
            'venta.envios.eventos',
            'venta.comprobantes',
        ])->findOrFail($id);
    }

    private function resolveSalePaymentStatus(Venta $venta): string
    {
        $effectivePayments = $venta->pagos->filter(function (Pago $pago) {
            return in_array($pago->estado, ['pagado', 'parcial'], true);
        });

        $paidAmount = (float) $effectivePayments->sum(function (Pago $pago) {
            return (float) $pago->monto;
        });

        $total = (float) $venta->total;

        if ($paidAmount >= $total && $total > 0) {
            return 'pagado';
        }

        return 'pendiente';
    }

    private function guardAgainstOverpayment(Venta $venta, float $incomingAmount, string $incomingStatus, ?Pago $currentPago = null): void
    {
        if (!$this->isEffectivePaymentStatus($incomingStatus)) {
            return;
        }

        $alreadyPaid = (float) $venta->pagos
            ->reject(function (Pago $pago) use ($currentPago) {
                return $currentPago && $pago->id === $currentPago->id;
            })
            ->filter(function (Pago $pago) {
                return $this->isEffectivePaymentStatus((string) $pago->estado);
            })
            ->sum(function (Pago $pago) {
                return (float) $pago->monto;
            });

        if (($alreadyPaid + $incomingAmount) - (float) $venta->total > 0.009) {
            throw ValidationException::withMessages([
                'monto' => 'El pago supera el saldo pendiente de la venta.',
            ]);
        }
    }

    private function isEffectivePaymentStatus(string $status): bool
    {
        return in_array($status, ['pagado', 'parcial'], true);
    }

    private function isCheckoutManagedMethod(?string $method): bool
    {
        return in_array($method, ['Mercado Pago', 'Payway'], true);
    }

    private function resolveCheckoutProvider(?string $method): ?string
    {
        return match ($method) {
            'Mercado Pago' => 'mercadopago',
            'Payway' => 'payway',
            default => null,
        };
    }

    private function resolveCheckoutLink(Pago $pago): string
    {
        return (string) (
            data_get($pago->datos_externos, 'mercado_pago.sandbox_init_point')
            ?: data_get($pago->datos_externos, 'mercado_pago.init_point')
            ?: data_get($pago->datos_externos, 'payway.checkout_link')
            ?: data_get($pago->datos_externos, 'payway.raw.payment_link')
            ?: ''
        );
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'venta_id' => [$required, 'exists:ventas,id'],
            'medio_pago' => [$required, 'string', 'max:100'],
            'estado' => [$required, 'string', 'in:' . implode(',', self::PAYMENT_STATUSES)],
            'monto' => [$required, 'numeric', 'min:0'],
            'moneda' => ['nullable', 'string', 'max:10'],
            'fecha_pago' => ['nullable', 'date'],
            'referencia_externa' => ['nullable', 'string', 'max:255'],
            'referencia_secundaria' => ['nullable', 'string', 'max:255'],
            'comprobante_manual' => ['nullable', 'string', 'max:255'],
            'datos_externos' => ['nullable', 'array'],
            'observaciones' => ['nullable', 'string'],
            'estado_pago' => ['nullable', 'string', 'max:100'],
            'estado_venta' => ['nullable', 'string', 'max:100'],
        ];
    }
}
