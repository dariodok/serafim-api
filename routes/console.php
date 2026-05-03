<?php

use App\Services\Afip\AfipBillingException;
use App\Services\Afip\AfipConstanciaInscripcionClient;
use App\Services\Afip\AfipFiscalLookupService;
use App\Services\Afip\AfipPadronA13Client;
use App\Services\Afip\AfipSoapClient;
use App\Services\TransactionalEmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('serafim:mail-test {to?} {--subject=Prueba de correo Serafim} {--message=Este es un correo de prueba enviado desde Serafim.}', function (TransactionalEmailService $mailService) {
    $to = $this->argument('to') ?: env('MAIL_TEST_RECIPIENT');

    if (! $to) {
        $this->error('Indica un destinatario o configura MAIL_TEST_RECIPIENT.');

        return self::FAILURE;
    }

    $mailService->sendTestEmail(
        to: (string) $to,
        subject: (string) $this->option('subject'),
        message: (string) $this->option('message'),
    );

    $this->info(sprintf('Correo de prueba enviado a %s usando %s.', $to, config('mail.default')));

    return self::SUCCESS;
})->purpose('Envia un correo de prueba usando la configuracion SMTP actual');

Artisan::command('afip:test-connection {--tipo=6 : Tipo de comprobante AFIP para consultar, 1 Factura A, 6 Factura B} {--pto-vta= : Punto de venta a consultar; por defecto AFIP_PTO_VTA}', function (AfipSoapClient $afip) {
    $resolvePath = static function (string $path): string {
        if (filter_var($path, FILTER_VALIDATE_URL) || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    };

    $environment = (string) config('afip.environment', 'homologacion');
    $cuit = (string) config('afip.cuit');
    $pointOfSale = (int) ($this->option('pto-vta') ?: config('afip.point_of_sale'));
    $voucherType = (int) $this->option('tipo');
    $certificatePath = $resolvePath((string) config('afip.certificate_path'));
    $privateKeyPath = $resolvePath((string) config('afip.private_key_path'));

    $errors = [];

    if (! extension_loaded('soap')) {
        $errors[] = 'Falta habilitar la extension PHP soap.';
    }

    if (! extension_loaded('openssl')) {
        $errors[] = 'Falta habilitar la extension PHP openssl.';
    }

    if ($cuit === '') {
        $errors[] = 'Falta configurar AFIP_CUIT.';
    }

    if ($pointOfSale <= 0) {
        $errors[] = 'Falta configurar un AFIP_PTO_VTA valido.';
    }

    if ($voucherType <= 0) {
        $errors[] = 'Indica un tipo de comprobante AFIP valido con --tipo.';
    }

    if (! file_exists($certificatePath)) {
        $errors[] = "No se encontro el certificado en [{$certificatePath}].";
    }

    if (! file_exists($privateKeyPath)) {
        $errors[] = "No se encontro la clave privada en [{$privateKeyPath}].";
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }

    $this->line("Ambiente AFIP: {$environment}");
    $this->line("CUIT emisor: {$cuit}");
    $this->line("Punto de venta: {$pointOfSale}");
    $this->line("Tipo de comprobante: {$voucherType}");
    $this->line("Certificado: {$certificatePath}");
    $this->line("Clave privada: {$privateKeyPath}");
    $this->line('Consultando WSAA/WSFE sin emitir comprobantes...');

    try {
        $lastAuthorized = $afip->getLastAuthorized($pointOfSale, $voucherType);
    } catch (AfipBillingException $exception) {
        $this->error('AFIP rechazo la prueba: '.$exception->getMessage());

        return self::FAILURE;
    } catch (Throwable $exception) {
        $this->error('No se pudo completar la prueba AFIP: '.$exception->getMessage());

        return self::FAILURE;
    }

    $this->info('Conexion AFIP OK.');
    $this->info("Ultimo comprobante autorizado para punto {$pointOfSale}, tipo {$voucherType}: {$lastAuthorized}");

    return self::SUCCESS;
})->purpose('Prueba la conexion AFIP WSAA/WSFE sin emitir comprobantes');

Artisan::command('afip:a13-test {documento? : DNI a consultar; si se omite solo ejecuta dummy} {--nombre= : Nombre para ordenar posibles homonimos} {--json : Muestra la respuesta completa normalizada y raw}', function (AfipPadronA13Client $padron) {
    $document = $this->argument('documento');

    try {
        $dummy = $padron->dummy();
        $this->info(sprintf(
            'A13 dummy OK. app=%s auth=%s db=%s',
            data_get($dummy, 'return.appserver', '?'),
            data_get($dummy, 'return.authserver', '?'),
            data_get($dummy, 'return.dbserver', '?'),
        ));

        if (! $document) {
            return self::SUCCESS;
        }

        $candidates = $padron->findCandidatesByDocument((string) $document, (string) $this->option('nombre'));
    } catch (AfipBillingException $exception) {
        $this->error('AFIP A13 rechazo la prueba: '.$exception->getMessage());

        return self::FAILURE;
    } catch (Throwable $exception) {
        $this->error('No se pudo completar la prueba AFIP A13: '.$exception->getMessage());

        return self::FAILURE;
    }

    $this->info('Candidatos encontrados: '.count($candidates));
    $this->table(
        ['CUIT/CUIL', 'Tipo clave', 'Estado', 'Tipo persona', 'Nombre', 'Domicilios', 'Coincidencia', 'Error A13'],
        collect($candidates)->map(fn (array $candidate): array => [
            $candidate['id_persona'],
            $candidate['tipo_clave'] ?: '-',
            $candidate['estado_clave'] ?: '-',
            $candidate['tipo_persona'] ?: '-',
            $candidate['nombre_completo'] ?: '-',
            count($candidate['domicilios'] ?? []),
            $candidate['match_score'].'%',
            $candidate['a13_error'] ?: '-',
        ])->all(),
    );

    if ($this->option('json')) {
        $this->line(json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return self::SUCCESS;
})->purpose('Prueba ws_sr_padron_a13 y opcionalmente busca CUIT/CUIL por DNI');

Artisan::command('afip:constancia-test {cuit? : CUIT/CUIL a consultar; por defecto AFIP_CUIT} {--json : Muestra la respuesta completa normalizada y raw}', function (AfipConstanciaInscripcionClient $constancia) {
    $cuit = (string) ($this->argument('cuit') ?: config('afip.cuit'));

    try {
        $dummy = $constancia->dummy();
        $this->info(sprintf(
            'Constancia dummy OK. app=%s auth=%s db=%s',
            data_get($dummy, 'return.appserver', '?'),
            data_get($dummy, 'return.authserver', '?'),
            data_get($dummy, 'return.dbserver', '?'),
        ));

        $profile = $constancia->fiscalProfile($cuit);
    } catch (AfipBillingException $exception) {
        $this->error('AFIP Constancia rechazo la prueba: '.$exception->getMessage());

        return self::FAILURE;
    } catch (Throwable $exception) {
        $this->error('No se pudo completar la prueba AFIP Constancia: '.$exception->getMessage());

        return self::FAILURE;
    }

    $this->table(['Campo', 'Valor'], [
        ['CUIT/CUIL', $profile['id_persona']],
        ['Estado clave', $profile['estado_clave']],
        ['Tipo persona', $profile['tipo_persona']],
        ['Nombre', $profile['nombre_completo']],
        ['Condicion IVA inferida', $profile['condicion_iva']],
        ['Condicion IVA receptor ID', $profile['condicion_iva_receptor_id']],
        ['Comprobante sugerido', $profile['tipo_comprobante_sugerido']],
        ['Domicilios', count($profile['domicilios'])],
        ['Caracterizaciones', count($profile['caracterizaciones'])],
        ['Actividades', count($profile['actividades'])],
        ['Impuestos', count($profile['impuestos'])],
        ['Regimenes', count($profile['regimenes'])],
        ['Categorias', count($profile['categorias'])],
        ['Relaciones', count($profile['relaciones'])],
    ]);

    if ($this->option('json')) {
        $this->line(json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return self::SUCCESS;
})->purpose('Prueba ws_sr_constancia_inscripcion y resume condicion fiscal por CUIT/CUIL');

Artisan::command('afip:fiscal-lookup-test {documento : DNI a consultar} {--nombre= : Nombre para ordenar posibles homonimos} {--json : Muestra la respuesta completa normalizada y raw}', function (AfipFiscalLookupService $lookup) {
    try {
        $candidates = $lookup->findCandidatesByDocument((string) $this->argument('documento'), (string) $this->option('nombre'));
    } catch (Throwable $exception) {
        $this->error('No se pudo completar la busqueda fiscal AFIP: '.$exception->getMessage());

        return self::FAILURE;
    }

    $this->info('Candidatos encontrados: '.count($candidates));
    $this->table(
        ['CUIT/CUIL', 'Estado', 'Nombre', 'Coincidencia', 'Condicion IVA', 'Comprobante', 'Domicilios', 'Actividades', 'Impuestos', 'Error A13', 'Error constancia'],
        collect($candidates)->map(fn (array $candidate): array => [
            $candidate['id_persona'],
            $candidate['estado_clave'] ?: '-',
            $candidate['nombre_completo'] ?: '-',
            $candidate['match_score'].'%',
            $candidate['condicion_iva'] ?: '-',
            $candidate['tipo_comprobante_sugerido'] ?: '-',
            count($candidate['domicilios'] ?? []),
            count($candidate['actividades'] ?? []),
            count($candidate['impuestos'] ?? []),
            $candidate['a13_error'] ?: '-',
            $candidate['constancia_error'] ?: '-',
        ])->all(),
    );

    if ($this->option('json')) {
        $this->line(json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return self::SUCCESS;
})->purpose('Busca candidatos fiscales por DNI usando A13 y Constancia');
