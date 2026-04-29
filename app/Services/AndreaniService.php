<?php

namespace App\Services;

use App\Models\Envio;
use Illuminate\Validation\ValidationException;

class AndreaniService
{
    public function configurationSummary(): array
    {
        return [
            'enabled' => $this->isConfigured(),
            'base_url' => config('services.andreani.base_url'),
            'client_id' => config('services.andreani.client_id'),
            'contract' => config('services.andreani.contract'),
            'origin_postal_code' => config('services.andreani.origin_postal_code'),
            'request_timeout_seconds' => config('services.andreani.timeout', 20),
            'status' => $this->isConfigured()
                ? 'Configurado de forma preliminar. Falta mapear endpoints reales de Andreani.'
                : 'Pendiente de credenciales y documentacion tecnica privada de Andreani.',
        ];
    }

    public function isConfigured(): bool
    {
        return (string) config('services.andreani.base_url') !== ''
            && (string) config('services.andreani.client_id') !== ''
            && (string) config('services.andreani.client_secret') !== '';
    }

    public function buildSuggestedPayloadsForEnvio(Envio $envio): array
    {
        $envio->loadMissing(['venta.usuario', 'bultos']);

        $summary = $envio->datos_adicionales['packaging_summary'] ?? [];
        $primaryWeight = (int) ($envio->peso_gramos ?: $summary['total_weight_grams'] ?? 0);
        $primaryPackage = $envio->bultos->sortBy('numero_bulto')->first();

        $rates = [
            'contract' => (string) config('services.andreani.contract'),
            'originPostalCode' => (string) config('services.andreani.origin_postal_code'),
            'destinationPostalCode' => (string) ($envio->codigo_postal ?? ''),
            'serviceType' => (string) config('services.andreani.default_service_type', 'standard'),
            'package' => [
                'weightInGrams' => $primaryWeight,
                'heightInCm' => (float) ($primaryPackage?->alto_cm ?? $envio->alto_cm ?? 0),
                'widthInCm' => (float) ($primaryPackage?->ancho_cm ?? $envio->ancho_cm ?? 0),
                'lengthInCm' => (float) ($primaryPackage?->largo_cm ?? $envio->largo_cm ?? 0),
            ],
        ];

        $shipping = [
            'order' => [
                'externalId' => (string) ($envio->venta?->numero_venta ?: "ENV-{$envio->id}"),
                'serviceType' => (string) config('services.andreani.default_service_type', 'standard'),
                'declaredValue' => (float) ($primaryPackage?->valor_declarado ?? $envio->venta?->total ?? 0),
            ],
            'sender' => [
                'postalCode' => (string) config('services.andreani.origin_postal_code'),
            ],
            'recipient' => [
                'name' => (string) ($envio->destinatario ?? ''),
                'email' => (string) ($envio->venta?->usuario?->email ?? ''),
                'phone' => (string) ($envio->telefono ?? ''),
                'address' => [
                    'postalCode' => (string) ($envio->codigo_postal ?? ''),
                    'province' => (string) ($envio->provincia ?? ''),
                    'city' => (string) ($envio->localidad ?? ''),
                    'street' => (string) ($envio->calle ?? ''),
                    'number' => (string) ($envio->numero ?? ''),
                    'floor' => (string) ($envio->piso ?? ''),
                    'apartment' => (string) ($envio->departamento ?? ''),
                    'reference' => (string) ($envio->referencia ?? ''),
                ],
            ],
            'package' => [
                'weightInGrams' => $primaryWeight,
                'heightInCm' => (float) ($primaryPackage?->alto_cm ?? $envio->alto_cm ?? 0),
                'widthInCm' => (float) ($primaryPackage?->ancho_cm ?? $envio->ancho_cm ?? 0),
                'lengthInCm' => (float) ($primaryPackage?->largo_cm ?? $envio->largo_cm ?? 0),
            ],
        ];

        $tracking = [
            'trackingNumber' => $envio->codigo_seguimiento ?: $envio->referencia_externa ?: $envio->codigo_bulto,
        ];

        return [
            'rates' => $rates,
            'shipping_import' => $shipping,
            'tracking' => $tracking,
        ];
    }

    public function quote(array $payload): array
    {
        $this->throwPendingContractException('cotizar', $payload);
    }

    public function importShipping(array $payload): array
    {
        $this->throwPendingContractException('registrar', $payload);
    }

    public function track(array $params): array
    {
        $this->throwPendingContractException('trackear', $params);
    }

    private function throwPendingContractException(string $action, array $payload): never
    {
        throw ValidationException::withMessages([
            'andreani' => sprintf(
                'La integracion de Andreani todavia no tiene endpoints confirmados para %s. Se dejo armado el contrato interno y los payloads sugeridos. Payload actual: %s',
                $action,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ]);
    }
}
