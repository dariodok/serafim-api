<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\Pago;
use App\Models\Usuario;
use App\Models\Venta;
use Throwable;

class CustomerNotificationService
{
    public function __construct(private readonly TransactionalEmailService $mail)
    {
    }

    public function sendWelcomeEmail(Usuario $usuario, string $origin, ?string $temporaryPassword = null): void
    {
        $this->safely(function () use ($usuario, $origin, $temporaryPassword) {
            if (!$usuario->email) {
                return;
            }

            $contextRows = [
                'Cliente' => trim(sprintf('%s %s', $usuario->nombre, $usuario->apellido)),
                'Correo' => $usuario->email,
                'Origen' => $origin,
            ];

            if ($temporaryPassword) {
                $contextRows['Acceso inicial'] = $temporaryPassword;
            }

            $this->mail->sendMessage(
                to: $usuario->email,
                subject: 'Bienvenido a Serafim',
                heading: 'Tu cuenta ya esta disponible',
                intro: 'Ya puedes operar con tu cuenta en Serafim. Conserva este mensaje como referencia de acceso inicial.',
                contextRows: $contextRows,
                outro: 'Si no reconoces esta alta, responde este correo para revisarlo.',
            );
        });
    }

    public function sendSaleCreated(Venta $venta): void
    {
        $this->safely(function () use ($venta) {
            $venta->loadMissing(['usuario', 'detalles', 'datosFacturacion']);

            if (!$venta->usuario?->email) {
                return;
            }

            $this->mail->sendMessage(
                to: $venta->usuario->email,
                subject: sprintf('Compra registrada %s', $venta->numero_venta),
                heading: 'Registramos tu compra',
                intro: 'Tu compra ya fue registrada y quedo disponible para seguimiento.',
                contextRows: [
                    'Numero de venta' => $venta->numero_venta,
                    'Productos' => $this->buildItemSummary($venta),
                    'Entrega' => $venta->tipo_entrega === 'domicilio' ? 'Envio a domicilio' : 'Retiro / entrega manual',
                    'Total' => $this->formatMoney((float) $venta->total, (string) $venta->moneda),
                ],
                outro: 'Te iremos notificando los siguientes cambios de pago y logistica.',
            );
        });
    }

    public function sendCheckoutLinkGenerated(Venta $venta, string $checkoutLink): void
    {
        $this->safely(function () use ($venta, $checkoutLink) {
            $venta->loadMissing('usuario');

            if (!$venta->usuario?->email || trim($checkoutLink) === '') {
                return;
            }

            $this->mail->sendMessage(
                to: $venta->usuario->email,
                subject: sprintf('Link de pago disponible para %s', $venta->numero_venta),
                heading: 'Tu link de pago ya esta listo',
                intro: 'Ya puedes abonar tu compra usando el siguiente enlace de pago.',
                contextRows: [
                    'Numero de venta' => $venta->numero_venta,
                    'Total a cobrar' => $this->formatMoney((float) $venta->total, (string) $venta->moneda),
                    'Link de pago' => $checkoutLink,
                ],
                outro: 'Si el link no abre correctamente, responde este mensaje para que lo reenviemos.',
            );
        });
    }

    public function sendPaymentRecorded(Pago $pago, string $origin = 'manual'): void
    {
        $this->safely(function () use ($pago, $origin) {
            $pago->loadMissing('venta.usuario');

            if (!$pago->venta?->usuario?->email) {
                return;
            }

            $this->mail->sendMessage(
                to: $pago->venta->usuario->email,
                subject: sprintf('Actualizacion de pago %s', $pago->venta->numero_venta),
                heading: 'Registramos un movimiento en tu pago',
                intro: 'Actualizamos el estado de un pago asociado a tu compra.',
                contextRows: [
                    'Numero de venta' => $pago->venta->numero_venta,
                    'Medio de pago' => $pago->medio_pago ?: 'Sin definir',
                    'Estado' => $this->humanizeStatus((string) $pago->estado),
                    'Monto' => $this->formatMoney((float) $pago->monto, (string) $pago->moneda),
                    'Fecha' => optional($pago->fecha_pago ?: $pago->created_at)?->format('d/m/Y H:i'),
                    'Origen' => $origin,
                ],
                outro: 'Si este movimiento no coincide con tu pago, responde este correo para revisarlo.',
            );
        });
    }

    public function sendShippingUpdate(Envio $envio, ?string $previousStatus = null): void
    {
        $this->safely(function () use ($envio, $previousStatus) {
            $envio->loadMissing('venta.usuario');

            if (!$envio->venta?->usuario?->email) {
                return;
            }

            $contextRows = [
                'Numero de venta' => $envio->venta->numero_venta,
                'Estado' => $this->humanizeStatus((string) $envio->estado),
                'Proveedor' => $envio->proveedor ?: 'Sin definir',
                'Servicio' => $envio->servicio ?: 'Sin definir',
                'Tracking' => $envio->codigo_seguimiento ?: 'Sin codigo',
                'Destino' => $this->buildAddress($envio),
            ];

            if ($previousStatus && $previousStatus !== $envio->estado) {
                $contextRows['Estado anterior'] = $this->humanizeStatus($previousStatus);
            }

            $this->mail->sendMessage(
                to: $envio->venta->usuario->email,
                subject: sprintf('Actualizacion logistica %s', $envio->venta->numero_venta),
                heading: 'Tu envio tuvo una actualizacion',
                intro: 'Modificamos la informacion logistica asociada a tu compra.',
                contextRows: $contextRows,
                outro: 'Te seguiremos notificando cualquier cambio importante en el despacho.',
            );
        });
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function buildItemSummary(Venta $venta): string
    {
        return $venta->detalles
            ->map(fn ($detalle) => sprintf('%s x%d', $detalle->nombre_producto, (int) $detalle->cantidad))
            ->implode(', ');
    }

    private function buildAddress(Envio $envio): string
    {
        return collect([
            trim(sprintf('%s %s', $envio->calle, $envio->numero)),
            $envio->localidad,
            $envio->provincia,
            $envio->codigo_postal,
        ])->filter()->implode(', ');
    }

    private function humanizeStatus(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return sprintf('%s %s', $currency ?: 'ARS', number_format($amount, 2, '.', ''));
    }
}
