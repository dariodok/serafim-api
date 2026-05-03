<?php

namespace App\Services\Afip;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SoapClient;
use SoapFault;

class AfipConstanciaInscripcionClient
{
    private const SERVICE = 'ws_sr_constancia_inscripcion';

    private ?SoapClient $client = null;

    public function __construct(private readonly AfipWsaaAuthenticator $authenticator) {}

    public function dummy(): array
    {
        return $this->call('dummy');
    }

    public function getPersonaV2(string $cuit): array
    {
        $response = $this->call('getPersona_v2', $this->authPayload([
            'idPersona' => $this->onlyDigits($cuit),
        ]));
        $persona = (array) data_get($response, 'personaReturn', []);
        $general = (array) ($persona['datosGenerales'] ?? []);
        $regimenGeneral = (array) ($persona['datosRegimenGeneral'] ?? []);
        $monotributo = (array) ($persona['datosMonotributo'] ?? []);

        return [
            'datos_generales' => $general,
            'datos_regimen_general' => $regimenGeneral,
            'datos_monotributo' => $monotributo,
            'domicilios' => $this->domiciliosFromGeneral($general),
            'caracterizaciones' => $this->arrayItems($general['caracterizacion'] ?? null),
            'actividades' => $this->actividadesFromSections($regimenGeneral, $monotributo),
            'impuestos' => $this->impuestosFromSections($regimenGeneral, $monotributo),
            'regimenes' => $this->arrayItems($regimenGeneral['regimen'] ?? null),
            'categorias' => $this->categoriasFromSections($regimenGeneral, $monotributo),
            'relaciones' => $this->arrayItems($monotributo['componenteDeSociedad'] ?? null),
            'errores' => [
                'constancia' => $this->arrayItems($persona['errorConstancia'] ?? null),
                'regimen_general' => $this->arrayItems($persona['errorRegimenGeneral'] ?? null),
                'monotributo' => $this->arrayItems($persona['errorMonotributo'] ?? null),
            ],
            'error_constancia' => $this->errorMessages($persona['errorConstancia'] ?? null),
            'error_regimen_general' => $this->errorMessages($persona['errorRegimenGeneral'] ?? null),
            'error_monotributo' => $this->errorMessages($persona['errorMonotributo'] ?? null),
            'metadata' => (array) ($persona['metadata'] ?? []),
            'raw' => $response,
        ];
    }

    public function fiscalProfile(string $cuit): array
    {
        $person = $this->getPersonaV2($cuit);
        $general = $person['datos_generales'];
        $ivaCondition = $this->inferIvaCondition($person);

        return [
            'id_persona' => (string) ($general['idPersona'] ?? $this->onlyDigits($cuit)),
            'tipo_persona' => $general['tipoPersona'] ?? null,
            'tipo_clave' => $general['tipoClave'] ?? null,
            'estado_clave' => $general['estadoClave'] ?? null,
            'nombre' => $general['nombre'] ?? null,
            'apellido' => $general['apellido'] ?? null,
            'razon_social' => $general['razonSocial'] ?? null,
            'nombre_completo' => $this->personName($general),
            'domicilio_fiscal' => (array) ($general['domicilioFiscal'] ?? []),
            'domicilios' => $person['domicilios'],
            'condicion_iva' => $ivaCondition['label'],
            'condicion_iva_receptor_id' => $ivaCondition['id'],
            'tipo_comprobante_sugerido' => in_array($ivaCondition['id'], [1, 6, 13, 16], true) ? 'factura_a' : 'factura_b',
            'caracterizaciones' => $person['caracterizaciones'],
            'actividades' => $person['actividades'],
            'impuestos' => $person['impuestos'],
            'regimenes' => $person['regimenes'],
            'categorias' => $person['categorias'],
            'relaciones' => $person['relaciones'],
            'errores' => $person['errores'],
            'constancia' => $person,
        ];
    }

    private function call(string $method, array $payload = []): array
    {
        try {
            $response = $this->client()->{$method}($payload);
        } catch (SoapFault $fault) {
            throw new AfipBillingException("SOAP Fault AFIP Constancia {$method}: {$fault->faultcode} - {$fault->faultstring}", previous: $fault);
        }

        return $this->authenticator->objectToArray($response);
    }

    private function authPayload(array $payload): array
    {
        $auth = $this->authenticator->auth(self::SERVICE);

        return array_merge([
            'token' => $auth['token'],
            'sign' => $auth['sign'],
            'cuitRepresentada' => (int) config('afip.cuit'),
        ], $payload);
    }

    private function client(): SoapClient
    {
        if ($this->client instanceof SoapClient) {
            return $this->client;
        }

        $this->client = new SoapClient(
            $this->authenticator->resolvePath((string) $this->authenticator->environmentConfig('constancia_wsdl')),
            $this->authenticator->soapOptions((string) $this->authenticator->environmentConfig('constancia_url'))
        );

        return $this->client;
    }

    private function inferIvaCondition(array $person): array
    {
        if ($person['datos_monotributo'] !== [] && $person['error_monotributo'] === []) {
            return ['id' => 6, 'label' => 'Responsable Monotributo'];
        }

        $impuestos = collect($this->firstOrMany(data_get($person, 'datos_regimen_general.impuesto')))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        $hasActiveVat = $impuestos->contains(function (array $tax): bool {
            $id = (string) $this->value($tax, ['idImpuesto', 'IdImpuesto']);
            $description = $this->normalize((string) $this->value($tax, ['descripcionImpuesto', 'DescripcionImpuesto']));
            $status = strtoupper((string) $this->value($tax, ['estadoImpuesto', 'EstadoImpuesto']));

            return ($id === '30' || $description === 'iva') && ($status === '' || $status === 'AC');
        });

        if ($hasActiveVat) {
            return ['id' => 1, 'label' => 'IVA Responsable Inscripto'];
        }

        $hasVatExempt = $impuestos->contains(function (array $tax): bool {
            $description = $this->normalize((string) $this->value($tax, ['descripcionImpuesto', 'DescripcionImpuesto']));

            return Str::contains($description, ['iva exento', 'exento']);
        });

        if ($hasVatExempt) {
            return ['id' => 4, 'label' => 'IVA Sujeto Exento'];
        }

        if ($person['datos_regimen_general'] !== []) {
            return ['id' => 15, 'label' => 'IVA No Alcanzado'];
        }

        return ['id' => 5, 'label' => 'Consumidor final'];
    }

    private function value(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function personName(array $data): string
    {
        if (! empty($data['razonSocial'])) {
            return (string) $data['razonSocial'];
        }

        return collect([$data['apellido'] ?? null, $data['nombre'] ?? null])
            ->filter()
            ->implode(' ');
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private function domiciliosFromGeneral(array $general): array
    {
        return collect($this->arrayItems($general['domicilioFiscal'] ?? null))
            ->filter(fn (mixed $domicilio): bool => is_array($domicilio))
            ->map(fn (array $domicilio): array => array_merge(['origen' => 'constancia_domicilio_fiscal'], $domicilio))
            ->values()
            ->all();
    }

    private function actividadesFromSections(array $regimenGeneral, array $monotributo): array
    {
        return collect()
            ->merge($this->taggedItems($regimenGeneral['actividad'] ?? null, 'regimen_general'))
            ->merge($this->taggedItems($monotributo['actividad'] ?? null, 'monotributo'))
            ->merge($this->taggedItems($monotributo['actividadMonotributista'] ?? null, 'monotributo_actividad_principal'))
            ->values()
            ->all();
    }

    private function impuestosFromSections(array $regimenGeneral, array $monotributo): array
    {
        return collect()
            ->merge($this->taggedItems($regimenGeneral['impuesto'] ?? null, 'regimen_general'))
            ->merge($this->taggedItems($monotributo['impuesto'] ?? null, 'monotributo'))
            ->values()
            ->all();
    }

    private function categoriasFromSections(array $regimenGeneral, array $monotributo): array
    {
        return collect()
            ->merge($this->taggedItems($regimenGeneral['categoriaAutonomo'] ?? null, 'autonomo'))
            ->merge($this->taggedItems($monotributo['categoriaMonotributo'] ?? null, 'monotributo'))
            ->values()
            ->all();
    }

    private function taggedItems(mixed $value, string $source): array
    {
        return collect($this->arrayItems($value))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => array_merge(['origen' => $source], $item))
            ->values()
            ->all();
    }

    private function errorMessages(mixed $value): array
    {
        return collect($this->arrayItems($value))
            ->map(fn (mixed $item): ?string => is_array($item)
                ? (string) ($item['error'] ?? $item['mensaje'] ?? '')
                : (string) $item)
            ->filter()
            ->values()
            ->all();
    }

    private function arrayItems(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return $value === null || $value === '' ? [] : [(string) $value];
        }

        return Arr::isList($value) ? $value : [$value];
    }

    private function firstOrMany(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return $value === null || $value === '' ? [] : [(string) $value];
        }

        return Arr::isList($value) ? $value : [$value];
    }
}
