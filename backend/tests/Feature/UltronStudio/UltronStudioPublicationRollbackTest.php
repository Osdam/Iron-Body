<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REVERTIR Y VOLVER A LEGACY (contrato H5 §A2, §A5.3, §A5.4, §A8 y Enmienda 1,
 * Q-2), contra el servidor de verdad:
 *
 *  - cada fila de A2 de este lado: CANARY → ROLLED_BACK (la cadena declara el
 *    resto en las dos partes); PUBLISHED → ROLLED_BACK con la anterior RETIRED →
 *    PUBLISHED (o Legacy si no había); con OTRA versión en canario se conserva
 *    su parte (Q-2); Legacy: CANARY → ROLLED_BACK y PUBLISHED → RETIRED;
 *  - una restauración que no verifica (la versión o la entrada de la que
 *    vuelve, alteradas) es 409 `restore_integrity_mismatch` y Legacy sigue
 *    siendo la salida;
 *  - sin llaves, sin cuatro ojos y sin ventana: es el sentido seguro;
 *  - escritor → lector: después de cada paso, el LECTOR REAL del motor aplica
 *    lo que toca (la parte anterior, o Legacy);
 *  - y las 13 puertas de revertir y Legacy del fixture del contrato, por HTTP.
 */
class UltronStudioPublicationRollbackTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private static ?array $fixture = null;

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private static function at(string $iso): void
    {
        Carbon::setTestNow(Carbon::parse($iso)->utc());
        CarbonImmutable::setTestNow(CarbonImmutable::parse($iso)->utc());
    }

    /** Publicar y promover una versión nueva (con su evidencia: tres turnos y 25 h). */
    private function published(string $label): StudioVersion
    {
        $v = $this->approvedVersion($this->ana, $this->root, $label);
        $this->publishAs($this->root, $v->uuid)->assertCreated();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::at(CarbonImmutable::now()->addHours(25)->format('Y-m-d\TH:i:s\Z'));
        $this->freshSessions();
        $this->promoteAs($this->root, $v->uuid)->assertCreated();

        return $v->fresh();
    }

    public function test_revertir_el_canario_vuelve_al_resto_en_las_dos_partes(): void
    {
        $v1 = $this->published('Publicada');
        $v2 = $this->approvedVersion($this->ana, $this->root, 'En canario');
        $this->publishAs($this->root, $v2->uuid)->assertCreated();
        $this->assertSame(['canary' => $v2->uuid, 'rest' => $v1->uuid], $this->headVersions());

        $r = $this->rollbackAs($this->root, $v2->uuid, 'El canario contestó con un enlace viejo')->assertCreated();
        $r->assertJsonPath('data.action', 'rollback')
            ->assertJsonPath('data.version', ['uuid' => $v2->uuid, 'label' => 'En canario'])
            ->assertJsonPath('data.reason', 'El canario contestó con un enlace viejo')
            ->assertJsonPath('data.labGate', null);

        $this->assertSame(['ROLLED_BACK', 'PUBLISHED'], [$v2->fresh()->status, $v1->fresh()->status]);
        $this->assertSame(['canary' => $v1->uuid, 'rest' => $v1->uuid], $this->headVersions());
        $entrada = $this->chainHead();
        $this->assertNull($entrada->restores_publication_id, 'revertir el canario no restaura nada');
        $this->assertSame($v2->id, $entrada->version_id);
        $evento = StudioAuditEvent::query()->where('event', 'version.rolled_back')->sole();
        $this->assertSame([$v2->uuid, 'CANARY', 'ROLLED_BACK'], [$evento->subject_uuid, $evento->from_status, $evento->to_status]);
        $this->assertSame(['entry' => $entrada->uuid, 'seq' => $entrada->seq, 'reason' => 'El canario contestó con un enlace viejo'], $evento->diff);
        // El motor: el canario vuelve a la publicada.
        $this->assertSame(['applied', 'canary', $v1->uuid], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['part'], $this->engineStamp(self::CANARY_ID)['version']]);
        $this->assertChainMatchesStates();
        // ROLLED_BACK es terminal.
        $this->rollbackAs($this->root, $v2->uuid)->assertStatus(409)->assertJsonPath('code', 'not_active_publication')->assertJsonPath('currentStatus', 'ROLLED_BACK');
        $this->publishAs($this->root, $v2->uuid)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_revertir_la_publicada_restaura_la_anterior_que_quedo_sustituida(): void
    {
        $v1 = $this->published('Primera');
        $v2 = $this->published('Segunda');
        $this->assertSame(['RETIRED', 'PUBLISHED'], [$v1->fresh()->status, $v2->fresh()->status]);

        $this->rollbackAs($this->root, $v2->uuid, 'La segunda vende peor')->assertCreated();

        $this->assertSame(['PUBLISHED', 'ROLLED_BACK'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertSame(['canary' => $v1->uuid, 'rest' => $v1->uuid], $this->headVersions());
        // La parte que vuelve es la de la entrada anterior a la promoción de V2.
        $promocionV2 = StudioPublication::query()->where('version_id', $v2->id)->where('action', 'promote')->sole();
        $this->assertSame($promocionV2->previous_publication_id, $this->chainHead()->restores_publication_id);
        $restaurada = StudioAuditEvent::query()->where('event', 'version.restored')->sole();
        $this->assertSame([$v1->uuid, 'RETIRED', 'PUBLISHED'], [$restaurada->subject_uuid, $restaurada->from_status, $restaurada->to_status]);
        $this->assertSame(['entry' => $this->chainHead()->uuid, 'seq' => $this->chainHead()->seq], $restaurada->diff);
        $this->assertSame('PUBLISHED', StudioAuditEvent::query()->where('event', 'version.rolled_back')->sole()->from_status);
        $this->assertSame($v1->uuid, $this->engineStamp(4242)['version'], 'el resto vuelve a la primera');
        $this->assertChainMatchesStates();
    }

    public function test_revertir_la_unica_publicada_deja_legacy(): void
    {
        $v1 = $this->published('Única');
        $this->rollbackAs($this->root, $v1->uuid)->assertCreated();

        $this->assertSame('ROLLED_BACK', $v1->fresh()->status);
        $this->assertSame(['canary' => null, 'rest' => null], $this->headVersions());
        $this->assertSame('legacy', $this->engineStamp(self::CANARY_ID)['state']);
        $this->assertSame('legacy', $this->engineStamp(4242)['state']);
        $this->assertChainMatchesStates();
        // Y ya no hay nada que dejar de declarar.
        $this->legacyAs($this->root)->assertStatus(409)->assertJsonPath('code', 'already_legacy');
    }

    public function test_revertir_la_publicada_con_otra_en_canario_conserva_su_parte(): void
    {
        // Q-2 (a): deshacer no depende de otro estado.
        $v1 = $this->published('Publicada');
        $v2 = $this->approvedVersion($this->ana, $this->root, 'En canario');
        $this->publishAs($this->root, $v2->uuid)->assertCreated();

        $this->rollbackAs($this->root, $v1->uuid)->assertCreated();

        $this->assertSame(['ROLLED_BACK', 'CANARY'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertSame(['canary' => $v2->uuid, 'rest' => null], $this->headVersions());
        $this->assertSame($v2->uuid, $this->engineStamp(self::CANARY_ID)['version'], 'el canario sigue con su versión');
        $this->assertSame('legacy', $this->engineStamp(4242)['state'], 'el resto, sin publicada, queda Legacy');
        $this->assertChainMatchesStates();
    }

    public function test_volver_a_legacy_deja_de_declarar_las_dos(): void
    {
        $v1 = $this->published('Publicada');
        $v2 = $this->approvedVersion($this->ana, $this->root, 'En canario');
        $this->publishAs($this->root, $v2->uuid)->assertCreated();

        $r = $this->legacyAs($this->root, 'Paramos de declarar hasta revisar')->assertCreated();
        $r->assertJsonPath('data.action', 'legacy')->assertJsonPath('data.version', null)->assertJsonPath('data.reason', 'Paramos de declarar hasta revisar');

        $this->assertSame(['RETIRED', 'ROLLED_BACK'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertSame(['canary' => null, 'rest' => null], $this->headVersions());
        $entrada = $this->chainHead();
        $this->assertSame([null, 'legacy'], [$entrada->version_id, $entrada->action]);
        $sistema = StudioAuditEvent::query()->where('event', 'system.publication_legacy')->sole();
        $this->assertSame(['system', $entrada->uuid], [$sistema->subject_type, $sistema->subject_uuid]);
        $this->assertSame(['entry' => $entrada->uuid, 'seq' => $entrada->seq, 'reason' => 'Paramos de declarar hasta revisar'], $sistema->diff);
        $this->assertSame(['entry' => $entrada->uuid, 'by' => 'legacy'], StudioAuditEvent::query()->where('event', 'version.retired')->sole()->diff);
        $this->assertSame($v2->uuid, StudioAuditEvent::query()->where('event', 'version.rolled_back')->sole()->subject_uuid);
        $this->assertSame('legacy', $this->engineStamp(self::CANARY_ID)['state']);
        $this->assertChainMatchesStates();

        $this->legacyAs($this->root)->assertStatus(409)->assertJsonPath('code', 'already_legacy');
    }

    public function test_sin_llaves_sin_cuatro_ojos_y_sin_ventana_revertir_y_legacy_siguen_abiertos(): void
    {
        $v1 = $this->published('Publicada');
        // La autora de la segunda tiene la llave de revertir.
        $autora = $this->personWith(['ultron.view', 'ultron.edit_draft', 'ultron.rollback'], 'Autora con llave de revertir');
        $v2 = $this->approvedVersion($autora, $this->root, 'De la autora, en canario');
        $this->publishAs($this->root, $v2->uuid)->assertCreated();

        // Las dos llaves apagadas, la ventana cerrada (21:00 en Bogotá) y quien revierte es la AUTORA.
        config(['ultron_studio.publishing_enabled' => false, 'marketing.ultron.studio_config' => 'off', 'ultron_studio.publishing.window' => '07:00-19:00']);
        self::at('2026-11-06T02:00:00Z');
        $this->freshSessions();
        $entradas = StudioPublication::query()->count();

        $this->rollbackAs($autora, $v2->uuid)->assertCreated();
        // Con el motor en `off` es una fila que nadie lee: el motor no sella.
        $this->assertNull($this->engineStamp(self::CANARY_ID));
        $this->legacyAs($autora)->assertCreated();
        $this->assertSame($entradas + 2, StudioPublication::query()->count());
        $this->assertSame(['RETIRED', 'ROLLED_BACK'], [$v1->fresh()->status, $v2->fresh()->status]);
        $this->assertChainMatchesStates();
        // Publicar, en cambio, sigue con su llave.
        $v3 = $this->approvedVersion($this->ana, $this->root, 'Tercera');
        $this->publishAs($this->root, $v3->uuid)->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
    }

    public function test_una_restauracion_que_no_verifica_es_409_y_legacy_es_la_salida(): void
    {
        // La versión que volvería, alterada: una cadena sembrada (firmada de
        // verdad) con V0 RETIRED de huella mala y V1 PUBLISHED.
        $v0 = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'RETIRED', str_repeat('f', 64));
        $v1 = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'PUBLISHED');
        $p0 = D::part($v0->uuid, str_repeat('f', 64), $v0->catalog_version, $this->root->id, $this->root->id, D::declared($v0->content['configs']));
        $p1 = $this->partOf($v1, $this->root->id, $this->root->id);
        $this->seedEntry(D::ACTION_PUBLISH, $v0, $p0, null);
        $this->seedEntry(D::ACTION_PROMOTE, $v0, $p0, $p0);
        $this->seedEntry(D::ACTION_PUBLISH, $v1, $p1, $p0);
        $this->seedEntry(D::ACTION_PROMOTE, $v1, $p1, $p1);
        $this->assertChainMatchesStates();

        $this->rollbackAs($this->root, $v1->uuid)->assertStatus(409)->assertJsonPath('code', 'restore_integrity_mismatch');
        $this->assertSame(['RETIRED', 'PUBLISHED', 4], [$v0->fresh()->status, $v1->fresh()->status, StudioPublication::query()->count()]);
        $this->assertSame(['code' => 'restore_integrity_mismatch'], StudioAuditEvent::query()->where('event', 'version.rollback_refused')->sole()->diff);

        // La salida: Legacy (no copia nada).
        $this->legacyAs($this->root)->assertCreated();
        $this->assertSame(['RETIRED', 'RETIRED'], [$v0->fresh()->status, $v1->fresh()->status]);
        $this->assertChainMatchesStates();
    }

    public function test_si_la_entrada_de_la_que_volveria_esta_alterada_tampoco_se_restaura(): void
    {
        $v0 = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'RETIRED');
        $v1 = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'PUBLISHED');
        $p0 = $this->partOf($v0, $this->root->id, $this->root->id);
        $p1 = $this->partOf($v1, $this->root->id, $this->root->id);
        $this->seedEntry(D::ACTION_PUBLISH, $v0, $p0, null);
        $this->seedEntry(D::ACTION_PROMOTE, $v0, $p0, $p0);
        // La entrada anterior a la promoción de V1, con la firma de otra clave.
        $this->seedEntry(D::ACTION_PUBLISH, $v1, $p1, $p0, ['signature' => str_repeat('1', 64)]);
        $this->seedEntry(D::ACTION_PROMOTE, $v1, $p1, $p1);

        $this->rollbackAs($this->root, $v1->uuid)->assertStatus(409)->assertJsonPath('code', 'restore_integrity_mismatch');
        $this->assertSame('PUBLISHED', $v1->fresh()->status);
    }

    public function test_revertir_y_legacy_son_idempotentes_y_validan_su_motivo(): void
    {
        $v1 = $this->published('Publicada');
        $this->rollbackAs($this->root, $v1->uuid, 'no')->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->studio('POST', "versions/{$v1->uuid}/rollback", $this->root, ['reason' => 'Sin identificador'])->assertStatus(422)->assertJsonValidationErrors(['clientRequestId']);
        $this->rollbackAs($this->root, $v1->uuid, str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->legacyAs($this->root, "Con\u{0000}nulo")->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $crid = (string) Str::uuid();
        $primera = $this->rollbackAs($this->root, $v1->uuid, 'Motivo de verdad', $crid)->assertCreated()->json('data');
        $this->rollbackAs($this->root, $v1->uuid, 'Motivo de verdad', $crid)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data', $primera);
        $this->rollbackAs($this->root, $v1->uuid, 'Otro motivo', $crid)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->legacyAs($this->root, 'Motivo de verdad', $crid)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');

        $v2 = $this->approvedVersion($this->ana, $this->root, 'Otra');
        $this->publishAs($this->root, $v2->uuid)->assertCreated();
        $legacy = (string) Str::uuid();
        $l = $this->legacyAs($this->root, 'Volver', $legacy)->assertCreated()->json('data');
        $this->legacyAs($this->root, 'Volver', $legacy)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data', $l);
    }

    // ── Las puertas del fixture, por HTTP ───────────────────────────────────

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/publication-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rollbackAndLegacyGateCases(): iterable
    {
        foreach (self::fixture()['gates'] as $caso) {
            if (in_array($caso['kind'], ['rollback', 'legacy'], true)) {
                yield $caso['name'] => [$caso];
            }
        }
    }

    public function test_el_fixture_trae_las_13_puertas_de_revertir_y_legacy(): void
    {
        $this->assertCount(13, iterator_to_array(self::rollbackAndLegacyGateCases()));
    }

    #[DataProvider('rollbackAndLegacyGateCases')]
    public function test_revertir_y_legacy_por_http_en_el_orden_del_fixture(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        $actor = $f['actor']['person'] ? $this->personWith($f['actor']['permissions'], 'Quien revierte') : null;
        $autor = ($f['actor']['isAuthor'] ?? false) && $actor !== null ? $actor : $this->ana;

        if ($caso['kind'] === 'rollback') {
            $uuid = $this->arrangeRollback($f, $autor);
        } else {
            $this->arrangeLegacy($f);
        }

        // Las llaves y la ventana del caso (revertir y Legacy no las miran).
        config([
            'ultron_studio.publishing_enabled' => $f['studioPublishingEnabled'],
            'marketing.ultron.studio_config' => $f['engineScope'],
            'ultron_studio.publishing.window' => $f['window'],
        ]);
        self::at($f['now']);
        $this->freshSessions();
        $cabeceras = $actor !== null ? $this->as($actor) : $this->sharedTokenHeaders();
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Motivo del caso del fixture'];
        $entradas = StudioPublication::query()->count();

        $r = $caso['kind'] === 'rollback'
            ? $this->json('POST', "/api/admin/ultron-studio/versions/{$uuid}/rollback", $cuerpo, $cabeceras)
            : $this->json('POST', '/api/admin/ultron-studio/publications/legacy', $cuerpo, $cabeceras);

        if ($e['status'] === null) {
            $this->assertSame(201, $r->status(), $r->getContent());
            $r->assertJsonPath('data.action', $caso['kind']);
            $this->assertSame($entradas + 1, StudioPublication::query()->count());
            $this->assertChainMatchesStates();

            return;
        }
        $this->assertSame($e['status'], $r->status(), $r->getContent());
        if ($e['code'] === 'person_required') {
            // El token compartido se para en la puerta del mapa (fixture P-10).
            $this->assertContains($r->json('code'), ['person_required', 'forbidden']);
        } else {
            $r->assertJsonPath('code', $e['code']);
        }
        foreach ($e['payload'] ?? [] as $clave => $valor) {
            $this->assertSame($valor, $r->json($clave), $r->getContent());
        }
        $this->assertSame($entradas, StudioPublication::query()->count(), 'un rechazo no escribe en la cadena');
    }

    /** El estado de un caso de revertir: la versión en el estado que dice, con la cadena que lo explica. */
    private function arrangeRollback(array $f, Admin $autor): string
    {
        if (! $f['version']['exists']) {
            return (string) Str::uuid();
        }
        $estado = $f['version']['status'];
        if ($estado === 'APPROVED' || $estado === 'RETIRED') {
            return $this->seededVersion($autor, $this->root, $this->baseline(), $estado)->uuid;
        }
        if ($estado === 'CANARY') {
            $v = $this->seededVersion($autor, $this->root, $this->baseline());
            $this->publishAs($this->root, $v->uuid)->assertCreated();

            return $v->uuid;
        }

        // PUBLISHED: sin anterior, con la anterior íntegra o alterada.
        $v = $this->seededVersion($autor, $this->root, $this->baseline(), 'PUBLISHED');
        $p = $this->partOf($v, $this->root->id, $this->root->id);
        $anterior = null;
        if (($f['restore'] ?? null) !== null) {
            $v0 = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'RETIRED', $f['restore']['integrityOk'] ? null : str_repeat('f', 64));
            $anterior = D::part($v0->uuid, (string) $v0->content_hash, $v0->catalog_version, $this->root->id, $this->root->id, D::declared($v0->content['configs']));
            $this->seedEntry(D::ACTION_PUBLISH, $v0, $anterior, null);
            $this->seedEntry(D::ACTION_PROMOTE, $v0, $anterior, $anterior);
        }
        $this->seedEntry(D::ACTION_PUBLISH, $v, $p, $anterior);
        $this->seedEntry(D::ACTION_PROMOTE, $v, $p, $p);
        $this->assertChainMatchesStates();

        return $v->uuid;
    }

    /** El estado de un caso de Legacy: una cabeza que declara, una que ya es Legacy o ninguna. */
    private function arrangeLegacy(array $f): void
    {
        if ($f['head'] === 'none') {
            return;
        }
        $v = $this->seededVersion($this->ana, $this->root, $this->baseline());
        $this->publishAs($this->root, $v->uuid)->assertCreated();
        if ($f['head'] === 'legacy') {
            $this->legacyAs($this->root)->assertCreated();
        }
    }
}
