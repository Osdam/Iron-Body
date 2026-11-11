<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioPublication;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;

/**
 * Las FORMAS de la publicación para las pruebas del CRM (contrato H5 §A7):
 * lo que exporta `ultron-studio:export-fixtures` a
 * `backend-publication.testing.ts`. Se hace con los MISMOS presentadores que
 * las respuestas ({@see StudioPublications}, {@see StudioPublishing},
 * {@see StudioCanaryEvidence}) sobre datos sintéticos fijos: sin base de datos,
 * sin ids de conversación y sin textos del canario.
 *
 * Vive entre los servicios y no en el comando: los comandos del Studio no
 * nombran nada del veredicto del laboratorio (`UltronStudioLabNoEngineEffectsTest`),
 * y la tabla de errores tiene que nombrar el código de la puerta del laboratorio.
 *
 * Enmienda M-1 (plan de motores §B.4 y §D): `effect` y `prompts` en la vista
 * previa (con la llave de prompts en `off` y encendida), `engine.prompts` en el
 * bloque del motor (apagada y con una traza `applied`), los vocabularios
 * nuevos y el 409 `prompts_compile_failed`. Las huellas de los paquetes son
 * las reales: las calcula el compilador del motor con la base del repositorio.
 */
final class StudioPublicationExport
{
    /**
     * Lo que viaja en `BACKEND_PUBLICATION`: vocabularios, códigos, topes y un
     * ejemplo de cada DTO de la publicación, hecho con los presentadores reales.
     *
     * Ejemplos con una cadena sintética: V0 publicada en canario (1) y
     * promovida (2); V1 publicada en canario (3) y revertida (4); vuelta a
     * Legacy (5). Las huellas efectivas son las de los textos firmados reales
     * de esas entradas.
     *
     * @return array<string,mixed>
     */
    public static function shapes(): array
    {
        $v0 = '00000000-0000-4000-8000-0000000000a0';
        $v1 = '11111111-1111-4111-8111-111111111111';
        $huellaV0 = str_repeat('b', 64);
        $huellaV1 = str_repeat('a', 64);
        $etiquetaV0 = 'Línea base publicada';
        $etiquetaV1 = 'Tono profesional (propuesta)';
        $declarados = ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false];
        $parteV0 = D::part($v0, $huellaV0, StudioCatalog::VERSION, 12, 7, $declarados);
        $parteV1 = D::part($v1, $huellaV1, StudioCatalog::VERSION, 12, 7, $declarados);
        $entradas = [
            1 => ['eeeeeeee-0000-4000-8000-000000000001', D::ACTION_PUBLISH, $parteV0, null, '2026-11-01T14:00:00Z', ['mode' => D::LAB_REQUIRED], null, $v0, $etiquetaV0],
            2 => ['eeeeeeee-0000-4000-8000-000000000002', D::ACTION_PROMOTE, $parteV0, $parteV0, '2026-11-02T15:00:00Z', ['mode' => null], null, $v0, $etiquetaV0],
            3 => ['eeeeeeee-0000-4000-8000-000000000003', D::ACTION_PUBLISH, $parteV1, $parteV0, '2026-11-03T15:04:05Z', ['mode' => D::LAB_WAIVED], 'Primera publicación con tono profesional', $v1, $etiquetaV1],
            4 => ['eeeeeeee-0000-4000-8000-000000000004', D::ACTION_ROLLBACK, $parteV0, $parteV0, '2026-11-04T09:00:00Z', ['mode' => null], 'El canario contestó con un enlace viejo', $v1, $etiquetaV1],
            5 => ['eeeeeeee-0000-4000-8000-000000000005', D::ACTION_LEGACY, null, null, '2026-11-04T10:00:00Z', ['mode' => null], 'Paramos de declarar hasta revisar', null, null],
        ];
        $dto = [];
        foreach ($entradas as $seq => [$uuid, $accion, $canario, $resto, $en, $puerta, $motivo, $version, $etiqueta]) {
            $json = D::effectiveJson($uuid, $seq, CarbonImmutable::parse($en), $canario, $resto);
            $fila = (new StudioPublication)->forceFill([
                'uuid' => $uuid,
                'seq' => $seq,
                'action' => $accion,
                'version_id' => $version === null ? null : $seq,
                'effective_hash' => D::effectiveHash($json),
                'lab_gate' => $puerta,
                'reason' => $motivo,
                'actor_name' => $seq % 2 === 0 ? 'Bea Revisora' : 'Root',
                'actor_role' => $seq % 2 === 0 ? 'Administrador' : 'Super Admin',
                'created_at' => CarbonImmutable::parse($en),
            ]);
            $dto[$seq] = StudioPublications::entryDto($fila, $version === null ? null : ['uuid' => $version, 'label' => $etiqueta]);
        }

        $cabeza = static fn (int $seq, bool $firma = true): array => [
            'uuid' => $dto[$seq]['uuid'], 'seq' => $seq, 'action' => $dto[$seq]['action'], 'at' => $dto[$seq]['at'], 'signatureOk' => $firma,
        ];
        $v0Ref = ['versionUuid' => $v0, 'label' => $etiquetaV0];
        $v1Ref = ['versionUuid' => $v1, 'label' => $etiquetaV1];
        $estados = [
            'applied' => StudioPublications::stateDto(StudioPublishing::SCOPE_CANARY, $cabeza(3), $v1Ref, $v0Ref, [], StudioPublications::STATE_APPLIED),
            'drift' => StudioPublications::stateDto(StudioPublishing::SCOPE_ALL, $cabeza(2), $v0Ref, $v0Ref, [
                ['param' => 'rules.paymentLinksEnabled', 'declared' => false, 'engine' => true],
                ['param' => 'intent.driver', 'declared' => 'entorno', 'engine' => null],
            ], StudioPublications::STATE_APPLIED),
            'legacy' => StudioPublications::stateDto(StudioPublishing::SCOPE_CANARY, $cabeza(5), null, null, [], StudioPublications::STATE_LEGACY),
            'fallback' => StudioPublications::stateDto(StudioPublishing::SCOPE_CANARY, $cabeza(4, false), null, null, [], StudioPublications::STATE_FALLBACK),
            'off' => StudioPublications::stateDto(StudioPublishing::SCOPE_OFF, null, null, null, [], StudioPublications::STATE_OFF),
        ];

        // La vista previa de V1: la línea base con el tono «profesional» y la
        // regla S-003 copiada, y lecturas del motor que coinciden.
        $configs = StudioCatalog::baseline()['configs'];
        $configs['persona']['tone'] = 'profesional';
        $lecturas = [
            'intent.driver' => ['available' => true, 'value' => 'openai'],
            'rules.paymentLinksEnabled' => ['available' => true, 'value' => false],
            'governance.enabled' => ['available' => true, 'value' => true],
            'governance.canaryOnly' => ['available' => true, 'value' => true],
        ];
        $clasificacion = D::classify($configs, [['code' => 'S-003']], StudioCatalog::baseline()['configs'], $lecturas, StudioCatalog::toArray());
        $hechos = static fn (array $lab, bool $llave): array => [
            'canPublish' => true,
            'status' => StudioVersionStatus::Approved,
            'fourEyes' => true,
            'integrity' => true,
            'revalidation' => 'ok',
            'labGate' => $lab,
            'configs' => $configs,
            'classification' => $clasificacion,
            'previewHash' => D::previewHash($v1, $huellaV1, StudioCatalog::VERSION, $clasificacion, ['mode' => $lab['mode'], 'latest' => $lab['latest']]),
            'engineCanary' => true,
            'brake' => 'released',
            'otherCanary' => false,
            'window' => ['open' => true, 'label' => '07:00–19:00'],
            'keys' => ['studio' => $llave, 'engine' => StudioPublishing::SCOPE_CANARY],
        ];
        $aprobada = ['mode' => D::LAB_REQUIRED, 'ok' => true, 'latest' => 'approved'];
        $sinVeredicto = ['mode' => D::LAB_REQUIRED, 'ok' => false, 'latest' => null];

        // Enmienda M-1: la misma V1 con la llave de prompts encendida y el
        // cambio de aceptación (G8: tono «profesional» y 200 caracteres), sobre
        // una cabeza que arrastra V0 en el resto (línea base, paquete Legacy).
        $configsPrompts = $configs;
        $configsPrompts['persona']['maxChars'] = 200;
        $clasificacionPrompts = D::classify($configsPrompts, [['code' => 'S-003']], StudioCatalog::baseline()['configs'], $lecturas, StudioCatalog::toArray(), true);
        $paqueteV1 = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($configsPrompts));
        $paqueteV0 = StudioPromptBundle::compile(StudioPromptBundle::governedFrom(StudioCatalog::baseline()['configs']));
        $hechosPrompts = [
            'key' => StudioEngineReadings::PROMPTS_CANARY,
            'compiled' => $paqueteV1,
            'carried' => ['version' => $v0, 'label' => $etiquetaV0, 'bundleHash' => $paqueteV0['prompts']['bundleHash'], 'baseline' => true],
            'error' => null,
        ];
        $huellaPrompts = D::previewHash($v1, $huellaV1, StudioCatalog::VERSION, $clasificacionPrompts, ['mode' => $aprobada['mode'], 'latest' => $aprobada['latest']], [
            'base' => StudioPromptBundle::base(),
            'bundleHash' => $paqueteV1['prompts']['bundleHash'],
            'carried' => ['bundleHash' => $paqueteV0['prompts']['bundleHash'], 'version' => $v0],
            'compiler' => StudioPromptBundle::compilerVersion(),
        ]);
        $vistaPrompts = StudioPublications::previewFrom($v1, $huellaV1, ['configs' => $configsPrompts, 'classification' => $clasificacionPrompts, 'previewHash' => $huellaPrompts, 'prompts' => $hechosPrompts] + $hechos($aprobada, true));
        $parteV1Prompts = D::partV2($parteV1, $paqueteV1['governed'], $paqueteV1['prompts']);
        $trazaPrompts = [
            'key' => StudioEngineReadings::PROMPTS_CANARY,
            'base' => StudioPromptBundle::base(),
            'compiler' => StudioPromptBundle::compilerVersion(),
            'legacyBundleHash' => StudioPromptBundle::legacyBundleHash(),
            'current' => StudioPromptBundle::offer(StudioEngineReadings::PROMPTS_CANARY, StudioPublishing::SCOPE_CANARY, true, ['state' => 'ok', 'reason' => null, 'contract' => D::CONTRACT_V2, 'part' => $parteV1Prompts]),
            // Tres turnos de la canaria: antes de publicar (Legacy), con V1
            // aplicada y después de revertirla. Solo vocabularios y recortes.
            'lastTurns' => [
                self::turn('2026-11-03T14:58Z', ['state' => 'legacy', 'version8' => null], ['mode' => 'legacy', 'reason' => 'part_legacy', 'version8' => null, 'bundle8' => null], ['mode' => 'legacy', 'bundle8' => substr(StudioPromptBundle::legacyBundleHash(), 0, 8), 'flowRev' => 'S1'], 'legacy'),
                self::turn('2026-11-03T15:10Z', ['state' => 'applied', 'version8' => substr($v1, 0, 8)], ['mode' => 'studio', 'reason' => null, 'version8' => substr($v1, 0, 8), 'bundle8' => substr($paqueteV1['prompts']['bundleHash'], 0, 8)], ['mode' => 'studio', 'bundle8' => substr($paqueteV1['prompts']['bundleHash'], 0, 8), 'flowRev' => 'S1'], 'applied'),
                self::turn('2026-11-04T09:05Z', ['state' => 'legacy', 'version8' => null], ['mode' => 'legacy', 'reason' => 'part_legacy', 'version8' => null, 'bundle8' => null], ['mode' => 'legacy', 'bundle8' => substr(StudioPromptBundle::legacyBundleHash(), 0, 8), 'flowRev' => 'S1'], 'legacy'),
            ],
        ];
        $trazaApagada = [
            'key' => StudioEngineReadings::PROMPTS_OFF,
            'base' => StudioPromptBundle::base(),
            'compiler' => StudioPromptBundle::compilerVersion(),
            'legacyBundleHash' => StudioPromptBundle::legacyBundleHash(),
            'current' => StudioPromptBundle::offer(StudioEngineReadings::PROMPTS_OFF, StudioPublishing::SCOPE_CANARY, true, ['state' => 'none', 'reason' => null, 'contract' => null, 'part' => null]),
            'lastTurns' => [],
        ];

        // La evidencia de V1 en canario: 26 h, cuatro turnos sellados, acta a cero.
        $medidas = static fn (int $turnos): array => [
            'since' => '2026-11-03T15:04:05Z',
            'turns' => $turnos,
            'stamped' => $turnos,
            'byState' => ['applied' => $turnos] + array_fill_keys(array_diff(StudioCanaryEvidence::BY_STATE, ['applied']), 0),
            'brakes' => ['count' => 0, 'engaged' => false],
            'acta' => ['exitCode' => 0, 'total' => 0, 'counts' => array_fill_keys(StudioCanaryMeasures::ACTA_COUNTERS, 0)],
            'recoveries' => 0,
            'drift' => [],
            'signatureOk' => true,
        ];
        $umbrales = ['minHours' => StudioPublishing::DEFAULT_MIN_HOURS, 'minTurns' => StudioPublishing::DEFAULT_MIN_TURNS];
        $ahora = CarbonImmutable::parse('2026-11-04T17:10:00Z');
        $evidencia = static fn (int $turnos): array => StudioPublications::evidenceDto(
            $v1,
            StudioCanaryEvidence::evidence($medidas($turnos), $umbrales),
            StudioCanaryEvidence::evaluate($medidas($turnos), $umbrales, $ahora),
        );

        // Los códigos de los rechazos de la publicación con su estado HTTP,
        // leídos de las propias excepciones.
        $errores = [];
        foreach ([
            UltronStudioException::publicationFourEyesRequired('publish'),
            UltronStudioException::publishingRefused(StudioPublishing::CODE_DISABLED, ''),
            UltronStudioException::publishingRefused(StudioPublishing::CODE_ENGINE_NOT_READING, ''),
            UltronStudioException::revalidationChanged([]),
            UltronStudioException::labVerdictRequired(null),
            UltronStudioException::engineValueMismatch([]),
            UltronStudioException::engineReadingUnavailable([]),
            UltronStudioException::noEffectNotAcknowledged([]),
            UltronStudioException::previewStale([]),
            UltronStudioException::engineHasNoCanary(),
            UltronStudioException::engineBraked(),
            UltronStudioException::canaryInProgress(),
            UltronStudioException::outsidePublicationWindow(null),
            UltronStudioException::scopeNotAllowed(),
            UltronStudioException::canaryEvidenceInsufficient([]),
            UltronStudioException::evidenceChanged([]),
            UltronStudioException::notActivePublication(StudioVersionStatus::Approved->value),
            UltronStudioException::restoreIntegrityMismatch(),
            UltronStudioException::alreadyLegacy(),
            UltronStudioException::publicationMoved(),
            UltronStudioException::publicationHeadInvalid(),
            UltronStudioException::promptsCompileFailed(),
        ] as $e) {
            $errores[$e->errorCode] = $e->status;
        }

        return [
            'version' => 1,
            'contract' => D::CONTRACT,
            'catalogVersion' => StudioCatalog::VERSION,
            'lifecycle' => StudioVersionStatus::lifecycle(),
            'actions' => [D::ACTION_PUBLISH, D::ACTION_PROMOTE, D::ACTION_ROLLBACK, D::ACTION_LEGACY],
            'envScopes' => StudioPublishing::SCOPES,
            'states' => [StudioPublications::STATE_APPLIED, StudioPublications::STATE_LEGACY, StudioPublications::STATE_OFF, StudioPublications::STATE_FALLBACK],
            'labGateModes' => [D::LAB_REQUIRED, D::LAB_WAIVED],
            'labLatest' => D::LAB_LATEST,
            'mustMatch' => array_keys(D::MUST_MATCH),
            'byState' => StudioCanaryEvidence::BY_STATE,
            'evidenceGates' => StudioCanaryEvidence::GATES,
            'actaCounters' => StudioCanaryMeasures::ACTA_COUNTERS,
            // Enmienda M-1.
            'contracts' => D::CONTRACTS,
            'effects' => [StudioPublications::EFFECT_DECLARATION, StudioPublications::EFFECT_PROMPTS],
            'paramClasses' => ['must_match', 'matches', 'no_effect', D::CLASS_PROMPT],
            'promptKeys' => [StudioEngineReadings::PROMPTS_OFF, StudioEngineReadings::PROMPTS_CANARY],
            'promptGoverned' => StudioPromptBundle::parameters(),
            'promptRoles' => StudioPromptBundle::roles(),
            'promptModes' => [StudioPromptBundle::MODE_STUDIO, StudioPromptBundle::MODE_LEGACY],
            'promptTurnsMax' => StudioPublications::PROMPT_TURNS,
            'engineMessagesPrompts' => StudioPublications::ENGINE_MESSAGES_PROMPTS,
            'errors' => $errores,
            'limits' => [
                'acknowledgedNoEffectMax' => StudioPublications::MAX_ACKNOWLEDGED,
                'noteMax' => StudioPublications::NOTE_MAX,
                'reasonMin' => StudioPublications::REASON_MIN,
                'reasonMax' => StudioPublications::REASON_MAX,
                'publicationsPerPageMax' => StudioPublications::PER_PAGE_MAX,
                'archivedRulesPerPageMax' => StudioRules::ARCHIVED_PER_PAGE_MAX,
                'evidencePerMinute' => StudioPublications::EVIDENCE_PER_MINUTE,
                'canaryMinHours' => [StudioCanaryEvidence::MIN_HOURS, StudioCanaryEvidence::MAX_HOURS],
                'canaryMinTurns' => [StudioCanaryEvidence::MIN_TURNS, StudioCanaryEvidence::MAX_TURNS],
            ],
            'examples' => [
                'engine' => [
                    'readsVersions' => StudioPublishing::ENGINE_READS_VERSIONS,
                    'activeVersion' => $v0,
                    'message' => StudioPublications::ENGINE_MESSAGES[StudioPublishing::SCOPE_CANARY],
                    'publication' => $estados['applied'],
                    'prompts' => $trazaApagada,
                ],
                // Enmienda M-1: el bloque con la llave de prompts encendida.
                'enginePrompts' => [
                    'readsVersions' => StudioPublishing::ENGINE_READS_VERSIONS,
                    'activeVersion' => $v0,
                    'message' => StudioPublications::engineMessage(StudioPublishing::SCOPE_CANARY, StudioEngineReadings::PROMPTS_CANARY),
                    'publication' => $estados['applied'],
                    'prompts' => $trazaPrompts,
                ],
                'publishing' => [
                    'disabled' => StudioPublishing::statusFor(false, StudioPublishing::SCOPE_OFF),
                    'engineOff' => StudioPublishing::statusFor(true, StudioPublishing::SCOPE_OFF),
                    'canary' => StudioPublishing::statusFor(true, StudioPublishing::SCOPE_CANARY),
                    'all' => StudioPublishing::statusFor(true, StudioPublishing::SCOPE_ALL),
                ],
                'publication' => $estados,
                'entries' => [
                    'publish' => $dto[3],
                    'promote' => $dto[2],
                    'rollback' => $dto[4],
                    'legacy' => $dto[5],
                ],
                'preview' => StudioPublications::previewFrom($v1, $huellaV1, $hechos($aprobada, true)),
                'previewBlocked' => StudioPublications::previewFrom($v1, $huellaV1, $hechos($sinVeredicto, false)),
                'previewPrompts' => $vistaPrompts,
                'evidence' => $evidencia(4),
                'evidenceInsufficient' => $evidencia(2),
            ],
        ];
    }

    /**
     * Un turno de `status.engine.prompts.lastTurns` (la forma de
     * `StudioCanaryMeasures::promptTurns()`).
     *
     * @param  array<string,mixed>|null  $declared
     * @param  array<string,mixed>|null  $offered
     * @param  array<string,mixed>|null  $observed
     * @return array<string,mixed>
     */
    private static function turn(string $at, ?array $declared, ?array $offered, ?array $observed, ?string $state): array
    {
        return ['at' => $at, 'declared' => $declared, 'offered' => $offered, 'observed' => $observed, 'state' => $state];
    }
}
