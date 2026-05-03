<?php

namespace App\Observers;

use App\Models\Pago;
use App\Services\Afip\AfipElectronicBillingService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PagoObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Pago $pago): void
    {
        if (! $pago->wasRecentlyCreated && ! $pago->wasChanged(['estado', 'monto'])) {
            return;
        }

        if (! in_array((string) $pago->estado, ['pagado', 'parcial'], true)) {
            return;
        }

        $venta = $pago->venta()->with(['pagos', 'datosFacturacion', 'comprobantes'])->first();

        if (! $venta) {
            return;
        }

        app(AfipElectronicBillingService::class)->emitInvoiceForPaidVenta($venta);
    }
}
