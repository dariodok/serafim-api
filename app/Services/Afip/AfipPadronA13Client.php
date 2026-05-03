<?php

namespace App\Services\Afip;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SoapClient;
use SoapFault;

class AfipPadronA13Client
{
    private const SERVICE = 'ws_sr_padron_a13';

    private ?SoapClient $client = null;

    public function __construct(private readonly AfipWsaaAuthenticator $authenticator) {}

    public function dummy(): array
    {
        return $this->call('dummy');
    }

    public function getIdPersonaListByDocumento(string $document): array
    {
        $response = $this->call('getIdPersonaListByDocumento', $this->authPayload([
            'documento' => $this->normalizeDocumentForA13($document),
        ]));

        return $this->firstOrMany(data_get($response, 'idPersonaListReturn.idPersona'));
    }

    public function getPersona(string $idPersona): array
    {
        $response = $this->call('getPersona', $this->authPayload([
            'idPersona' => $this->onlyDigits($idPersona),
        ]));

        return [
            'persona' => (array) data_get($response, 'personaReturn.persona', []),
            'domicilios' => $this->domiciliosFromPersona((array) data_get($response, 'personaReturn.persona', [])),
            'metadata' => (array) data_get($response, 'personaReturn.metadata', []),
            'raw' => $response,
        ];
    }

    public function findCandidatesByDocument(string $document, ?string $name = null): array
    {
        $ids = $this->getIdPersonaListByDocumento($document);

        return collect($ids)
            ->map(function (string|int $idPersona) use ($name): array {
                try {
                    return $this->candidateFromPerson((string) $idPersona, (string) $name);
                } catch (\Throwable $exception) {
                    return $this->candidateFromError((string) $idPersona, $exception->getMessage());
                }
            })
            ->sortByDesc('match_score')
            ->values()
            ->all();
    }

    private function call(string $method, array $payload = []): array
    {
        try {
            $response = $this->client()->{$method}($payload);
        } catch (SoapFault $fault) {
            throw new AfipBillingException("SOAP Fault AFIP A13 {$method}: {$fault->faultcode} - {$fault->faultstring}", previous: $fault);
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
            $this->authenticator->resolvePath((string) $this->authenticator->environmentConfig('a13_wsdl')),
            $this->authenticator->soapOptions((string) $this->authenticator->environmentConfig('a13_url'))
        );

        return $this->client;
    }

    private function normalizeDocumentForA13(string $document): string
    {
        return $this->onlyDigits($document);
    }

    private function candidateFromPerson(string $idPersona, string $name): array
    {
        $person = $this->getPersona($idPersona);
        $data = $person['persona'];
        $fullName = $this->personName($data);

        return [
            'id_persona' => (string) ($data['idPersona'] ?? $idPersona),
            'tipo_clave' => $data['tipoClave'] ?? null,
            'estado_clave' => $data['estadoClave'] ?? null,
            'tipo_persona' => $data['tipoPersona'] ?? null,
            'tipo_documento' => $data['tipoDocumento'] ?? null,
            'numero_documento' => isset($data['numeroDocumento']) ? (string) $data['numeroDocumento'] : null,
            'nombre' => $data['nombre'] ?? null,
            'apellido' => $data['apellido'] ?? null,
            'razon_social' => $data['razonSocial'] ?? null,
            'nombre_completo' => $fullName,
            'domicilios' => $person['domicilios'],
            'match_score' => $this->nameMatchScore($name, $fullName),
            'a13_error' => null,
            'a13' => $person,
        ];
    }

    private function candidateFromError(string $idPersona, string $message): array
    {
        return [
            'id_persona' => $idPersona,
            'tipo_clave' => null,
            'estado_clave' => Str::contains(Str::lower($message), 'inactiva') ? 'INACTIVA' : null,
            'tipo_persona' => null,
            'tipo_documento' => null,
            'numero_documento' => null,
            'nombre' => null,
            'apellido' => null,
            'razon_social' => null,
            'nombre_completo' => null,
            'domicilios' => [],
            'match_score' => 0,
            'a13_error' => $message,
            'a13' => null,
        ];
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
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

    private function domiciliosFromPersona(array $person): array
    {
        return collect($this->firstOrMany($person['domicilio'] ?? null))
            ->filter(fn (mixed $domicilio): bool => is_array($domicilio))
            ->map(fn (array $domicilio): array => array_merge(['origen' => 'a13'], $domicilio))
            ->values()
            ->all();
    }

    private function nameMatchScore(string $needle, string $candidate): int
    {
        $needle = $this->normalizeName($needle);
        $candidate = $this->normalizeName($candidate);

        if ($needle === '' || $candidate === '') {
            return 0;
        }

        if ($needle === $candidate) {
            return 100;
        }

        $tokens = collect(explode(' ', $needle))->filter();
        $matched = $tokens->filter(fn (string $token): bool => Str::contains($candidate, $token))->count();

        return $tokens->count() > 0 ? (int) round(($matched / $tokens->count()) * 100) : 0;
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9 ]+/', ' ')
            ->squish()
            ->toString();
    }

    private function firstOrMany(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return $value === null || $value === '' ? [] : [(string) $value];
        }

        return Arr::isList($value) ? $value : [$value];
    }
}
