<?php

namespace Tests\Unit\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use App\Services\UltronStudio\StudioPublishing;
use App\Services\UltronStudio\StudioVersionStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD de las PUERTAS de la publicación (contrato H5 §A5 y Enmienda 1) con
 * la sección `gates` de `tests/fixtures/ultron-studio/publication-cases.json`,
 * sobre las puertas PURAS de {@see StudioPublications} y {@see StudioPublishing}:
 * con los hechos del caso, la primera puerta que rechaza es la del fixture, con
 * su código, su estado HTTP y su carga (P-3).
 *
 *  - Publicar: las llaves (paso 2, {@see StudioPublishing::keyRefusalFor()}) y
 *    los pasos 5–11 ({@see StudioPublications::publishRefusal()}).
 *  - Promover: después de los cuatro ojos, la llave del motor, el estado y la
 *    integridad, y después la evidencia, el freno y la ventana.
 *  - Revertir: el estado.
 *
 * Lo que necesita a la persona, la base o la cadena (persona, permisos,
 * reintento, versión inexistente, cuatro ojos, la restauración y la cabeza de
 * Legacy) lo prueban, con estado real, `UltronStudioPublication*Test` por
 * HTTP. Aquí se comprueba además que todo caso cae en uno de los dos lados:
 * ninguno se queda sin probar.
 */
class StudioPublicationGatesParityTest extends TestCase
{
    /** Los códigos que deciden las puertas puras, por acción. */
    private const PURE = [
        'publish' => ['invalid_transition', 'integrity_mismatch', 'revalidation_blocked', 'revalidation_changed', 'lab_verdict_required',
            'engine_value_mismatch', 'engine_reading_unavailable', 'no_effect_not_acknowledged', 'preview_stale',
            'engine_has_no_canary', 'engine_braked', 'canary_in_progress', 'outside_publication_window', null],
        'promote' => ['scope_not_allowed', 'invalid_transition', 'integrity_mismatch', 'canary_evidence_insufficient', 'evidence_changed',
            'engine_braked', 'outside_publication_window', null],
        'rollback' => ['not_active_publication'],
        'legacy' => [],
    ];

    /** Los que solo se prueban por HTTP, con estado real (y las llaves, también aquí). */
    private const HTTP_ONLY = ['person_required', 'forbidden', 'publishing_disabled', 'engine_not_reading_versions', 'replayed', 'client_request_reused',
        'version_not_found', 'four_eyes_required', 'restore_integrity_mismatch', 'already_legacy', null];

    /** Lo que declara la línea base en los cuatro `must_match`. */
    private const DECLARED = ['intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false, 'governance.enabled' => true, 'governance.canaryOnly' => true];

    private static ?array $fixture = null;

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/publication-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function gateCases(): iterable
    {
        foreach (self::fixture()['gates'] as $caso) {
            yield $caso['name'] => [$caso];
        }
    }

    public function test_los_71_casos_caen_en_las_puertas_puras_o_en_las_de_http(): void
    {
        $puras = 0;
        foreach (self::fixture()['gates'] as $caso) {
            $codigo = $caso['expected']['code'];
            $this->assertTrue(in_array($codigo, self::PURE[$caso['kind']], true) || in_array($codigo, self::HTTP_ONLY, true), $caso['name']);
            $puras += in_array($codigo, self::PURE[$caso['kind']], true) && ! self::earlierThanPure($caso) ? 1 : 0;
        }
        $this->assertCount(71, self::fixture()['gates']);
        $this->assertGreaterThanOrEqual(40, $puras, 'la mayoría de los casos los decide una puerta pura');
    }

    #[DataProvider('gateCases')]
    public function test_la_primera_puerta_que_rechaza_es_la_del_fixture(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];

        // Las llaves (paso 2): puras también.
        if (in_array($e['code'], [StudioPublishing::CODE_DISABLED, StudioPublishing::CODE_ENGINE_NOT_READING], true)) {
            $this->assertSame($e['code'], StudioPublishing::keyRefusalFor($caso['kind'], $f['studioPublishingEnabled'], $f['engineScope'])['code']);

            return;
        }
        if (self::earlierThanPure($caso)) {
            // Persona, permiso, reintento, versión, cuatro ojos, restauración o
            // cabeza de Legacy: con estado real, en las pruebas Feature.
            $this->assertContains($e['code'], self::HTTP_ONLY);

            return;
        }

        $rechazo = match ($caso['kind']) {
            'publish' => self::publish($f),
            'promote' => self::promote($f),
            'rollback' => StudioPublications::rollbackStateRefusal(StudioVersionStatus::from($f['version']['status'])),
            default => null,
        };

        if ($e['code'] === null) {
            $this->assertNull($rechazo, $rechazo?->errorCode ?? '');

            return;
        }
        $this->assertInstanceOf(UltronStudioException::class, $rechazo);
        $this->assertSame([$e['status'], $e['code']], [$rechazo->status, $rechazo->errorCode]);
        foreach ($e['payload'] ?? [] as $clave => $valor) {
            $this->assertSame($valor, $rechazo->payload[$clave] ?? null, $clave);
        }
    }

    /** ¿Lo decide una puerta anterior a las puras (o la base)? */
    private static function earlierThanPure(array $caso): bool
    {
        $f = $caso['facts'];
        $codigo = $caso['expected']['code'];
        if (in_array($codigo, ['person_required', 'forbidden', 'replayed', 'client_request_reused', 'version_not_found', 'four_eyes_required', 'restore_integrity_mismatch', 'already_legacy'], true)) {
            return true;
        }

        // Los que pasan todo: en revertir, la restauración; en Legacy, la cabeza.
        return in_array($caso['kind'], ['rollback', 'legacy'], true) && ($codigo === null || $caso['kind'] === 'legacy')
            || ($caso['kind'] === 'rollback' && ($f['restore'] ?? null) !== null);
    }

    private static function publish(array $f): ?UltronStudioException
    {
        $clasificacion = [
            'mustMatch' => array_map(static fn (string $id): array => ['param' => $id, 'versionValue' => self::DECLARED[$id], 'engineValue' => self::DECLARED[$id], 'ok' => true], array_keys(D::MUST_MATCH)),
            'matches' => [],
            'noEffect' => $f['classification']['noEffect'],
            'mismatches' => $f['classification']['mismatches'],
            'engineReadingUnavailable' => $f['classification']['engineReadingUnavailable'],
        ];
        $lab = $f['labGate'];
        $ventana = StudioPublishing::windowFor($f['window']);
        $h = [
            'canPublish' => true,
            'status' => StudioVersionStatus::from($f['version']['status']),
            'fourEyes' => ! $f['actor']['isAuthor'],
            'integrity' => $f['version']['integrityOk'],
            'revalidation' => $f['revalidation'],
            'findings' => [],
            'warnings' => [],
            'labGate' => ['mode' => $lab['mode'], 'ok' => $lab['mode'] === D::LAB_WAIVED || $lab['latest'] === 'approved', 'latest' => $lab['latest']],
            'configs' => StudioCatalog::baseline()['configs'],
            'classification' => $clasificacion,
            'previewHash' => $f['previewHash']['current'],
            'engineCanary' => $f['engineCanaryConfigured'],
            'brake' => $f['brakeEngaged'] ? 'engaged' : 'released',
            'otherCanary' => $f['otherVersionInCanary'],
            'window' => ['open' => StudioPublishing::windowOpenFor($ventana, CarbonImmutable::parse($f['now'])), 'label' => $ventana['label'] ?? null],
            'keys' => ['studio' => $f['studioPublishingEnabled'], 'engine' => $f['engineScope']],
        ];

        return StudioPublications::publishRefusal($h, $f['acknowledgedNoEffect'], $f['previewHash']['sent'], '11111111-1111-4111-8111-111111111111', str_repeat('a', 64));
    }

    private static function promote(array $f): ?UltronStudioException
    {
        $estado = StudioPublications::promoteStateRefusal($f['engineScope'], StudioVersionStatus::from($f['version']['status']), $f['version']['integrityOk']);
        if ($estado !== null) {
            return $estado;
        }
        $ventana = StudioPublishing::windowFor($f['window']);

        return StudioPublications::promoteEvidenceRefusal(
            ['hours' => 0, 'passes' => $f['evidence']['passes'], 'failing' => $f['evidence']['failing'], 'evidenceHash' => $f['evidenceHash']['current']],
            ['passes' => $f['evidence']['passes']],
            $f['evidenceHash']['sent'],
            $f['brakeEngaged'] ? 'engaged' : 'released',
            StudioPublishing::windowOpenFor($ventana, CarbonImmutable::parse($f['now'])),
            $ventana['label'] ?? null,
        );
    }
}
