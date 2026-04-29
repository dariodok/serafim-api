<?php

namespace App\Services;

use App\Models\Envio;
use App\Models\EnvioBulto;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CorreoArgentinoService
{
    private const TOKEN_CACHE_KEY = 'correo_argentino.api_token';
    private const CUSTOMER_ID_CACHE_KEY = 'correo_argentino.customer_id';

    private const PROVINCE_CODES = [
        'buenos aires' => 'B',
        'caba' => 'C',
        'capital federal' => 'C',
        'ciudad autonoma de buenos aires' => 'C',
        'catamarca' => 'K',
        'chaco' => 'H',
        'chubut' => 'U',
        'cordoba' => 'X',
        'corrientes' => 'W',
        'entre rios' => 'E',
        'formosa' => 'P',
        'jujuy' => 'Y',
        'la pampa' => 'L',
        'la rioja' => 'F',
        'mendoza' => 'M',
        'misiones' => 'N',
        'neuquen' => 'Q',
        'rio negro' => 'R',
        'salta' => 'A',
        'san juan' => 'J',
        'san luis' => 'D',
        'santa cruz' => 'Z',
        'santa fe' => 'S',
        'santiago del estero' => 'G',
        'tierra del fuego' => 'V',
        'tucuman' => 'T',
    ];

    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function configurationSummary(): array
    {
        return [
            'enabled' => $this->isConfigured(),
            'base_url' => $this->baseUrl(),
            'username' => config('services.correo_argentino.username'),
            'configured_customer_id' => $this->configuredCustomerId(),
            'origin_postal_code' => config('services.correo_argentino.origin_postal_code'),
            'default_product_type' => config('services.correo_argentino.default_product_type', 'CP'),
            'sender' => [
                'name' => config('services.correo_argentino.sender.name'),
                'email' => config('services.correo_argentino.sender.email'),
                'phone' => config('services.correo_argentino.sender.phone'),
                'cell_phone' => config('services.correo_argentino.sender.cell_phone'),
                'origin_street_name' => config('services.correo_argentino.sender.origin.street_name'),
                'origin_street_number' => config('services.correo_argentino.sender.origin.street_number'),
                'origin_city' => config('services.correo_argentino.sender.origin.city'),
                'origin_province' => config('services.correo_argentino.sender.origin.province'),
                'origin_postal_code' => config('services.correo_argentino.sender.origin.postal_code'),
            ],
            'request_timeout_seconds' => config('services.correo_argentino.timeout', 20),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->configuredUsername() !== '' && $this->configuredPassword() !== '';
    }

    public function validateUser(?string $email = null, ?string $password = null): array
    {
        $payload = [
            'email' => trim((string) ($email ?: $this->configuredUsername())),
            'password' => trim((string) ($password ?: $this->configuredPassword())),
        ];

        if ($payload['email'] === '' || $payload['password'] === '') {
            throw ValidationException::withMessages([
                'correo_argentino' => 'Faltan email o password para validar el usuario de MiCorreo.',
            ]);
        }

        return $this->request()
            ->post('/users/validate', $payload)
            ->throw()
            ->json();
    }

    public function agencies(array $params): array
    {
        $query = [
            'customerId' => (string) ($params['customerId'] ?? $this->resolveCustomerId()),
            'provinceCode' => $this->resolveProvinceCode($params['provinceCode'] ?? $params['province'] ?? null),
        ];

        if (array_key_exists('services', $params) && $params['services'] !== null && $params['services'] !== '') {
            $query['services'] = is_array($params['services'])
                ? implode(',', array_filter(array_map('trim', $params['services'])))
                : trim((string) $params['services']);
        }

        return $this->request()
            ->get('/agencies', array_filter($query, fn ($value) => $value !== null && $value !== ''))
            ->throw()
            ->json();
    }

    public function buildSuggestedPayloadsForEnvio(Envio $envio): array
    {
        $envio->loadMissing(['venta.usuario', 'bultos']);

        $customerId = $this->resolveCustomerId();
        $primaryPackage = $this->primaryPackage($envio);
        $dimensions = $this->buildDimensionsPayload($envio, $primaryPackage);
        $destinationProvinceCode = $this->resolveProvinceCode($envio->provincia);
        $externalOrderId = (string) ($envio->venta?->numero_venta ?: "ENV-{$envio->id}");

        $rates = [
            'customerId' => $customerId,
            'postalCodeOrigin' => $this->senderOriginPostalCode(),
            'postalCodeDestination' => (string) ($envio->codigo_postal ?? ''),
            'deliveredType' => 'D',
            'dimensions' => $dimensions,
        ];

        $shippingImport = [
            'customerId' => $customerId,
            'extOrderId' => $externalOrderId,
            'orderNumber' => $externalOrderId,
            'sender' => $this->buildSenderPayload(),
            'recipient' => [
                'name' => (string) ($envio->destinatario ?? ''),
                'phone' => $this->nullableString($envio->telefono),
                'cellPhone' => $this->nullableString($envio->telefono),
                'email' => $this->nullableString($envio->venta?->usuario?->email),
            ],
            'shipping' => [
                'deliveryType' => 'D',
                'agency' => null,
                'address' => [
                    'streetName' => (string) ($envio->calle ?? ''),
                    'streetNumber' => (string) ($envio->numero ?? ''),
                    'floor' => $this->nullableString($envio->piso, 3),
                    'apartment' => $this->nullableString($envio->departamento),
                    'city' => (string) ($envio->localidad ?? ''),
                    'provinceCode' => $destinationProvinceCode,
                    'postalCode' => (string) ($envio->codigo_postal ?? ''),
                ],
                'productType' => (string) config('services.correo_argentino.default_product_type', 'CP'),
                'weight' => $dimensions['weight'],
                'declaredValue' => $this->declaredValueForPackage($envio, $primaryPackage),
                'height' => $dimensions['height'],
                'length' => $dimensions['length'],
                'width' => $dimensions['width'],
            ],
        ];

        $tracking = [
            'shippingId' => (string) (
                data_get($envio->datos_adicionales, 'correo_argentino.tracking_lookup_id')
                ?: $envio->referencia_externa
                ?: $externalOrderId
            ),
        ];

        $agencies = [
            'customerId' => $customerId,
            'provinceCode' => $destinationProvinceCode,
        ];

        return [
            'rates' => $rates,
            'shipping_import' => $shippingImport,
            'tracking' => $tracking,
            'agencies' => $agencies,
        ];
    }

    public function quote(array $payload): array
    {
        return $this->request()
            ->post('/rates', $this->normalizeRatePayload($payload))
            ->throw()
            ->json();
    }

    public function importShipping(array $payload): array
    {
        return $this->request()
            ->post('/shipping/import', $this->normalizeShippingImportPayload($payload))
            ->throw()
            ->json();
    }

    public function track(array $params): array
    {
        return $this->request()
            ->get('/shipping/tracking', $this->normalizeTrackingParams($params))
            ->throw()
            ->json();
    }

    public function syncEnvioWithImportResponse(Envio $envio, array $response, ?array $payload = null): Envio
    {
        $payload ??= $this->buildSuggestedPayloadsForEnvio($envio)['shipping_import'];
        $data = $this->extractShippingNode($response);
        $externalOrderId = (string) ($payload['extOrderId'] ?? $payload['orderNumber'] ?? $envio->referencia_externa ?? "ENV-{$envio->id}");
        $trackingLookupId = (string) ($data['shippingId'] ?? $data['id'] ?? $externalOrderId);

        $datosAdicionales = $envio->datos_adicionales ?? [];
        $datosAdicionales['correo_argentino'] = array_filter([
            'customer_id' => $payload['customerId'] ?? null,
            'tracking_lookup_id' => $trackingLookupId,
            'last_import_payload' => $payload,
            'last_import_response' => $response,
            'imported_at' => $response['createdAt'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $envio->update([
            'proveedor' => 'correo_argentino',
            'referencia_externa' => $trackingLookupId ?: $envio->referencia_externa,
            'codigo_seguimiento' => $data['trackingNumber'] ?? $data['tracking'] ?? $envio->codigo_seguimiento,
            'codigo_bulto' => $data['parcelNumber'] ?? $data['packageNumber'] ?? $envio->codigo_bulto,
            'url_etiqueta' => $data['labelUrl'] ?? $data['urlEtiqueta'] ?? $envio->url_etiqueta,
            'archivo_etiqueta' => $data['labelFile'] ?? $data['archivoEtiqueta'] ?? $envio->archivo_etiqueta,
            'fecha_generacion' => $envio->fecha_generacion ?: $this->parseRemoteDate($response['createdAt'] ?? null),
            'respuesta_ultima_api' => $response,
            'datos_adicionales' => $datosAdicionales,
        ]);

        return $envio->fresh(['venta.usuario', 'domicilio', 'bultos', 'eventos']);
    }

    public function resolveCustomerId(): string
    {
        $configured = $this->configuredCustomerId();
        if ($configured !== '') {
            return $configured;
        }

        return Cache::remember(self::CUSTOMER_ID_CACHE_KEY, now()->addHours(12), function () {
            $validated = $this->validateUser();
            $customerId = trim((string) ($validated['customerId'] ?? ''));

            if ($customerId === '') {
                throw ValidationException::withMessages([
                    'correo_argentino' => 'MiCorreo no devolvio un customerId utilizable.',
                ]);
            }

            return $customerId;
        });
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($this->requestToken())
            ->timeout((int) config('services.correo_argentino.timeout', 20));
    }

    private function requestToken(): string
    {
        if (!$this->isConfigured()) {
            throw ValidationException::withMessages([
                'correo_argentino' => 'Faltan las credenciales de MiCorreo.',
            ]);
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(45), function () {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->acceptJson()
                ->withBasicAuth($this->configuredUsername(), $this->configuredPassword())
                ->timeout((int) config('services.correo_argentino.timeout', 20))
                ->post('/token')
                ->throw();

            return $this->resolveToken($response);
        });
    }

    private function resolveToken(Response $response): string
    {
        $token = trim((string) (
            $response->json('token')
            ?? $response->json('access_token')
            ?? $response->json('jwt')
            ?? ''
        ));

        if ($token === '') {
            throw ValidationException::withMessages([
                'correo_argentino' => 'La autenticacion de MiCorreo no devolvio un token utilizable.',
            ]);
        }

        return $token;
    }

    private function normalizeRatePayload(array $payload): array
    {
        $dimensions = $payload['dimensions'] ?? $payload['parcel'] ?? [];

        return [
            'customerId' => (string) ($payload['customerId'] ?? $this->resolveCustomerId()),
            'postalCodeOrigin' => (string) ($payload['postalCodeOrigin'] ?? $this->senderOriginPostalCode()),
            'postalCodeDestination' => (string) ($payload['postalCodeDestination'] ?? ''),
            'deliveredType' => $payload['deliveredType'] ?? $payload['deliveryType'] ?? $payload['deliveryMode'] ?? null,
            'dimensions' => [
                'weight' => $this->toPositiveInt($dimensions['weight'] ?? $payload['weight'] ?? null),
                'height' => $this->toPositiveInt($dimensions['height'] ?? $payload['height'] ?? null),
                'width' => $this->toPositiveInt($dimensions['width'] ?? $payload['width'] ?? null),
                'length' => $this->toPositiveInt($dimensions['length'] ?? $payload['length'] ?? null),
            ],
        ];
    }

    private function normalizeShippingImportPayload(array $payload): array
    {
        $sender = $payload['sender'] ?? [];
        $recipient = $payload['recipient'] ?? [];
        $shipping = $payload['shipping'] ?? [];
        $address = $shipping['address'] ?? $payload['address'] ?? [];
        $originAddress = $sender['originAddress'] ?? [];

        return [
            'customerId' => (string) ($payload['customerId'] ?? $this->resolveCustomerId()),
            'extOrderId' => (string) ($payload['extOrderId'] ?? $payload['externalOrderId'] ?? ''),
            'orderNumber' => (string) ($payload['orderNumber'] ?? $payload['extOrderId'] ?? ''),
            'sender' => [
                'name' => $this->nullableString($sender['name'] ?? data_get($payload, 'senderName') ?? config('services.correo_argentino.sender.name') ?? config('app.name')),
                'phone' => $this->nullableString($sender['phone'] ?? config('services.correo_argentino.sender.phone')),
                'cellPhone' => $this->nullableString($sender['cellPhone'] ?? config('services.correo_argentino.sender.cell_phone')),
                'email' => $this->nullableString($sender['email'] ?? config('services.correo_argentino.sender.email') ?? config('mail.from.address')),
                'originAddress' => [
                    'streetName' => $this->nullableString($originAddress['streetName'] ?? config('services.correo_argentino.sender.origin.street_name')),
                    'streetNumber' => $this->nullableString($originAddress['streetNumber'] ?? config('services.correo_argentino.sender.origin.street_number')),
                    'floor' => $this->nullableString($originAddress['floor'] ?? config('services.correo_argentino.sender.origin.floor'), 3),
                    'apartment' => $this->nullableString($originAddress['apartment'] ?? config('services.correo_argentino.sender.origin.apartment')),
                    'city' => $this->nullableString($originAddress['city'] ?? config('services.correo_argentino.sender.origin.city')),
                    'provinceCode' => $this->resolveProvinceCode($originAddress['provinceCode'] ?? config('services.correo_argentino.sender.origin.province')),
                    'postalCode' => $this->nullableString($originAddress['postalCode'] ?? $this->senderOriginPostalCode()),
                ],
            ],
            'recipient' => [
                'name' => (string) ($recipient['name'] ?? ''),
                'phone' => $this->nullableString($recipient['phone'] ?? null),
                'cellPhone' => $this->nullableString($recipient['cellPhone'] ?? $recipient['phone'] ?? null),
                'email' => $this->nullableString($recipient['email'] ?? null),
            ],
            'shipping' => [
                'deliveryType' => $shipping['deliveryType'] ?? $shipping['deliveredType'] ?? $shipping['deliveryMode'] ?? 'D',
                'agency' => $this->nullableString($shipping['agency'] ?? null),
                'address' => [
                    'streetName' => (string) ($address['streetName'] ?? $address['street'] ?? ''),
                    'streetNumber' => (string) ($address['streetNumber'] ?? $address['number'] ?? ''),
                    'floor' => $this->nullableString($address['floor'] ?? null, 3),
                    'apartment' => $this->nullableString($address['apartment'] ?? $address['department'] ?? null),
                    'city' => (string) ($address['city'] ?? ''),
                    'provinceCode' => $this->resolveProvinceCode($address['provinceCode'] ?? $address['province'] ?? null),
                    'postalCode' => (string) ($address['postalCode'] ?? ''),
                ],
                'productType' => (string) ($shipping['productType'] ?? config('services.correo_argentino.default_product_type', 'CP')),
                'weight' => $this->toPositiveInt($shipping['weight'] ?? null),
                'declaredValue' => round((float) ($shipping['declaredValue'] ?? 0), 2),
                'height' => $this->toPositiveInt($shipping['height'] ?? null),
                'length' => $this->toPositiveInt($shipping['length'] ?? null),
                'width' => $this->toPositiveInt($shipping['width'] ?? null),
            ],
        ];
    }

    private function normalizeTrackingParams(array $params): array
    {
        return [
            'shippingId' => (string) ($params['shippingId'] ?? $params['id'] ?? ''),
        ];
    }

    private function primaryPackage(Envio $envio): ?EnvioBulto
    {
        return $envio->bultos->sortBy('numero_bulto')->first();
    }

    private function buildDimensionsPayload(Envio $envio, ?EnvioBulto $package): array
    {
        return [
            'weight' => $this->toPositiveInt($package?->peso_gramos ?? $envio->peso_gramos),
            'height' => $this->toPositiveInt($package?->alto_cm ?? $envio->alto_cm),
            'width' => $this->toPositiveInt($package?->ancho_cm ?? $envio->ancho_cm),
            'length' => $this->toPositiveInt($package?->largo_cm ?? $envio->largo_cm),
        ];
    }

    private function declaredValueForPackage(Envio $envio, ?EnvioBulto $package): float
    {
        return round((float) ($package?->valor_declarado ?? $envio->venta?->total ?? 0), 2);
    }

    private function buildSenderPayload(): array
    {
        return [
            'name' => $this->nullableString(config('services.correo_argentino.sender.name') ?? config('app.name')),
            'phone' => $this->nullableString(config('services.correo_argentino.sender.phone')),
            'cellPhone' => $this->nullableString(config('services.correo_argentino.sender.cell_phone')),
            'email' => $this->nullableString(config('services.correo_argentino.sender.email') ?? config('mail.from.address')),
            'originAddress' => [
                'streetName' => $this->nullableString(config('services.correo_argentino.sender.origin.street_name')),
                'streetNumber' => $this->nullableString(config('services.correo_argentino.sender.origin.street_number')),
                'floor' => $this->nullableString(config('services.correo_argentino.sender.origin.floor'), 3),
                'apartment' => $this->nullableString(config('services.correo_argentino.sender.origin.apartment')),
                'city' => $this->nullableString(config('services.correo_argentino.sender.origin.city')),
                'provinceCode' => $this->resolveProvinceCode(config('services.correo_argentino.sender.origin.province')),
                'postalCode' => $this->nullableString($this->senderOriginPostalCode()),
            ],
        ];
    }

    private function senderOriginPostalCode(): string
    {
        return trim((string) (
            config('services.correo_argentino.sender.origin.postal_code')
            ?: config('services.correo_argentino.origin_postal_code')
            ?: ''
        ));
    }

    private function resolveProvinceCode(string|null $province): ?string
    {
        $province = trim((string) $province);
        if ($province === '') {
            return null;
        }

        $province = $this->normalizeProvinceName($province);

        if (strlen($province) === 1 && ctype_alpha($province)) {
            return strtoupper($province);
        }

        return self::PROVINCE_CODES[$province] ?? null;
    }

    private function normalizeProvinceName(string $province): string
    {
        $normalized = strtolower(trim($province));

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $normalized
        );
    }

    private function toPositiveInt(mixed $value): int
    {
        return max(1, (int) round((float) ($value ?? 0)));
    }

    private function nullableString(mixed $value, ?int $maxLength = null): ?string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return null;
        }

        if ($maxLength !== null) {
            $string = mb_substr($string, 0, $maxLength);
        }

        return $string;
    }

    private function parseRemoteDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractShippingNode(array $response): array
    {
        if (isset($response['shipping']) && is_array($response['shipping'])) {
            return $response['shipping'];
        }

        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        if (isset($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        return $response;
    }

    private function configuredUsername(): string
    {
        return trim((string) config('services.correo_argentino.username'));
    }

    private function configuredPassword(): string
    {
        return trim((string) config('services.correo_argentino.password'));
    }

    private function configuredCustomerId(): string
    {
        return trim((string) config('services.correo_argentino.customer_id'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.correo_argentino.base_url'), '/');
    }
}
