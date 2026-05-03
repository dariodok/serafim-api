<?php

namespace App\Services\Afip;

use App\Models\AfipConsultaFiscal;
use App\Models\DatoFacturacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AfipFiscalConsultationService
{
    public function __construct(
        private readonly AfipFiscalLookupService $lookup,
        private readonly AfipConstanciaInscripcionClient $constancia,
    ) {}

    public function lookupByDocument(
        string $document,
        ?string $name = null,
        ?Usuario $usuario = null,
        ?DatoFacturacion $datoFacturacion = null,
        bool $force = false,
    ): array {
        $document = $this->onlyDigits($document);

        if ($document === '') {
            throw ValidationException::withMessages([
                'documento' => 'Ingresa un documento valido para consultar AFIP.',
            ]);
        }

        if (! $force) {
            $cached = $this->latestFreshConsulta($usuario, $datoFacturacion, $document);

            if ($cached) {
                return [
                    'consulta' => $cached,
                    'candidates' => $cached->resultado_normalizado ?: ($cached->candidatos ?: []),
                    'cached' => true,
                    'error' => null,
                ];
            }
        }

        try {
            $candidates = $this->lookup->findCandidatesByDocument($document, $name);
        } catch (\Throwable $exception) {
            $consulta = $this->storeErrorConsulta(
                usuario: $usuario,
                datoFacturacion: $datoFacturacion,
                document: $document,
                name: $name,
                cuit: null,
                error: $exception,
            );

            return [
                'consulta' => $consulta,
                'candidates' => [],
                'cached' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $consulta = $this->storeConsulta(
            usuario: $usuario,
            datoFacturacion: $datoFacturacion,
            candidates: $candidates,
            document: $document,
            name: $name,
        );

        return [
            'consulta' => $consulta,
            'candidates' => $candidates,
            'cached' => false,
            'error' => null,
        ];
    }

    public function refreshUsuario(Usuario $usuario, bool $force = false, bool $throwOnFailure = false): array
    {
        $usuario->loadMissing('datosFacturacion');

        return $usuario->datosFacturacion
            ->filter(fn (DatoFacturacion $dato): bool => (bool) $dato->activo)
            ->map(fn (DatoFacturacion $dato): array => [
                'datos_facturacion_id' => $dato->id,
                'consulta' => $this->refreshDatoFacturacion($dato, $force, $throwOnFailure),
                'dato_facturacion' => $dato->fresh(),
            ])
            ->values()
            ->all();
    }

    public function refreshDatoFacturacion(
        DatoFacturacion $datoFacturacion,
        bool $force = false,
        bool $throwOnFailure = false,
    ): ?AfipConsultaFiscal {
        $datoFacturacion->loadMissing('usuario');

        if (! $this->canConsultAfip()) {
            if ($throwOnFailure) {
                throw ValidationException::withMessages([
                    'afip' => 'La consulta fiscal AFIP no esta habilitada o falta configurar AFIP_CUIT.',
                ]);
            }

            return null;
        }

        $document = $this->onlyDigits((string) $datoFacturacion->numero_documento);
        $cuit = $this->onlyDigits((string) ($datoFacturacion->cuit ?: $datoFacturacion->afip_id_persona));
        $usuario = $datoFacturacion->usuario;

        if (! $force) {
            $cached = $this->latestFreshConsulta($usuario, $datoFacturacion, $document, $cuit);

            if ($cached) {
                $this->applyConsultaToDatoFacturacion($datoFacturacion, $cached);

                return $cached;
            }
        }

        if ($cuit !== '') {
            return $this->refreshByCuit($datoFacturacion, $cuit, $throwOnFailure);
        }

        if ($document !== '') {
            $result = $this->lookupByDocument(
                document: $document,
                name: $this->nameForLookup($datoFacturacion),
                usuario: $usuario,
                datoFacturacion: $datoFacturacion,
                force: true,
            );

            if ($result['error']) {
                if ($throwOnFailure) {
                    throw new AfipBillingException($result['error']);
                }

                return $result['consulta'];
            }

            $candidate = $this->selectCandidate($result['candidates'], $datoFacturacion);

            if (! $candidate) {
                if ($throwOnFailure) {
                    throw ValidationException::withMessages([
                        'afip' => 'AFIP no devolvio una situacion fiscal aplicable para este dato de facturacion.',
                    ]);
                }

                return $result['consulta'];
            }

            $consulta = $result['consulta'];
            $this->markSelection($consulta, $candidate);
            $this->applyCandidateToDatoFacturacion($datoFacturacion, $candidate, $consulta);

            return $consulta->fresh();
        }

        if ($throwOnFailure) {
            throw ValidationException::withMessages([
                'afip' => 'El dato de facturacion necesita CUIT/CUIL o DNI para consultar AFIP.',
            ]);
        }

        return null;
    }

    public function tryRefreshDatoFacturacion(DatoFacturacion $datoFacturacion, bool $force = false): ?AfipConsultaFiscal
    {
        if (! config('afip.auto_refresh_fiscal_data', true)) {
            return null;
        }

        try {
            return $this->refreshDatoFacturacion($datoFacturacion, $force, false);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo refrescar automaticamente la situacion fiscal AFIP.', [
                'datos_facturacion_id' => $datoFacturacion->id,
                'usuario_id' => $datoFacturacion->usuario_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function refreshByCuit(
        DatoFacturacion $datoFacturacion,
        string $cuit,
        bool $throwOnFailure,
    ): ?AfipConsultaFiscal {
        try {
            $profile = $this->constancia->fiscalProfile($cuit);
        } catch (\Throwable $exception) {
            $consulta = $this->storeErrorConsulta(
                usuario: $datoFacturacion->usuario,
                datoFacturacion: $datoFacturacion,
                document: $this->onlyDigits((string) $datoFacturacion->numero_documento) ?: null,
                name: $this->nameForLookup($datoFacturacion),
                cuit: $cuit,
                error: $exception,
            );

            if ($throwOnFailure) {
                throw new AfipBillingException($exception->getMessage(), previous: $exception);
            }

            return $consulta;
        }

        $candidate = $this->candidateFromFiscalProfile($profile, $datoFacturacion);
        $consulta = $this->storeConsulta(
            usuario: $datoFacturacion->usuario,
            datoFacturacion: $datoFacturacion,
            candidates: [$candidate],
            document: $this->onlyDigits((string) $datoFacturacion->numero_documento) ?: null,
            name: $this->nameForLookup($datoFacturacion),
            cuit: $cuit,
            selection: $candidate,
        );

        $this->applyCandidateToDatoFacturacion($datoFacturacion, $candidate, $consulta);

        return $consulta->fresh();
    }

    private function storeConsulta(
        ?Usuario $usuario,
        ?DatoFacturacion $datoFacturacion,
        array $candidates,
        ?string $document = null,
        ?string $name = null,
        ?string $cuit = null,
        ?array $selection = null,
    ): AfipConsultaFiscal {
        return AfipConsultaFiscal::create([
            'usuario_id' => $usuario?->id ?: $datoFacturacion?->usuario_id,
            'datos_facturacion_id' => $datoFacturacion?->id,
            'documento_consultado' => $document ? $this->onlyDigits($document) : null,
            'nombre_buscado' => $name ?: null,
            'cuit_consultado' => $cuit ? $this->onlyDigits($cuit) : ($selection['id_persona'] ?? null),
            'id_persona_seleccionada' => $selection['id_persona'] ?? null,
            'estado_resultado' => $this->statusForCandidates($candidates),
            'candidatos' => $candidates,
            'seleccion' => $selection,
            'domicilios' => $this->collectField($candidates, 'domicilios'),
            'actividades' => $this->collectField($candidates, 'actividades'),
            'impuestos' => $this->collectField($candidates, 'impuestos'),
            'regimenes' => $this->collectField($candidates, 'regimenes'),
            'categorias' => $this->collectField($candidates, 'categorias'),
            'caracterizaciones' => $this->collectField($candidates, 'caracterizaciones'),
            'relaciones' => $this->collectField($candidates, 'relaciones'),
            'a13_raw' => $this->collectRaw($candidates, 'a13.raw'),
            'constancia_raw' => $this->collectRaw($candidates, 'constancia.constancia.raw'),
            'resultado_normalizado' => $candidates,
            'errores' => $this->collectErrors($candidates),
            'consultado_at' => now(),
        ]);
    }

    private function storeErrorConsulta(
        ?Usuario $usuario,
        ?DatoFacturacion $datoFacturacion,
        ?string $document,
        ?string $name,
        ?string $cuit,
        \Throwable $error,
    ): AfipConsultaFiscal {
        return AfipConsultaFiscal::create([
            'usuario_id' => $usuario?->id ?: $datoFacturacion?->usuario_id,
            'datos_facturacion_id' => $datoFacturacion?->id,
            'documento_consultado' => $document ? $this->onlyDigits($document) : null,
            'nombre_buscado' => $name ?: null,
            'cuit_consultado' => $cuit ? $this->onlyDigits($cuit) : null,
            'estado_resultado' => 'error',
            'errores' => [['message' => $error->getMessage()]],
            'consultado_at' => now(),
        ]);
    }

    private function latestFreshConsulta(
        ?Usuario $usuario,
        ?DatoFacturacion $datoFacturacion,
        ?string $document = null,
        ?string $cuit = null,
    ): ?AfipConsultaFiscal {
        $days = (int) config('afip.fiscal_cache_days', 30);

        if ($days <= 0) {
            return null;
        }

        $document = $document ? $this->onlyDigits($document) : '';
        $cuit = $cuit ? $this->onlyDigits($cuit) : '';
        $hasCriterion = (bool) ($datoFacturacion?->id || $document !== '' || $cuit !== '');

        if (! $hasCriterion) {
            return null;
        }

        return AfipConsultaFiscal::query()
            ->where('consultado_at', '>=', now()->subDays($days))
            ->whereIn('estado_resultado', ['ok', 'parcial'])
            ->when($usuario?->id, fn ($query) => $query->where('usuario_id', $usuario->id))
            ->where(function ($query) use ($datoFacturacion, $document, $cuit) {
                if ($datoFacturacion?->id) {
                    $query->orWhere('datos_facturacion_id', $datoFacturacion->id);
                }

                if ($document !== '') {
                    $query->orWhere('documento_consultado', $document);
                }

                if ($cuit !== '') {
                    $query
                        ->orWhere('cuit_consultado', $cuit)
                        ->orWhere('id_persona_seleccionada', $cuit);
                }
            })
            ->orderByDesc('consultado_at')
            ->first();
    }

    private function applyConsultaToDatoFacturacion(
        DatoFacturacion $datoFacturacion,
        AfipConsultaFiscal $consulta,
    ): void {
        $candidate = is_array($consulta->seleccion) && $consulta->seleccion !== []
            ? $consulta->seleccion
            : $this->selectCandidate($consulta->resultado_normalizado ?: ($consulta->candidatos ?: []), $datoFacturacion);

        if (! $candidate) {
            return;
        }

        $this->markSelection($consulta, $candidate);
        $this->applyCandidateToDatoFacturacion($datoFacturacion, $candidate, $consulta);
    }

    private function markSelection(AfipConsultaFiscal $consulta, array $candidate): void
    {
        $consulta->update([
            'id_persona_seleccionada' => $candidate['id_persona'] ?? null,
            'cuit_consultado' => $consulta->cuit_consultado ?: ($candidate['id_persona'] ?? null),
            'seleccion' => $candidate,
        ]);
    }

    private function applyCandidateToDatoFacturacion(
        DatoFacturacion $datoFacturacion,
        array $candidate,
        AfipConsultaFiscal $consulta,
    ): void {
        if (($candidate['a13_error'] ?? null) || ($candidate['constancia_error'] ?? null)) {
            return;
        }

        $tipoPersona = $this->resolveTipoPersona($candidate, $datoFacturacion);
        $fiscalName = $this->fiscalName($candidate);
        $domicilioFields = $this->fiscalAddressFields($candidate['domicilios'] ?? []);
        $payload = [
            'tipo_persona' => $tipoPersona,
            'razon_social' => $tipoPersona === 'juridica' ? $fiscalName : null,
            'nombre_completo' => $tipoPersona === 'fisica' ? ($fiscalName ?: $datoFacturacion->nombre_completo) : null,
            'tipo_documento' => $tipoPersona === 'fisica' ? ($candidate['tipo_documento'] ?? $datoFacturacion->tipo_documento ?: 'DNI') : null,
            'numero_documento' => $tipoPersona === 'fisica'
                ? $this->normalizeDocumentNumber((string) ($candidate['numero_documento'] ?? $datoFacturacion->numero_documento))
                : null,
            'cuit' => $candidate['id_persona'] ?? $datoFacturacion->cuit,
            'afip_id_persona' => $candidate['id_persona'] ?? $datoFacturacion->afip_id_persona,
            'condicion_iva' => $this->mapFiscalCondition((string) ($candidate['condicion_iva'] ?? $datoFacturacion->condicion_iva)),
            'condicion_iva_receptor_id' => $candidate['condicion_iva_receptor_id'] ?? $datoFacturacion->condicion_iva_receptor_id,
            'afip_estado_clave' => $candidate['estado_clave'] ?? $datoFacturacion->afip_estado_clave,
            'afip_ultima_consulta_at' => $consulta->consultado_at ?: now(),
            'afip_datos' => $candidate,
        ];

        foreach ($domicilioFields as $field => $value) {
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        $datoFacturacion->update($payload);
    }

    private function candidateFromFiscalProfile(array $profile, DatoFacturacion $datoFacturacion): array
    {
        return [
            'id_persona' => (string) ($profile['id_persona'] ?? $datoFacturacion->cuit),
            'tipo_clave' => $profile['tipo_clave'] ?? null,
            'estado_clave' => $profile['estado_clave'] ?? null,
            'tipo_persona' => $profile['tipo_persona'] ?? null,
            'tipo_documento' => $datoFacturacion->tipo_documento,
            'numero_documento' => $datoFacturacion->numero_documento,
            'nombre' => $profile['nombre'] ?? null,
            'apellido' => $profile['apellido'] ?? null,
            'razon_social' => $profile['razon_social'] ?? null,
            'nombre_completo' => $profile['nombre_completo'] ?? null,
            'domicilios' => $profile['domicilios'] ?? [],
            'match_score' => 100,
            'a13_error' => null,
            'a13' => null,
            'constancia' => $profile,
            'condicion_iva' => $profile['condicion_iva'] ?? null,
            'condicion_iva_receptor_id' => $profile['condicion_iva_receptor_id'] ?? null,
            'tipo_comprobante_sugerido' => $profile['tipo_comprobante_sugerido'] ?? null,
            'caracterizaciones' => $profile['caracterizaciones'] ?? [],
            'actividades' => $profile['actividades'] ?? [],
            'impuestos' => $profile['impuestos'] ?? [],
            'regimenes' => $profile['regimenes'] ?? [],
            'categorias' => $profile['categorias'] ?? [],
            'relaciones' => $profile['relaciones'] ?? [],
            'errores_constancia_detalle' => $profile['errores'] ?? [],
            'constancia_error' => null,
        ];
    }

    private function selectCandidate(array $candidates, DatoFacturacion $datoFacturacion): ?array
    {
        $expectedId = $this->onlyDigits((string) ($datoFacturacion->afip_id_persona ?: $datoFacturacion->cuit));

        if ($expectedId !== '') {
            $match = collect($candidates)->first(fn (array $candidate): bool => (
                $this->onlyDigits((string) ($candidate['id_persona'] ?? '')) === $expectedId
            ));

            if ($match) {
                return $match;
            }
        }

        return collect($candidates)
            ->filter(fn (array $candidate): bool => ! ($candidate['a13_error'] ?? null) && ! ($candidate['constancia_error'] ?? null))
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['match_score'] ?? 0))
            ->first();
    }

    private function statusForCandidates(array $candidates): string
    {
        if ($candidates === []) {
            return 'sin_resultados';
        }

        $hasErrors = collect($candidates)->contains(
            fn (array $candidate): bool => (bool) ($candidate['a13_error'] ?? $candidate['constancia_error'] ?? false)
        );

        return $hasErrors ? 'parcial' : 'ok';
    }

    private function collectField(array $candidates, string $field): array
    {
        return collect($candidates)
            ->flatMap(fn (array $candidate): array => $candidate[$field] ?? [])
            ->values()
            ->all();
    }

    private function collectRaw(array $candidates, string $path): array
    {
        return collect($candidates)
            ->map(fn (array $candidate): mixed => data_get($candidate, $path))
            ->filter()
            ->values()
            ->all();
    }

    private function collectErrors(array $candidates): array
    {
        return collect($candidates)
            ->flatMap(function (array $candidate): array {
                return collect([
                    'a13' => $candidate['a13_error'] ?? null,
                    'constancia' => $candidate['constancia_error'] ?? null,
                ])
                    ->filter()
                    ->map(fn (string $message, string $service): array => [
                        'service' => $service,
                        'id_persona' => $candidate['id_persona'] ?? null,
                        'message' => $message,
                    ])
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    private function fiscalAddressFields(array $domicilios): array
    {
        $domicilio = collect($domicilios)
            ->first(fn (mixed $item): bool => is_array($item) && Str::contains(Str::upper((string) ($item['tipoDomicilio'] ?? '')), 'FISCAL'))
            ?: collect($domicilios)->first(fn (mixed $item): bool => is_array($item) && Str::contains((string) ($item['origen'] ?? ''), 'constancia'))
            ?: collect($domicilios)->first(fn (mixed $item): bool => is_array($item));

        if (! is_array($domicilio)) {
            return [];
        }

        $parsedAddress = $this->splitDireccion((string) ($domicilio['direccion'] ?? ''));

        return [
            'provincia' => $domicilio['descripcionProvincia'] ?? $domicilio['provincia'] ?? null,
            'localidad' => $domicilio['localidad'] ?? null,
            'codigo_postal' => $domicilio['codPostal'] ?? $domicilio['codigoPostal'] ?? null,
            'calle' => $domicilio['calle'] ?? $parsedAddress['calle'] ?? null,
            'numero' => isset($domicilio['numero']) ? (string) $domicilio['numero'] : ($parsedAddress['numero'] ?? null),
            'piso' => $domicilio['piso'] ?? null,
            'departamento' => $domicilio['oficinaDptoLocal'] ?? $domicilio['departamento'] ?? null,
        ];
    }

    private function splitDireccion(string $value): array
    {
        $address = trim($value);

        if ($address === '') {
            return ['calle' => null, 'numero' => null];
        }

        if (! preg_match('/^(.*?)[\s,]+(\d+[a-zA-Z]?)$/', $address, $matches)) {
            return ['calle' => $address, 'numero' => null];
        }

        return [
            'calle' => trim($matches[1]),
            'numero' => trim($matches[2]),
        ];
    }

    private function resolveTipoPersona(array $candidate, DatoFacturacion $datoFacturacion): string
    {
        $type = Str::of((string) ($candidate['tipo_persona'] ?? ''))->ascii()->upper()->toString();

        if (Str::contains($type, 'JURIDICA')) {
            return 'juridica';
        }

        if (Str::contains($type, 'FISICA')) {
            return 'fisica';
        }

        $cuit = $this->onlyDigits((string) ($candidate['id_persona'] ?? $datoFacturacion->cuit));

        return strlen($cuit) === 11 && (int) substr($cuit, 0, 2) >= 30 ? 'juridica' : 'fisica';
    }

    private function mapFiscalCondition(string $condition): string
    {
        $normalized = Str::of($condition)->ascii()->lower()->toString();

        if (Str::contains($normalized, 'monotributo')) {
            return 'Monotributo';
        }

        if (Str::contains($normalized, 'responsable inscripto')) {
            return 'Responsable inscripto';
        }

        if (Str::contains($normalized, 'exento')) {
            return 'Exento';
        }

        if (Str::contains($normalized, 'no alcanzado')) {
            return 'No alcanzado';
        }

        return 'Consumidor final';
    }

    private function fiscalName(array $candidate): string
    {
        return (string) ($candidate['razon_social'] ?? $candidate['nombre_completo'] ?? '');
    }

    private function nameForLookup(DatoFacturacion $datoFacturacion): string
    {
        return trim((string) ($datoFacturacion->nombre_completo ?: $datoFacturacion->razon_social));
    }

    private function canConsultAfip(): bool
    {
        return (bool) config('afip.enabled')
            && (string) config('afip.cuit') !== '';
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeDocumentNumber(string $value): string
    {
        $normalized = ltrim($this->onlyDigits($value), '0');

        return $normalized === '' ? '0' : $normalized;
    }
}
