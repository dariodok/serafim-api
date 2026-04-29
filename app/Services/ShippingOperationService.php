<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\Venta;

class ShippingOperationService
{
    public function ensureSuggestedPackages(Envio $envio, ?Venta $venta = null): void
    {
        $venta ??= $envio->venta()->with('detalles.productoVenta')->first();

        if (!$venta) {
            return;
        }

        if ($envio->bultos()->exists()) {
            $this->storePackagingSummary($envio, $this->buildPackagingSummary($venta));
            return;
        }

        $summary = $this->buildPackagingSummary($venta);
        $packageCount = max(1, (int) $summary['suggested_packages']);
        $weights = $this->splitInteger((int) $summary['total_weight_grams'], $packageCount);
        $declaredValues = $this->splitAmount((float) $venta->total, $packageCount);

        for ($index = 0; $index < $packageCount; $index++) {
            $envio->bultos()->create([
                'numero_bulto' => $index + 1,
                'estado' => $envio->estado,
                'valor_declarado' => $declaredValues[$index],
                'peso_gramos' => $weights[$index],
                'alto_cm' => $packageCount === 1 ? $envio->alto_cm : null,
                'ancho_cm' => $packageCount === 1 ? $envio->ancho_cm : null,
                'largo_cm' => $packageCount === 1 ? $envio->largo_cm : null,
                'datos_adicionales' => [
                    'origen' => 'estimado',
                    'packaging_summary' => $summary,
                ],
            ]);
        }

        $this->storePackagingSummary($envio, $summary);
    }

    public function registerStatusEvent(
        Envio $envio,
        string $status,
        string $origin,
        ?string $description = null,
        array $payload = [],
    ): void {
        $envio->eventos()->create([
            'estado' => $status,
            'origen' => $origin,
            'descripcion' => $description,
            'payload' => $payload ?: null,
            'ocurrio_en' => now(),
        ]);
    }

    public function buildPackagingSummary(Venta $venta): array
    {
        $venta->loadMissing('detalles.productoVenta');

        $maxWeight = max(1, (int) config('logistics.package_max_weight_grams', 20000));
        $maxVolume = max(1, (int) config('logistics.package_max_volume_cm3', 250000));

        $totalWeight = 0;
        $totalVolume = 0;

        foreach ($venta->detalles as $detalle) {
            $producto = $detalle->productoVenta;
            $quantity = max(1, (int) $detalle->cantidad);

            $weight = (int) ($producto?->peso_gramos ?? 0);
            $height = (float) ($producto?->alto_cm ?? 0);
            $width = (float) ($producto?->ancho_cm ?? 0);
            $length = (float) ($producto?->largo_cm ?? 0);

            $totalWeight += $weight * $quantity;

            if ($height > 0 && $width > 0 && $length > 0) {
                $totalVolume += ($height * $width * $length) * $quantity;
            }
        }

        $byWeight = max(1, (int) ceil(max($totalWeight, 1) / $maxWeight));
        $byVolume = $totalVolume > 0 ? max(1, (int) ceil($totalVolume / $maxVolume)) : 1;
        $suggestedPackages = max($byWeight, $byVolume);

        return [
            'total_weight_grams' => $totalWeight,
            'total_volume_cm3' => (int) round($totalVolume),
            'package_max_weight_grams' => $maxWeight,
            'package_max_volume_cm3' => $maxVolume,
            'weight_based_packages' => $byWeight,
            'volume_based_packages' => $byVolume,
            'suggested_packages' => $suggestedPackages,
            'packing_basis' => $suggestedPackages > 1 ? 'heuristica_peso_volumen' : 'unico_bulto',
        ];
    }

    private function storePackagingSummary(Envio $envio, array $summary): void
    {
        $additional = $envio->datos_adicionales ?? [];
        $additional['packaging_summary'] = $summary;

        $envio->forceFill(['datos_adicionales' => $additional])->save();
    }

    private function splitInteger(int $total, int $parts): array
    {
        $parts = max(1, $parts);
        $base = intdiv(max($total, 0), $parts);
        $remainder = max($total, 0) % $parts;
        $result = [];

        for ($index = 0; $index < $parts; $index++) {
            $result[] = $base + ($index < $remainder ? 1 : 0);
        }

        return $result;
    }

    private function splitAmount(float $total, int $parts): array
    {
        $parts = max(1, $parts);
        $base = round($total / $parts, 2);
        $result = [];
        $accumulated = 0.0;

        for ($index = 0; $index < $parts; $index++) {
            if ($index === $parts - 1) {
                $amount = round($total - $accumulated, 2);
            } else {
                $amount = $base;
                $accumulated += $amount;
            }

            $result[] = $amount;
        }

        return $result;
    }
}
