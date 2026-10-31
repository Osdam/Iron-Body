<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronAbort;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PUBLICAR Y PROMOVER (contrato H5 §A2, §A5.1, §A5.2, §A8 y Enmienda 1), contra
 * el servidor de verdad:
 *
 *  - cada fila de A2 de este lado (APPROVED → CANARY; CANARY → PUBLISHED con la
 *    PUBLISHED anterior → RETIRED), con la entrada FIRMADA de la cadena, el
 *    evento del libro y la invariante cadena ↔ estados;
 *  - escritor → lector: lo que escribe el Studio lo lee el LECTOR REAL del
 *    motor (`UltronPublishedConfig`) como `applied`, con la parte que toca;
 *  - la idempotencia, el libro en la misma transacción, las carreras (la de la
 *    cabeza y la del estado, resueltas fuera de la transacción abortada) y la
 *    cabeza alterada, que no se copia;
 *  - y las 58 puertas de publicar y promover del fixture del contrato
 *    (`publication-cases.json`, sección `gates`), en su orden, POR HTTP: el
 *    estado de cada caso se construye de verdad (personas y permisos, llaves,
 *    versiones, reseñas, veredictos del laboratorio, lecturas del motor, freno,
 *    reloj y ventana). Tres casos no se pueden construir por HTTP con el
 *    catálogo de hoy (ver {@see httpExpectation()}): responden la puerta
 *    anterior que lo impide, y su código del fixture lo prueba
 *    `StudioPublicationGatesParityTest` sobre las puertas puras.
 *
 * El reloj se fija con instantes UTC (memoria «Relojes de prueba en UTC»).
 */
class UltronStudioPublicationLifecycleTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Una huella que no es la de ninguna vista previa ni evidencia. */
    private const STALE = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

    private static ?array $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Muchas escrituras seguidas superan el cupo de 30/min, que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
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

    // ── A2: publicar ────────────────────────────────────────────────────────

    public function test_publicar_en_canario_firma_la_entrada_y_el_lector_del_motor_la_aplica(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $pub = $this->personWith(['ultron.view', 'ultron.publish'], 'Pablo Publica');
        $v = $this->approvedVersion($ana, $root, 'Tono profesional', ['persona' => ['tone' => 'profesional']]);

        $vista = $this->previewOf($pub, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame(['persona.tone'], $vista['noEffect']);
        $r = $this->studio('POST', "versions/{$v->uuid}/publish", $pub, [
            'clientRequestId' => $crid = (string) Str::uuid(),
            'previewHash' => $vista['previewHash'],
            'acknowledgedNoEffect' => ['persona.tone'],
            'note' => 'Primera publicación',
        ])->assertCreated();

        // La entrada (PublicationEntryDto).
        $fila = StudioPublication::query()->sole();
        $r->assertJsonPath('ok', true)->assertJsonPath('data', [
            'uuid' => $fila->uuid,
            'seq' => 1,
            'action' => 'publish',
            'version' => ['uuid' => $v->uuid, 'label' => 'Tono profesional'],
            'effectiveHash' => $fila->effective_hash,
            'shortHash' => substr($fila->effective_hash, 0, 8),
            'labGate' => ['mode' => 'waived'],
            'actor' => ['name' => 'Pablo Publica', 'role' => $pub->role],
            'reason' => 'Primera publicación',
            'at' => $fila->created_at->toIso8601String(),
        ]);
        $this->assertSame('CANARY', $v->fresh()->status);

        // La fila: firmada con la firma del motor sobre los bytes exactos.
        $this->assertSame([1, 'publish', $v->id, null, null, $crid], [$fila->seq, $fila->action, $fila->version_id, $fila->previous_publication_id, $fila->restores_publication_id, $fila->client_request_id]);
        $this->assertSame(UltronPublishedConfig::CONTRACT, $fila->engine_contract);
        $this->assertSame(hash('sha256', $fila->effective_json), $fila->effective_hash);
        $this->assertSame(UltronPublishedConfig::signatureFor($fila->effective_json), $fila->signature);
        $this->assertSame(['mode' => 'waived'], $fila->lab_gate);
        $this->assertSame(['noEffect' => ['persona.tone'], 'previewHash' => $vista['previewHash']], $fila->acknowledged);
        $this->assertSame([$pub->id, 'Pablo Publica'], [$fila->actor_admin_id, $fila->actor_name]);
        // Los bytes: el texto canónico de §A4.2, con las identidades dentro (Q-3) y sin etiquetas ni notas.
        $parte = D::part($v->uuid, $v->content_hash, $v->catalog_version, (int) $v->review->reviewer_admin_id, $pub->id, D::declared($v->content['configs']));
        $this->assertSame(D::effectiveJson($fila->uuid, 1, $fila->created_at, $parte, null), $fila->effective_json);
        $this->assertSame($root->id, $parte['approvedBy']);
        foreach (['Tono profesional', 'Primera publicación', 'Pablo', (string) self::CANARY_ID] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $fila->effective_json);
        }

        // El libro, en la misma transacción.
        $evento = StudioAuditEvent::query()->where('event', 'version.published')->sole();
        $this->assertSame([$v->uuid, 'APPROVED', 'CANARY'], [$evento->subject_uuid, $evento->from_status, $evento->to_status]);
        $this->assertSame(['entry' => $fila->uuid, 'seq' => 1, 'effectiveHash' => $fila->effective_hash, 'labGate' => ['mode' => 'waived'], 'noEffect' => ['persona.tone']], $evento->diff);

        // Escritor → lector: el motor la lee aplicada en el canario; con la
        // llave en `canary`, el resto queda fuera de alcance; con `all`, Legacy
        // (todavía no hay parte resto).
        $sello = $this->engineStamp(self::CANARY_ID);
        $this->assertSame(['v' => 1, 'env' => 'canary', 'state' => 'applied', 'entry' => $fila->uuid, 'seq' => 1, 'part' => 'canary', 'version' => $v->uuid, 'contentHash' => $v->content_hash, 'reason' => null], $sello);
        $this->assertSame('out_of_scope', $this->engineStamp(12345)['state']);
        config(['marketing.ultron.studio_config' => 'all']);
        $this->assertSame('legacy', $this->engineStamp(12345)['state']);
        config(['marketing.ultron.studio_config' => 'off']);
        $this->assertNull($this->engineStamp(self::CANARY_ID));

        $this->assertChainMatchesStates();
        // El detalle nombra la entrada; el historial también.
        $this->studio('GET', "versions/{$v->uuid}", $root)->assertOk()
            ->assertJsonPath('data.publications.0.uuid', $fila->uuid)
            ->assertJsonPath('data.status', 'CANARY')
            ->assertJsonPath('data.statusLabel', 'Canario');
        $this->studio('GET', 'publications', $root)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.action', 'publish');
    }

    public function test_publicar_con_la_puerta_del_laboratorio_requerida_registra_el_veredicto(): void
    {
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'], $this->previewOf($root, $v->uuid)['requirements']['labGate']);
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required')->assertJsonPath('latest', null);

        // Un veredicto con prompts viejos no basta; uno aprobado con los de hoy, sí.
        $this->labVerdict($v, 'approved', '2000-01-01.1');
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('latest', 'stale_prompts');
        $this->labVerdict($v, 'rejected');
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('latest', 'rejected');
        $veredicto = $this->labVerdict($v, 'approved');
        $this->publishAs($root, $v->uuid)->assertCreated()->assertJsonPath('data.labGate', ['mode' => 'required']);

        $gate = StudioPublication::query()->sole()->lab_gate;
        $this->assertSame('required', $gate['mode']);
        $this->assertSame($veredicto->id, $gate['verdictId']);
        $this->assertSame($veredicto->run->uuid, $gate['runUuid']);
        $this->assertSame(StudioLabPrompts::PROMPT_VERSION, $gate['promptVersion']);
    }

    // ── A2: promover ────────────────────────────────────────────────────────

    public function test_promover_pasa_la_misma_parte_al_resto_y_sustituye_a_la_publicada(): void
    {
        $this->importBaseline();
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $pub = $this->personWith(['ultron.view', 'ultron.publish'], 'Pablo Publica');
        $pro = $this->personWith(['ultron.view', 'ultron.publish'], 'Paula Promueve');

        // V1 publicada y promovida: no había PUBLISHED.
        $v1 = $this->approvedVersion($ana, $root, 'Primera');
        $this->publishAs($pub, $v1->uuid)->assertCreated();
        $this->stampTurns(3, $v1->uuid, now()->addHour());
        self::at('2026-11-04T16:00:00Z');
        $this->freshSessions();
        $evidencia = $this->evidenceOf($pro, $v1->uuid);
        $this->assertTrue($evidencia['passes'], json_encode($evidencia['failing']));
        $this->promoteAs($pro, $v1->uuid, null, $evidencia['evidenceHash'])->assertCreated()
            ->assertJsonPath('data.action', 'promote')
            ->assertJsonPath('data.version.uuid', $v1->uuid)
            ->assertJsonPath('data.labGate', null)
            ->assertJsonPath('data.actor.name', 'Paula Promueve');
        $this->assertSame('PUBLISHED', $v1->fresh()->status);
        $this->assertChainMatchesStates();

        // La entrada {V1, V1}: la MISMA parte del canario (Q-3: publishedBy es
        // quien publicó; quien promueve va en la fila y en el libro).
        $promocion = $this->chainHead();
        $partes = D::parts($promocion->effective_json, $promocion->uuid, $promocion->seq);
        $this->assertSame($partes['canary'], $partes['rest']);
        $this->assertSame($pub->id, $partes['rest']['publishedBy']);
        $this->assertSame([$pro->id, 'Paula Promueve'], [$promocion->actor_admin_id, $promocion->actor_name]);
        // La evidencia guardada: recuentos, umbrales e inicio; ni ids ni textos.
        $this->assertSame($evidencia['evidenceHash'], StudioContentHasher::hash($promocion->evidence));
        $this->assertStringNotContainsString((string) self::CANARY_ID, json_encode($promocion->evidence));
        $promovida = StudioAuditEvent::query()->where('event', 'version.promoted')->sole();
        $this->assertSame(['CANARY', 'PUBLISHED'], [$promovida->from_status, $promovida->to_status]);
        $this->assertSame(['entry', 'seq', 'evidence'], array_keys($promovida->diff));
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.retired')->count());

        // El motor: el canario y el resto aplican V1.
        $this->assertSame(['applied', 'canary', $v1->uuid], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['part'], $this->engineStamp(self::CANARY_ID)['version']]);
        $this->assertSame(['applied', 'rest', $v1->uuid], [$this->engineStamp(4242)['state'], $this->engineStamp(4242)['part'], $this->engineStamp(4242)['version']]);

        // V2 en canario {V2, V1} y promovida: V1 → RETIRED («Sustituida»).
        $v2 = $this->approvedVersion($ana, $root, 'Segunda');
        $this->publishAs($pub, $v2->uuid)->assertCreated();
        $this->assertSame(['canary' => $v2->uuid, 'rest' => $v1->uuid], $this->headVersions());
        $this->assertSame($v1->uuid, $this->engineStamp(4242)['version'], 'el resto sigue con V1 mientras V2 está en canario');
        $this->stampTurns(3, $v2->uuid, now()->addHour());
        self::at('2026-11-05T17:00:00Z');
        $this->freshSessions();
        $this->promoteAs($pro, $v2->uuid)->assertCreated();
        $this->assertSame(['PUBLISHED', 'RETIRED'], [$v2->fresh()->status, $v1->fresh()->status]);
        $this->assertSame('Sustituida', $this->studio('GET', "versions/{$v1->uuid}", $root)->json('data.statusLabel'));
        $retirada = StudioAuditEvent::query()->where('event', 'version.retired')->sole();
        $this->assertSame([$v1->uuid, 'PUBLISHED', 'RETIRED'], [$retirada->subject_uuid, $retirada->from_status, $retirada->to_status]);
        $this->assertSame(['entry' => $this->chainHead()->uuid, 'by' => 'promote'], $retirada->diff);
        $this->assertChainMatchesStates();
        // Cada entrada encadena la anterior. Por relación y no por valor: en
        // PostgreSQL la identidad no retrocede con el rollback de cada prueba.
        $filas = StudioPublication::query()->orderBy('seq')->get(['id', 'seq', 'previous_publication_id']);
        $this->assertSame([1, 2, 3, 4], $filas->pluck('seq')->all());
        $this->assertSame([null, ...$filas->pluck('id')->take(3)->all()], $filas->pluck('previous_publication_id')->all());
    }

    // ── Idempotencia, libro y carreras ──────────────────────────────────────

    public function test_publicar_y_promover_son_idempotentes_por_client_request_id(): void
    {
        $this->importBaseline();
        self::at('2026-11-03T15:00:00Z');
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $otra = $this->personWith(['ultron.view', 'ultron.publish'], 'Otra persona');
        $v = $this->approvedVersion($ana, $root);
        $vista = $this->previewOf($root, $v->uuid);
        $cuerpo = ['clientRequestId' => $crid = (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']];

        $primera = $this->studio('POST', "versions/{$v->uuid}/publish", $root, $cuerpo)->assertCreated()->json('data');
        // El doble clic: la entrada de la primera vez, aunque la versión ya esté en CANARY.
        $this->studio('POST', "versions/{$v->uuid}/publish", $root, $cuerpo)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data', $primera);
        // La misma llave con otro cuerpo, o de otra persona: 409.
        $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['note' => 'Otra nota'] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->studio('POST', "versions/{$v->uuid}/publish", $otra, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        // Y para otra acción.
        $this->studio('POST', "versions/{$v->uuid}/promote", $root, ['clientRequestId' => $crid, 'evidenceHash' => self::STALE])->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioPublication::query()->count());

        $this->stampTurns(3, $v->uuid, now()->addHour());
        self::at('2026-11-04T16:00:00Z');
        $this->freshSessions();
        $huella = $this->evidenceOf($root, $v->uuid)['evidenceHash'];
        $promo = ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $huella];
        $promovida = $this->studio('POST', "versions/{$v->uuid}/promote", $root, $promo)->assertCreated()->json('data');
        $this->studio('POST', "versions/{$v->uuid}/promote", $root, $promo)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data', $promovida);
        $this->studio('POST', "versions/{$v->uuid}/promote", $root, ['note' => 'Distinta'] + $promo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(2, StudioPublication::query()->count());
    }

    public function test_el_evento_del_libro_va_en_la_misma_transaccion_que_la_entrada_y_la_transicion(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $vista = $this->previewOf($root, $v->uuid);

        // El libro no se puede escribir: nada de la publicación se confirma.
        $this->beforeStatement('insert into "ultron_studio_audit_events"', static fn () => throw new RuntimeException('el libro no responde'));
        $this->withoutExceptionHandling();
        try {
            $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']]);
            $this->fail('La publicación siguió sin poder escribir el libro.');
        } catch (RuntimeException $e) {
            $this->assertSame('el libro no responde', $e->getMessage());
        }

        $this->assertSame(0, StudioPublication::query()->count());
        $this->assertSame('APPROVED', $v->fresh()->status);
        $this->assertSame('legacy', $this->engineStamp(self::CANARY_ID)['state'] ?? 'legacy');
    }

    public function test_la_carrera_por_la_cabeza_es_publication_moved_y_se_resuelve_fuera_de_la_transaccion(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $vista = $this->previewOf($root, $v->uuid);

        // Otra escritura confirma la entrada 1 entre la lectura de la cabeza y la
        // inserción de esta: el UNIQUE de `seq` para a esta (23505 en PG, que deja
        // la transacción abortada: la respuesta se decide fuera, sin 25P02).
        $this->beforeStatement('insert into "ultron_studio_publications"', function (): void {
            $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['seq' => 1, 'previous_publication_id' => null]);
        });
        $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])
            ->assertStatus(409)->assertJsonPath('code', 'publication_moved');

        $this->assertSame('APPROVED', $v->fresh()->status);
        // La conexión sigue viva y la cadena intacta.
        $this->assertSame(0, StudioPublication::query()->where('version_id', $v->id)->count());
        $this->publishAs($root, $v->uuid)->assertCreated();
    }

    public function test_la_carrera_del_estado_es_invalid_transition_con_el_estado_de_ahora(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $vista = $this->previewOf($root, $v->uuid);

        // Otra versión se mete en CANARY entre las puertas y la transición: el
        // índice parcial `usv_one_canary` para a esta → canary_in_progress.
        $otra = $this->seededVersion($ana, $root, $this->baseline());
        $this->beforeStatement('update "ultron_studio_versions"', static function () use ($otra): void {
            StudioVersion::transition($otra->id, ['APPROVED'], ['status' => 'CANARY', 'status_changed_at' => now()]);
        });
        $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])
            ->assertStatus(409)->assertJsonPath('code', 'canary_in_progress');
        $this->assertSame([0, 'APPROVED'], [StudioPublication::query()->count(), $v->fresh()->status]);
    }

    public function test_una_cabeza_que_no_verifica_no_se_copia_y_legacy_es_la_salida(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        // Una cabeza con la firma de otra clave (una APP_KEY rotada, o una fila escrita a mano).
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('0', 64)]);

        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'publication_head_invalid');
        $this->assertSame('fallback', $this->studio('GET', 'status', $root)->json('data.engine.publication.state'));
        $this->assertFalse($this->studio('GET', 'status', $root)->json('data.engine.publication.head.signatureOk'));
        $this->assertSame(['fallback', 'signature_invalid'], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['reason']]);

        // Volver a Legacy no copia nada: escribe una cabeza limpia, y entonces se publica.
        $this->legacyAs($root)->assertCreated();
        $this->assertSame('legacy', $this->engineStamp(self::CANARY_ID)['state']);
        $this->publishAs($root, $v->uuid)->assertCreated();
        $this->assertSame('applied', $this->engineStamp(self::CANARY_ID)['state']);
    }

    /**
     * B-1 del tester de backend (mutación C2, 25-10): una cabeza con la firma
     * buena sobre sus bytes y la columna de la HUELLA tocada a mano (DML sin la
     * APP_KEY) la rechaza el motor (fallback/hash_mismatch) y el Studio no
     * compone encima: publicar es 409 publication_head_invalid y Legacy es la
     * salida.
     */
    public function test_una_cabeza_con_la_huella_tocada_a_mano_no_se_copia_aunque_su_firma_verifique(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['effective_hash' => str_repeat('0', 64)]);

        $sello = $this->engineStamp(self::CANARY_ID);
        $this->assertSame(['fallback', 'hash_mismatch'], [$sello['state'] ?? null, $sello['reason'] ?? null], json_encode($sello));
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'publication_head_invalid');
        $this->assertSame('APPROVED', $v->fresh()->status);
        $this->assertSame('fallback', $this->studio('GET', 'status', $root)->json('data.engine.publication.state'));
        $this->legacyAs($root)->assertCreated();
    }

    /**
     * B-3 del tester de backend (mutación J1, 25-10): el Studio lee el texto
     * firmado de la cabeza acotado a MAX_EFFECTIVE_JSON + 1, como el motor. Una
     * cabeza de más de 16 384 caracteres con su huella y su firma de los bytes
     * ENTEROS la ve el motor como hash_mismatch, y el Studio tiene que verla
     * igual: sin el tope compondría encima de una cabeza que el motor no aplica.
     */
    public function test_una_cabeza_de_mas_de_16384_caracteres_se_lee_acotada_como_la_lee_el_motor(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        $uuid = strtolower((string) Str::uuid());
        $cuerpo = json_decode(D::effectiveJson($uuid, 1, now(), null, null), true);
        $cuerpo['issuedAt'] = str_repeat('x', 20000);   // mismas claves y tipos; más de 16 384 caracteres
        $enorme = (string) json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertGreaterThan(D::MAX_EFFECTIVE_JSON, strlen($enorme));
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, [
            'uuid' => $uuid, 'seq' => 1, 'previous_publication_id' => null,
            'effective_json' => $enorme,
            'effective_hash' => D::effectiveHash($enorme),
            'signature' => UltronPublishedConfig::signatureFor($enorme),
        ]);

        $sello = $this->engineStamp(self::CANARY_ID);
        $this->assertSame(['fallback', 'hash_mismatch'], [$sello['state'] ?? null, $sello['reason'] ?? null], json_encode($sello));
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'publication_head_invalid');
        $this->assertSame('fallback', $this->studio('GET', 'status', $root)->json('data.engine.publication.state'));
        $this->legacyAs($root)->assertCreated();
    }

    /**
     * m-2 del reviewer del Hito 5 (31-10): una versión APROBADA cuyo contenido
     * el catálogo actual no sabe leer (aquí, un parámetro que no existe en una
     * capa real) no se publica, y el 409 revalidation_blocked tiene que decir
     * POR QUÉ: la validación del grafo no produce ningún bloqueo, así que sin
     * un hallazgo propio la respuesta diría «tiene bloqueos» con la lista vacía.
     */
    public function test_un_contenido_que_el_catalogo_no_sabe_leer_se_rechaza_con_un_hallazgo_que_lo_explica(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $contenido = $this->baseline();
        $contenido['configs']['persona']['parametro-fantasma'] = true;
        $v = $this->seededVersion($ana, $root, $contenido);

        $vista = $this->previewOf($root, $v->uuid);
        $this->assertFalse($vista['publishable']);
        $this->assertContains('revalidation_blocked', $vista['blocking']);

        $r = $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => []])
            ->assertStatus(409)
            ->assertJsonPath('code', 'revalidation_blocked');
        $bloqueos = collect($r->json('findings'))->where('level', 'blocking');
        $this->assertNotEmpty($bloqueos->all(), 'el 409 tiene que enseñar al menos un bloqueo: '.json_encode($r->json('findings')));
        $this->assertContains(StudioPublications::FINDING_UNREADABLE, $bloqueos->pluck('id')->all());
        $this->assertSame('APPROVED', $v->fresh()->status);
        $this->assertSame(0, StudioPublication::query()->count());
    }

    /**
     * B-2 del tester de backend (mutación I1, 25-10): `previewHash` lleva el
     * catálogo DE LA VERSIÓN (Q-4), no el de hoy. Con una versión de F
     * (catálogo 2026-10-18.1) la huella de la vista previa es la de su catálogo
     * y no la que saldría con el vigente.
     */
    public function test_la_vista_previa_lleva_el_catalogo_de_la_version_en_su_huella(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->seedFormat1Version($ana, 'APPROVED');
        $vista = $this->previewOf($root, $v->uuid);

        $clasificacion = ['mustMatch' => [], 'matches' => [], 'noEffect' => []];
        foreach ($vista['params'] as $p) {
            $id = $p['layer'].'.'.$p['key'];
            if ($p['class'] === 'must_match') {
                $clasificacion['mustMatch'][] = ['param' => $id, 'versionValue' => $p['versionValue'], 'engineValue' => $p['engineValue'], 'ok' => $p['ok']];
            } elseif ($p['class'] === 'matches') {
                $clasificacion['matches'][] = $id;
            } else {
                $clasificacion['noEffect'][] = $id;
            }
        }
        $puerta = ['mode' => $vista['requirements']['labGate']['mode'], 'latest' => $vista['requirements']['labGate']['latest']];
        $this->assertSame(self::CATALOG_F, (string) $v->catalog_version);
        $this->assertSame(D::previewHash($v->uuid, $v->content_hash, self::CATALOG_F, $clasificacion, $puerta), $vista['previewHash'], 'la huella es la del catálogo de la versión');
        $this->assertNotSame(D::previewHash($v->uuid, $v->content_hash, StudioCatalog::VERSION, $clasificacion, $puerta), $vista['previewHash'], 'con el catálogo de hoy saldría otra');
    }

    public function test_el_historial_va_de_la_cabeza_hacia_atras_y_el_tope_de_la_pagina_es_del_servidor(): void
    {
        $this->openKeys();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        for ($i = 0; $i < 55; $i++) {
            $this->seedEntry(D::ACTION_LEGACY, null, null, null);
        }

        // Pedir 1000 da 50 (contrato H5 §A7: el historial, con la forma de `GET versions`).
        $primera = $this->studio('GET', 'publications?per_page=1000', $root)->assertOk();
        $this->assertSame([50, 55, 2], [$primera->json('data.per_page'), $primera->json('data.total'), $primera->json('data.last_page')]);
        $this->assertSame(range(55, 6), array_column($primera->json('data.data'), 'seq'), 'de la cabeza hacia atrás');
        $this->assertSame(range(5, 1), array_column($this->studio('GET', 'publications?per_page=1000&page=2', $root)->assertOk()->json('data.data'), 'seq'));
        $this->assertSame(20, $this->studio('GET', 'publications', $root)->assertOk()->json('data.per_page'), 'por defecto, 20');
    }

    // ── La vista previa ─────────────────────────────────────────────────────

    public function test_la_vista_previa_tiene_la_forma_del_contrato_y_su_huella_es_estable(): void
    {
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.window' => '07:00-19:00']);
        self::at('2026-11-03T15:00:00Z');
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->newStudioRule($root)->assertCreated();
        $v = $this->approvedVersion($ana, $root, 'Con tono y regla', ['persona' => ['tone' => 'profesional']]);

        $vista = $this->previewOf($root, $v->uuid);
        $this->assertSame(['versionUuid', 'contentHash', 'previewHash', 'publishable', 'params', 'noEffect', 'requirements', 'blocking'], array_keys($vista));
        $this->assertSame([$v->uuid, $v->content_hash, true, []], [$vista['versionUuid'], $vista['contentHash'], $vista['publishable'], $vista['blocking']]);
        $this->assertSame(['persona.tone', 'rule:S-001'], $vista['noEffect']);
        // Los cuatro must_match, en el orden del catálogo, y nada sin clase.
        $porClase = [];
        foreach ($vista['params'] as $p) {
            $porClase[$p['class']][] = "{$p['layer']}.{$p['key']}";
        }
        $this->assertSame(['intent.driver', 'rules.paymentLinksEnabled', 'governance.enabled', 'governance.canaryOnly'], $porClase['must_match']);
        $this->assertSame(['persona.tone'], $porClase['no_effect']);
        $this->assertNotContains('knowledge.sources', array_merge(...array_values($porClase)));
        $this->assertSame(['status', 'fourEyes', 'integrity', 'revalidation', 'labGate', 'engineCanary', 'brake', 'canaryFree', 'window', 'keys'], array_keys($vista['requirements']));
        $this->assertSame(['ok' => true, 'window' => '07:00–19:00'], $vista['requirements']['window']);
        $this->assertSame(['studio' => true, 'engine' => 'canary'], $vista['requirements']['keys']);

        // La huella no depende de la hora ni de quién mira; sí de una lectura del motor.
        self::at('2026-11-03T22:00:00Z');
        $otra = $this->previewOf($this->personWith(['ultron.view'], 'Solo mira'), $v->uuid);
        $this->assertSame($vista['previewHash'], $otra['previewHash']);
        $this->assertSame(['forbidden'], $otra['blocking'], 'sin la llave de publicar, no es publicable para quien mira');
        config(['marketing.ai.driver' => 'openai', 'marketing.ai.openai.enabled' => true, 'marketing.ai.openai.model' => 'gpt-x', 'services.openai.api_key' => 'sk-prueba']);
        $this->assertNotSame($vista['previewHash'], $this->previewOf($root, $v->uuid)['previewHash']);

        // Con una vista previa vieja, 409 `preview_stale` con la nueva.
        $r = $this->studio('POST', "versions/{$v->uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])
            ->assertStatus(409)->assertJsonPath('code', 'preview_stale');
        $this->assertSame($this->previewOf($root, $v->uuid), $r->json('preview'));
        $this->assertSame('APPROVED', $v->fresh()->status);
    }

    public function test_la_vista_previa_dice_por_que_no_se_puede_publicar(): void
    {
        // Dentro de la ventana por defecto (10:00 en Bogotá): lo que bloquea es lo demás.
        self::at('2026-11-03T15:00:00Z');
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        // Las llaves por defecto, el freno echado y la autora mirando su versión.
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola', 'engaged_at' => now()]);
        $this->canary();
        config(['marketing.ultron.enabled' => true, 'marketing.ultron.canary_conversation_id' => self::CANARY_ID]);

        $vista = $this->previewOf($ana, $v->uuid);
        $this->assertFalse($vista['publishable']);
        $this->assertSame(['forbidden', 'publishing_disabled', 'engine_not_reading_versions', 'four_eyes_required', 'lab_verdict_required', 'engine_braked'], $vista['blocking']);
        $this->assertSame(['studio' => false, 'engine' => 'off'], $vista['requirements']['keys']);
        $this->assertFalse($vista['requirements']['fourEyes']['ok']);
        $this->assertSame(['ok' => false, 'message' => 'El freno de ULTRON está echado: no se publica ni se promueve hasta que alguien lo suelte.'], $vista['requirements']['brake']);
        // De una versión que no existe, 404.
        $this->studio('GET', 'versions/'.Str::uuid().'/publication-preview', $root)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
    }

    // ── Las puertas del fixture, por HTTP ───────────────────────────────────

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/publication-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function publishGateCases(): iterable
    {
        foreach (self::fixture()['gates'] as $caso) {
            if ($caso['kind'] === 'publish') {
                yield $caso['name'] => [$caso];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function promoteGateCases(): iterable
    {
        foreach (self::fixture()['gates'] as $caso) {
            if ($caso['kind'] === 'promote') {
                yield $caso['name'] => [$caso];
            }
        }
    }

    public function test_el_fixture_trae_las_58_puertas_de_publicar_y_promover(): void
    {
        $this->assertCount(45, iterator_to_array(self::publishGateCases()));
        $this->assertCount(13, iterator_to_array(self::promoteGateCases()));
    }

    #[DataProvider('publishGateCases')]
    public function test_publicar_por_http_en_el_orden_del_fixture(array $caso): void
    {
        $f = $caso['facts'];
        [$estado, $codigo, $carga] = self::httpExpectation($caso);
        [$uuid, $cuerpo, $cabeceras] = $this->arrangePublish($f);
        $entradas = StudioPublication::query()->count();

        $r = $this->json('POST', "/api/admin/ultron-studio/versions/{$uuid}/publish", $cuerpo, $cabeceras);

        $this->assertOutcome($r, $estado, $codigo, $carga, 'publish', $entradas);
    }

    #[DataProvider('promoteGateCases')]
    public function test_promover_por_http_en_el_orden_del_fixture(array $caso): void
    {
        $f = $caso['facts'];
        $esperado = $caso['expected'];
        [$uuid, $cuerpo, $cabeceras] = $this->arrangePromote($f);
        $entradas = StudioPublication::query()->count();

        $r = $this->json('POST', "/api/admin/ultron-studio/versions/{$uuid}/promote", $cuerpo, $cabeceras);

        $this->assertOutcome($r, $esperado['status'], $esperado['code'], $esperado['payload'] ?? null, 'promote', $entradas);
    }

    /**
     * Lo que tiene que responder el servidor a los hechos de un caso de
     * publicar. Es el `expected` del fixture salvo en tres casos que, con el
     * catálogo 2026-10-19.1, no se pueden construir por HTTP:
     *
     *  - Sin canario configurado (`engineCanaryConfigured: false`): la lectura
     *    de `governance.canaryOnly` ES «hay canario», es un `must_match` y el
     *    catálogo exige declararlo en true; así que el paso 8 responde
     *    `engine_value_mismatch` antes que el 9 (`preview_stale`) o el 10
     *    (`engine_has_no_canary`).
     *  - Un `canaryOnly: false` declarado (el mismatch del caso «mismatch y
     *    lectura no disponible a la vez»): esa versión no pasa la validación del
     *    catálogo. Se construye con el otro interruptor (`governance.enabled`,
     *    con el motor apagado): el orden que prueba el caso (el mismatch antes
     *    que la lectura caída) es el mismo.
     *
     * Los tres códigos del fixture los prueba, sobre las puertas puras,
     * `StudioPublicationGatesParityTest`.
     *
     * @return array{0:int|null,1:string|null,2:array<string,mixed>|null}
     */
    private static function httpExpectation(array $caso): array
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        $tardio = ['preview_stale', 'engine_has_no_canary'];
        if (! $f['engineCanaryConfigured'] && in_array($e['code'], $tardio, true)) {
            return [409, 'engine_value_mismatch', ['mismatches' => [['param' => 'governance.canaryOnly', 'versionValue' => true, 'engineValue' => false]]]];
        }
        if (in_array('governance.canaryOnly', array_column($f['classification']['mismatches'], 'param'), true)) {
            return [409, 'engine_value_mismatch', ['mismatches' => [['param' => 'governance.enabled', 'versionValue' => true, 'engineValue' => false]]]];
        }

        return [$e['status'], $e['code'], $e['payload'] ?? null];
    }

    /**
     * Construye DE VERDAD el estado de un caso de publicar.
     *
     * @return array{0:string,1:array<string,mixed>,2:array<string,string>}
     */
    private function arrangePublish(array $f): array
    {
        self::at('2026-11-03T15:00:00Z');
        $this->importBaseline();
        $autora = $this->person(Admin::ROLE_ADMINISTRADOR, 'Autora de la versión');
        $revisora = $this->person(Admin::ROLE_SUPER_ADMIN, 'Revisora');
        $actor = $f['actor']['person'] ? $this->personWith($f['actor']['permissions'], 'Quien publica') : null;
        $autor = $f['actor']['isAuthor'] && $actor !== null ? $actor : $autora;
        $this->openKeys(['marketing.ultron.studio_config' => 'canary', 'ultron_studio.publishing.lab_gate' => 'waived']);

        // Lo que la versión no aplica en el motor: el tono y una regla S-001.
        $sinEfecto = $f['classification']['noEffect'];
        if (in_array('rule:S-001', $sinEfecto, true)) {
            $this->newStudioRule($revisora)->assertCreated();
        }
        $contenido = $this->contentWith(
            in_array('persona.tone', $sinEfecto, true) ? ['persona' => ['tone' => 'profesional']] : [],
            $f['revalidation'] === 'blocked' ? array_values(array_diff($this->baseline()['definition']['edgeIds'], ['E10'])) : null,
        );

        $uuid = (string) Str::uuid();
        $version = null;
        if ($f['version']['exists']) {
            $estadoInicial = $f['clientRequest'] === 'replay' ? 'APPROVED' : $f['version']['status'];
            $version = $this->seededVersion($autor, $revisora, $contenido, $estadoInicial, $f['version']['integrityOk'] === false ? str_repeat('f', 64) : null,
                $f['revalidation'] === 'changed' ? ['aviso-que-ya-no-existe'] : null);
            $uuid = $version->uuid;

            match ($f['labGate']['latest']) {
                'approved', 'rejected', 'needs_changes' => $this->labVerdict($version, $f['labGate']['latest']),
                'stale_prompts' => $this->labVerdict($version, 'approved', '2000-01-01.1'),
                default => null,
            };
        }
        if ($f['otherVersionInCanary']) {
            $this->seededVersion($autora, $revisora, $this->baseline(), 'CANARY');
        }
        if ($f['brakeEngaged']) {
            UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola', 'engaged_at' => now()]);
        }

        // La misma clientRequestId usada antes: por esta persona y este cuerpo
        // (reintento), por esta persona con otro cuerpo, o por otra persona.
        $crid = (string) Str::uuid();
        $previo = null;
        if ($f['clientRequest'] === 'replay') {
            $vista = $this->previewOf($actor, $uuid);
            $previo = ['clientRequestId' => $crid, 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $f['acknowledgedNoEffect']];
            $this->studio('POST', "versions/{$uuid}/publish", $actor, $previo)->assertCreated();
        } elseif ($f['clientRequest'] !== 'new') {
            $quien = $f['clientRequest'] === 'reusedOtherActor' ? $this->personWith(['ultron.view', 'ultron.publish'], 'Otra persona que publica') : $actor;
            $otra = $this->seededVersion($autora, $revisora, $this->baseline());
            $this->publishAs($quien, $otra->uuid, $crid)->assertCreated();
        }

        // Las llaves, el motor y la ventana del caso.
        $mismatch = array_column($f['classification']['mismatches'], 'param');
        config([
            'marketing.ultron.enabled' => $mismatch === [],
            'marketing.ultron.canary_conversation_id' => $f['engineCanaryConfigured'] ? self::CANARY_ID : null,
            'marketing.ai.driver' => in_array('intent.driver', $f['classification']['engineReadingUnavailable'], true) ? ['lectura rota'] : 'fake',
            'marketing.ultron.studio_config' => $f['engineScope'],
            'ultron_studio.publishing_enabled' => $f['studioPublishingEnabled'],
            'ultron_studio.publishing.lab_gate' => $f['labGate']['mode'],
            'ultron_studio.publishing.window' => $f['window'],
        ]);

        $huella = self::STALE;
        if ($previo !== null) {
            $huella = $previo['previewHash'];
        } elseif ($f['previewHash']['sent'] === $f['previewHash']['current'] && $version !== null && $actor !== null && in_array('ultron.view', $f['actor']['permissions'], true)) {
            $huella = $this->previewOf($actor, $uuid)['previewHash'];
        }

        self::at($f['now']);
        $this->freshSessions();
        $cuerpo = ['clientRequestId' => $crid, 'previewHash' => $huella, 'acknowledgedNoEffect' => $f['acknowledgedNoEffect']];

        return [$uuid, $cuerpo, $actor !== null ? $this->as($actor) : $this->sharedTokenHeaders()];
    }

    /**
     * Construye DE VERDAD el estado de un caso de promover: la versión publicada
     * en canario 25 h antes (en ventana), sus turnos sellados y el resto.
     *
     * @return array{0:string,1:array<string,mixed>,2:array<string,string>}
     */
    private function arrangePromote(array $f): array
    {
        $antes = CarbonImmutable::parse($f['now'])->subHours(25)->format('Y-m-d\TH:i:s\Z');
        self::at($antes);
        $this->importBaseline();
        $autora = $this->person(Admin::ROLE_ADMINISTRADOR, 'Autora de la versión');
        $revisora = $this->person(Admin::ROLE_SUPER_ADMIN, 'Revisora');
        $actor = $f['actor']['person'] ? $this->personWith($f['actor']['permissions'], 'Quien promueve') : null;
        $autor = $f['actor']['isAuthor'] && $actor !== null ? $actor : $autora;
        $publica = $this->personWith(['ultron.view', 'ultron.publish'], 'Quien publicó en canario');
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);

        $uuid = (string) Str::uuid();
        $estado = $f['version']['status'];
        if ($f['version']['exists']) {
            if ($f['version']['integrityOk'] === false || $estado === 'APPROVED') {
                // A una huella alterada no se llega por la API: se siembra en su estado.
                $uuid = $this->seededVersion($autor, $revisora, $this->baseline(), $estado, $f['version']['integrityOk'] === false ? str_repeat('f', 64) : null)->uuid;
            } else {
                $uuid = $this->seededVersion($autor, $revisora, $this->baseline())->uuid;
                $this->publishAs($publica, $uuid)->assertCreated();
                if (! in_array('turns', $f['evidence']['failing'], true)) {
                    $this->stampTurns(3, $uuid, now()->addHour());
                }
            }
        }

        $crid = (string) Str::uuid();
        $previo = null;
        if ($f['clientRequest'] === 'replay') {
            // La primera promoción, con la evidencia suficiente y en ventana.
            self::at(CarbonImmutable::parse($f['now'])->subHour()->format('Y-m-d\TH:i:s\Z'));
            $this->freshSessions();
            $previo = ['clientRequestId' => $crid, 'evidenceHash' => $this->evidenceOf($actor, $uuid)['evidenceHash']];
            $this->studio('POST', "versions/{$uuid}/promote", $actor, $previo)->assertCreated();
        }
        if ($f['brakeEngaged']) {
            UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola', 'engaged_at' => now()]);
        }

        config([
            'marketing.ultron.studio_config' => $f['engineScope'],
            'ultron_studio.publishing_enabled' => $f['studioPublishingEnabled'],
            'ultron_studio.publishing.window' => $f['window'],
        ]);
        self::at($f['now']);
        $this->freshSessions();

        $huella = self::STALE;
        if ($previo !== null) {
            $huella = $previo['evidenceHash'];
        } elseif ($f['evidenceHash']['sent'] === $f['evidenceHash']['current'] && $f['version']['exists'] && $estado === 'CANARY' && $actor !== null) {
            $huella = $this->evidenceOf($actor, $uuid)['evidenceHash'];
        }

        return [$uuid, ['clientRequestId' => $crid, 'evidenceHash' => $huella], $actor !== null ? $this->as($actor) : $this->sharedTokenHeaders()];
    }

    /**
     * El resultado de un caso: 201 con su entrada (y la invariante de A2), 200
     * `replayed`, o el rechazo con su código y su carga, sin escribir nada en
     * la cadena. El token compartido se para en la puerta del mapa
     * (`AuthorizationMap`) con su 403 `forbidden`, antes que el 403
     * `person_required` del dominio (fixture P-10: aquí se mira el 403).
     *
     * @param  array<string, mixed>|null  $carga
     */
    private function assertOutcome(TestResponse $r, ?int $estado, ?string $codigo, ?array $carga, string $accion, int $entradasAntes): void
    {
        if ($estado === null) {
            $this->assertSame(201, $r->status(), $r->getContent());
            $r->assertJsonPath('data.action', $accion);
            $this->assertSame($entradasAntes + 1, StudioPublication::query()->count());
            $this->assertChainMatchesStates();

            return;
        }
        $this->assertSame($estado, $r->status(), $r->getContent());
        if ($codigo === 'replayed') {
            $r->assertJsonPath('replayed', true)->assertJsonPath('data.action', $accion);
            $this->assertSame($entradasAntes, StudioPublication::query()->count(), 'el reintento no escribe otra entrada');

            return;
        }
        if ($codigo === 'person_required') {
            $this->assertContains($r->json('code'), ['person_required', 'forbidden'], $r->getContent());
        } else {
            $r->assertJsonPath('code', $codigo);
        }
        foreach ($carga ?? [] as $clave => $valor) {
            $this->assertSame($valor, $r->json($clave), "carga «{$clave}»: ".$r->getContent());
        }
        $this->assertSame($entradasAntes, StudioPublication::query()->count(), 'un rechazo no escribe en la cadena');
    }
}
