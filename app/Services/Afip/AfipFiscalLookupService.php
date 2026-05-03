<?php

namespace App\Services\Afip;

class AfipFiscalLookupService
{
    public function __construct(
        private readonly AfipPadronA13Client $padronA13,
        private readonly AfipConstanciaInscripcionClient $constancia,
    ) {}

    public function findCandidatesByDocument(string $document, ?string $name = null): array
    {
        return collect($this->padronA13->findCandidatesByDocument($document, $name))
            ->map(function (array $candidate): array {
                if ($candidate['a13_error'] ?? null) {
                    return array_merge($candidate, [
                        'constancia' => null,
                        'condicion_iva' => null,
                        'condicion_iva_receptor_id' => null,
                        'tipo_comprobante_sugerido' => null,
                        'caracterizaciones' => [],
                        'actividades' => [],
                        'impuestos' => [],
                        'regimenes' => [],
                        'categorias' => [],
                        'relaciones' => [],
                        'errores_constancia_detalle' => [],
                        'constancia_error' => 'No se consulta constancia porque A13 no devolvio persona activa.',
                    ]);
                }

                try {
                    $profile = $this->constancia->fiscalProfile((string) $candidate['id_persona']);

                    return array_merge($candidate, [
                        'constancia' => $profile,
                        'domicilios' => collect($candidate['domicilios'] ?? [])
                            ->merge($profile['domicilios'])
                            ->values()
                            ->all(),
                        'condicion_iva' => $profile['condicion_iva'],
                        'condicion_iva_receptor_id' => $profile['condicion_iva_receptor_id'],
                        'tipo_comprobante_sugerido' => $profile['tipo_comprobante_sugerido'],
                        'caracterizaciones' => $profile['caracterizaciones'],
                        'actividades' => $profile['actividades'],
                        'impuestos' => $profile['impuestos'],
                        'regimenes' => $profile['regimenes'],
                        'categorias' => $profile['categorias'],
                        'relaciones' => $profile['relaciones'],
                        'errores_constancia_detalle' => $profile['errores'],
                        'constancia_error' => null,
                    ]);
                } catch (\Throwable $exception) {
                    return array_merge($candidate, [
                        'constancia' => null,
                        'condicion_iva' => null,
                        'condicion_iva_receptor_id' => null,
                        'tipo_comprobante_sugerido' => null,
                        'caracterizaciones' => [],
                        'actividades' => [],
                        'impuestos' => [],
                        'regimenes' => [],
                        'categorias' => [],
                        'relaciones' => [],
                        'errores_constancia_detalle' => [],
                        'constancia_error' => $exception->getMessage(),
                    ]);
                }
            })
            ->values()
            ->all();
    }
}
