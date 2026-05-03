<?php

namespace App\Services\Afip;

use Illuminate\Support\Arr;
use SoapClient;
use SoapFault;

class AfipSoapClient
{
    private const WSFE_SERVICE = 'wsfe';

    private ?SoapClient $wsfeClient = null;

    public function __construct(private readonly AfipWsaaAuthenticator $authenticator) {}

    public function nextVoucherNumber(int $pointOfSale, int $voucherType): int
    {
        return $this->getLastAuthorized($pointOfSale, $voucherType) + 1;
    }

    public function authorize(array $feCAEReq): array
    {
        $pointOfSale = (int) data_get($feCAEReq, 'FeCabReq.PtoVta');
        $voucherType = (int) data_get($feCAEReq, 'FeCabReq.CbteTipo');
        $nextNumber = $this->nextVoucherNumber($pointOfSale, $voucherType);

        data_set($feCAEReq, 'FeDetReq.FECAEDetRequest.CbteDesde', $nextNumber);
        data_set($feCAEReq, 'FeDetReq.FECAEDetRequest.CbteHasta', $nextNumber);

        return $this->authorizePrepared($feCAEReq);
    }

    public function authorizePrepared(array $feCAEReq): array
    {
        $pointOfSale = (int) data_get($feCAEReq, 'FeCabReq.PtoVta');
        $voucherType = (int) data_get($feCAEReq, 'FeCabReq.CbteTipo');
        $number = (int) data_get($feCAEReq, 'FeDetReq.FECAEDetRequest.CbteDesde');

        try {
            $response = $this->callWsfe('FECAESolicitar', [
                'FeCAEReq' => $feCAEReq,
            ]);
        } catch (\Throwable $exception) {
            $consulted = $this->consult($pointOfSale, $voucherType, $number);

            if ($consulted && ($consulted['cae'] ?? null)) {
                return [
                    'status' => 'authorized',
                    'number' => $number,
                    'cae' => $consulted['cae'],
                    'cae_due_date' => $consulted['cae_due_date'],
                    'request' => $feCAEReq,
                    'response' => $consulted['response'],
                    'observations' => [],
                    'recovered_by_consult' => true,
                ];
            }

            throw $exception;
        }

        return $this->normalizeCaeResponse($response, $feCAEReq, $number);
    }

    public function getLastAuthorized(int $pointOfSale, int $voucherType): int
    {
        $response = $this->callWsfe('FECompUltimoAutorizado', [
            'PtoVta' => $pointOfSale,
            'CbteTipo' => $voucherType,
        ]);

        $this->throwResponseErrors($response, 'FECompUltimoAutorizadoResult');

        return (int) data_get($response, 'FECompUltimoAutorizadoResult.CbteNro', 0);
    }

    public function consult(int $pointOfSale, int $voucherType, int $number): ?array
    {
        try {
            $response = $this->callWsfe('FECompConsultar', [
                'FeCompConsReq' => [
                    'CbteTipo' => $voucherType,
                    'CbteNro' => $number,
                    'PtoVta' => $pointOfSale,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }

        $result = data_get($response, 'FECompConsultarResult.ResultGet');

        if (! $result || data_get($response, 'FECompConsultarResult.Errors')) {
            return null;
        }

        $cae = data_get($result, 'CodAutorizacion');

        if (! $cae) {
            return null;
        }

        return [
            'cae' => (string) $cae,
            'cae_due_date' => (string) data_get($result, 'FchVto'),
            'response' => $response,
        ];
    }

    private function callWsfe(string $method, array $parameters): array
    {
        $auth = $this->auth();
        $payload = array_merge([
            'Auth' => [
                'Token' => $auth['token'],
                'Sign' => $auth['sign'],
                'Cuit' => (int) config('afip.cuit'),
            ],
        ], $parameters);

        try {
            $response = $this->wsfeClient()->{$method}($payload);
        } catch (SoapFault $fault) {
            throw new AfipBillingException("SOAP Fault AFIP {$method}: {$fault->faultcode} - {$fault->faultstring}", previous: $fault);
        }

        return $this->objectToArray($response);
    }

    private function normalizeCaeResponse(array $response, array $request, int $number): array
    {
        $this->throwResponseErrors($response, 'FECAESolicitarResult');

        $cabResult = (string) data_get($response, 'FECAESolicitarResult.FeCabResp.Resultado');
        $detail = $this->firstItem(data_get($response, 'FECAESolicitarResult.FeDetResp.FECAEDetResponse'));
        $detailResult = (string) data_get($detail, 'Resultado', $cabResult);
        $observations = $this->extractObservations($detail);

        if ($cabResult === 'A' && $detailResult === 'A') {
            return [
                'status' => 'authorized',
                'number' => $number,
                'cae' => (string) data_get($detail, 'CAE'),
                'cae_due_date' => (string) data_get($detail, 'CAEFchVto'),
                'request' => $request,
                'response' => $response,
                'observations' => $observations,
                'recovered_by_consult' => false,
            ];
        }

        return [
            'status' => 'rejected',
            'number' => $number,
            'cae' => null,
            'cae_due_date' => null,
            'request' => $request,
            'response' => $response,
            'observations' => $observations,
            'recovered_by_consult' => false,
        ];
    }

    private function throwResponseErrors(array $response, string $resultKey): void
    {
        $errors = $this->firstOrMany(data_get($response, "{$resultKey}.Errors.Err"));

        if ($errors === []) {
            return;
        }

        $messages = array_map(function (array $error): string {
            return sprintf('(%s) %s', $error['Code'] ?? 'AFIP', $error['Msg'] ?? 'Error sin detalle');
        }, $errors);

        throw new AfipBillingException(implode(' - ', $messages));
    }

    private function extractObservations(array $detail): array
    {
        return array_map(function (array $observation): array {
            return [
                'code' => $observation['Code'] ?? null,
                'message' => $observation['Msg'] ?? null,
            ];
        }, $this->firstOrMany(data_get($detail, 'Observaciones.Obs')));
    }

    private function auth(): array
    {
        return $this->authenticator->auth(self::WSFE_SERVICE);
    }

    private function wsfeClient(): SoapClient
    {
        if ($this->wsfeClient instanceof SoapClient) {
            return $this->wsfeClient;
        }

        $this->wsfeClient = new SoapClient(
            $this->resolvePath((string) $this->environmentConfig('wsfe_wsdl')),
            $this->soapOptions((string) $this->environmentConfig('wsfe_url'))
        );

        return $this->wsfeClient;
    }

    private function soapOptions(string $location): array
    {
        return $this->authenticator->soapOptions($location);
    }

    private function environmentConfig(string $key): mixed
    {
        return $this->authenticator->environmentConfig($key);
    }

    private function resolvePath(string $path): string
    {
        return $this->authenticator->resolvePath($path);
    }

    private function objectToArray(mixed $value): array
    {
        return $this->authenticator->objectToArray($value);
    }

    private function firstItem(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (Arr::isList($value)) {
            return (array) ($value[0] ?? []);
        }

        return $value;
    }

    private function firstOrMany(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        return Arr::isList($value) ? $value : [$value];
    }
}
