<?php

namespace App\Services\Afip;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use SoapClient;

class AfipWsaaAuthenticator
{
    public function auth(string $service): array
    {
        $ticketPath = $this->serviceFilePath((string) config('afip.ticket_path'), $service);

        if (! $this->isTicketValid($ticketPath)) {
            $this->generateTicket($ticketPath, $service);
        }

        $xml = simplexml_load_file($ticketPath);

        if (! $xml || ! isset($xml->credentials->token, $xml->credentials->sign)) {
            throw new AfipBillingException("El ticket de acceso AFIP en [{$ticketPath}] es invalido.");
        }

        return [
            'token' => (string) $xml->credentials->token,
            'sign' => (string) $xml->credentials->sign,
        ];
    }

    public function resolvePath(string $path): string
    {
        if ($this->isUrl($path) || $this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    public function soapOptions(?string $location = null): array
    {
        $options = [
            'trace' => (bool) config('afip.soap.trace', true) ? 1 : 0,
            'exceptions' => true,
            'cache_wsdl' => (int) config('afip.soap.cache_wsdl', WSDL_CACHE_NONE),
            'connection_timeout' => (int) config('afip.soap.connection_timeout', 20),
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => (bool) config('afip.soap.verify_peer', true),
                    'verify_peer_name' => (bool) config('afip.soap.verify_peer_name', true),
                ],
            ]),
        ];

        if ($location !== null && $location !== '') {
            $options['location'] = $location;
        }

        return $options;
    }

    public function environmentConfig(string $key): mixed
    {
        $environment = (string) config('afip.environment', 'homologacion');
        $value = config("afip.environments.{$environment}.{$key}");

        if ($value === null) {
            throw new AfipBillingException("No existe configuracion AFIP para [{$environment}.{$key}].");
        }

        return $value;
    }

    public function objectToArray(mixed $value): array
    {
        return json_decode(json_encode($value), true) ?: [];
    }

    private function generateTicket(string $ticketPath, string $service): void
    {
        $traPath = $this->serviceFilePath((string) config('afip.tra_path'), $service);
        $tmpPath = $this->serviceFilePath((string) config('afip.tra_tmp_path'), $service);

        $this->createTra($traPath, $service);
        $cms = $this->signTra($traPath, $tmpPath);
        $response = $this->wsaaClient()->loginCms(['in0' => $cms]);
        $responseArray = $this->objectToArray($response);
        $ticketXml = data_get($responseArray, 'loginCmsReturn');

        if (! $ticketXml) {
            throw new AfipBillingException('AFIP WSAA no devolvio un ticket de acceso valido.');
        }

        File::ensureDirectoryExists(dirname($ticketPath));
        file_put_contents($ticketPath, $ticketXml);
    }

    private function createTra(string $path, string $service): void
    {
        File::ensureDirectoryExists(dirname($path));

        $now = CarbonImmutable::now((string) config('afip.timezone'));
        $tra = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"></loginTicketRequest>'
        );
        $tra->addChild('header');
        $tra->header->addChild('uniqueId', (string) time());
        $tra->header->addChild('generationTime', $now->subMinutes(10)->toRfc3339String());
        $tra->header->addChild('expirationTime', $now->addHours(12)->toRfc3339String());
        $tra->addChild('service', $service);
        $tra->asXML($path);
    }

    private function signTra(string $traPath, string $tmpPath): string
    {
        $certificatePath = $this->resolvePath((string) config('afip.certificate_path'));
        $privateKeyPath = $this->resolvePath((string) config('afip.private_key_path'));

        if (! file_exists($certificatePath)) {
            throw new AfipBillingException("No se encontro el certificado AFIP en [{$certificatePath}].");
        }

        if (! file_exists($privateKeyPath)) {
            throw new AfipBillingException("No se encontro la clave privada AFIP en [{$privateKeyPath}].");
        }

        File::ensureDirectoryExists(dirname($tmpPath));

        $signed = openssl_pkcs7_sign(
            $traPath,
            $tmpPath,
            'file://'.$certificatePath,
            ['file://'.$privateKeyPath, (string) config('afip.private_key_passphrase')],
            [],
            ! PKCS7_DETACHED
        );

        if (! $signed) {
            throw new AfipBillingException('No se pudo firmar el TRA para WSAA.');
        }

        $lines = file($tmpPath, FILE_IGNORE_NEW_LINES);
        @unlink($tmpPath);

        if ($lines === false || count($lines) <= 4) {
            throw new AfipBillingException('No se pudo leer la firma CMS generada para WSAA.');
        }

        return implode(PHP_EOL, array_slice($lines, 4));
    }

    private function isTicketValid(string $ticketPath): bool
    {
        if (! file_exists($ticketPath)) {
            return false;
        }

        $xml = simplexml_load_file($ticketPath);

        if (! $xml || ! isset($xml->header->expirationTime)) {
            return false;
        }

        return CarbonImmutable::parse((string) $xml->header->expirationTime)
            ->subMinutes(5)
            ->isFuture();
    }

    private function wsaaClient(): SoapClient
    {
        return new SoapClient(
            $this->resolvePath((string) $this->environmentConfig('wsaa_wsdl')),
            $this->soapOptions((string) $this->environmentConfig('wsaa_url'))
        );
    }

    private function serviceFilePath(string $pattern, string $service): string
    {
        return str_replace(
            ['%service%', '%environment%'],
            [$service, (string) config('afip.environment', 'homologacion')],
            $this->resolvePath($pattern)
        );
    }

    private function isUrl(string $path): bool
    {
        return (bool) filter_var($path, FILTER_VALIDATE_URL);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
