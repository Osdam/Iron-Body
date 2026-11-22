<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioRulePhraseAuthors;
use App\Services\UltronStudio\StudioRulePhrases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CUATRO OJOS POR REGLA (B-2 de seguridad; opción A del plan de cierre §3;
 * Enmienda del 22-11-2026 al contrato H5, A5.1 paso 4), por HTTP y sobre las
 * clases reales.
 *
 * Quien escribió una revisión ≤ la copiada de una S-NNN que aporta frases a
 * la versión no la aprueba, ni la publica, ni la promueve, en WhatsApp ni en
 * la voz, aunque la versión sea de otra persona y aunque sea Super Admin.
 * Mismo 403 `four_eyes_required` (con `detail: rule_phrase_author`) y el
 * mismo evento del libro (`diff.rulePhraseAuthor`). Rechazar sí puede. La
 * autoría sale del libro, no de `updated_by`, y si falta una línea se niega
 * a todos. Para una versión SIN frases de reglas nada cambia (la comparación
 * byte a byte está en el informe; aquí, la forma y que no se lee el libro).
 */
class UltronStudioRulePhraseAuthorsTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    /** Los diez requisitos de A7 de siempre, en su orden. */
    private const REQUISITOS = ['status', 'fourEyes', 'integrity', 'revalidation', 'labGate', 'engineCanary', 'brake', 'canaryFree', 'window', 'keys'];

    private Admin $ana;

    private Admin $beto;

    private Admin $carla;

    private Admin $root;

    private Admin $pub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $this->beto = $this->person(Admin::ROLE_ADMINISTRADOR, 'Beto Edita');
        $this->carla = $this->person(Admin::ROLE_ADMINISTRADOR, 'Carla Revisa');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->pub = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── Aprobar ─────────────────────────────────────────────────────────────

    public function test_el_autor_de_la_revision_1_no_aprueba_aunque_la_revision_2_sea_de_otra_cuenta(): void
    {
        $regla = $this->frases($this->ana, ['te lo regalo']);
        $this->editar($this->beto, $regla, ['priority' => 'high']);
        // El «último editor» de la fila es Beto: con él, Ana pasaría.
        $this->assertSame([2, $this->ana->id, $this->beto->id], [$regla->refresh()->revision, $regla->created_by_admin_id, $regla->updated_by_admin_id]);

        $uuid = $this->submittedVersion($this->root, 'De Root, con la regla de Ana');
        $enviada = StudioVersion::query()->where('uuid', $uuid)->value('status');

        $mensaje = 'Escribiste S-001, revisión 1: tiene que aprobarla otra persona.';
        $acciones = $this->studio('GET', "versions/{$uuid}", $this->ana)->assertOk()->json('data.actions');
        $this->assertSame(['allowed' => false, 'code' => 'four_eyes', 'message' => $mensaje, 'detail' => 'rule_phrase_author'], $acciones['approve']);
        $this->assertSame(['allowed' => true], $acciones['reject']);

        $this->aprobar($this->ana, $uuid)->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')
            ->assertJsonPath('message', $mensaje)
            ->assertJsonPath('detail', 'rule_phrase_author')
            ->assertJsonPath('rulePhraseAuthor', [['code' => 'S-001', 'revision' => 2, 'authored' => [1], 'unverified' => false]]);
        $evento = $this->ultimoDenegado();
        $this->assertSame(['approve', ['S-001']], [$evento->diff['action'], $evento->diff['rulePhraseAuthor']]);
        $this->assertArrayNotHasKey('rulePhraseUnverified', $evento->diff);
        $this->assertSame($this->ana->id, (int) $evento->actor_admin_id);
        $this->assertStringContainsString('copia frases de reglas que escribió', $evento->summary);

        // Beto, el de la revisión 2, tampoco.
        $this->aprobar($this->beto, $uuid)->assertStatus(403)
            ->assertJsonPath('rulePhraseAuthor.0.authored', [2])
            ->assertJsonPath('message', 'Escribiste S-001, revisión 2: tiene que aprobarla otra persona.');
        $this->assertSame($enviada, StudioVersion::query()->where('uuid', $uuid)->value('status'), 'un intento denegado no la mueve');

        // Una tercera persona, sí.
        $this->aprobar($this->carla, $uuid)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_una_revision_intermedia_cuenta_y_el_mensaje_nombra_todas_las_del_autor(): void
    {
        $regla = $this->frases($this->ana, ['te lo regalo']);
        $this->editar($this->beto, $regla, ['conditions' => self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['te lo regalo', 'oiga']]])]);
        $this->editar($this->ana, $regla, ['priority' => 'high']);
        // Ni la creadora (Ana) ni la última editora (Ana) son Beto.
        $this->assertSame([3, $this->ana->id, $this->ana->id], [$regla->refresh()->revision, $regla->created_by_admin_id, $regla->updated_by_admin_id]);

        $uuid = $this->submittedVersion($this->root);

        $this->aprobar($this->beto, $uuid)->assertStatus(403)->assertJsonPath('rulePhraseAuthor.0.authored', [2]);
        $this->aprobar($this->ana, $uuid)->assertStatus(403)
            ->assertJsonPath('message', 'Escribiste S-001, revisiones 1 y 3: tiene que aprobarla otra persona.')
            ->assertJsonPath('rulePhraseAuthor', [['code' => 'S-001', 'revision' => 3, 'authored' => [1, 3], 'unverified' => false]]);
        $this->aprobar($this->carla, $uuid)->assertOk();
    }

    public function test_quien_edita_la_regla_despues_de_copiarla_no_es_autor_de_lo_copiado(): void
    {
        $regla = $this->frases($this->ana, ['te lo regalo']);
        $uuid = $this->submittedVersion($this->root);
        // Después de crear la versión: la revisión 2 no está en ella.
        $this->editar($this->beto, $regla, ['priority' => 'critical']);

        $this->aprobar($this->ana, $uuid)->assertStatus(403)
            ->assertJsonPath('rulePhraseAuthor', [['code' => 'S-001', 'revision' => 1, 'authored' => [1], 'unverified' => false]]);
        $this->assertSame(['allowed' => true], $this->studio('GET', "versions/{$uuid}", $this->beto)->json('data.actions.approve'));
        $this->aprobar($this->beto, $uuid)->assertOk();
    }

    public function test_el_autor_de_reglas_sin_frases_o_cuyo_si_no_se_cumple_no_queda_bloqueado_ni_se_lee_el_libro(): void
    {
        $this->newStudioRule($this->ana, ['layers' => ['persona'], 'conditions' => self::conditionsOf([self::requireEq('persona', 'emojis', true)])])->assertCreated();
        $this->frases($this->ana, ['parcero'], [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => 'entusiasta']]);
        $uuid = (string) $this->createVersion($this->root, 'Profesional', null, $this->contentWith(['persona' => ['tone' => 'profesional']]))->assertCreated()->json('data.uuid');
        $this->studio('POST', "versions/{$uuid}/submit", $this->root)->assertOk();
        $this->assertNull(StudioRulePhraseAuthors::of(StudioVersion::query()->where('uuid', $uuid)->sole()->content, $this->ana->id), 'ninguna regla aporta frases');

        $lecturas = $this->contarLecturasDelLibro(function () use ($uuid): void {
            $this->assertSame(['allowed' => true], $this->studio('GET', "versions/{$uuid}", $this->ana)->json('data.actions.approve'));
            $this->aprobar($this->ana, $uuid)->assertOk();
            $vista = $this->previewOf($this->pub, $uuid);
            $this->assertSame(self::REQUISITOS, array_keys($vista['requirements']));
            $this->assertSame(['ok' => true], $vista['requirements']['fourEyes']);
            $this->publishAs($this->pub, $uuid)->assertCreated();
        });
        $this->assertSame(0, $lecturas, 'sin frases de reglas no se consulta el libro');
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->count());
    }

    public function test_una_regla_archivada_no_cuenta(): void
    {
        $regla = $this->frases($this->ana, ['te lo regalo']);
        $this->studio('POST', "rules/{$regla->uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'Ya no hace falta'])->assertOk();
        $uuid = $this->submittedVersion($this->root);
        $contenido = StudioVersion::query()->where('uuid', $uuid)->sole()->content;
        $this->assertNotContains('S-001', array_column($contenido['rules'], 'code'));
        $this->aprobar($this->ana, $uuid)->assertOk();

        // Y una copia marcada «archivada» dentro del contenido tampoco aporta
        // frases (el filtro de StudioRulePhrases::byRule), así que no hay autoría.
        $contenido['rules'][] = ['code' => 'S-001', 'revision' => 1, 'status' => 'archived', 'conditions' => self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['te lo regalo']]])];
        $this->assertNull(StudioRulePhraseAuthors::of($contenido, $this->ana->id));
    }

    public function test_una_revision_sin_su_linea_del_libro_o_sin_actor_se_niega_a_todos(): void
    {
        $sinLinea = $this->frases($this->ana, ['te lo regalo']);
        $sinActor = $this->frases($this->ana, ['oiga']);
        // Dos escrituras que se saltan el libro: una por SQL sin línea, otra con
        // una línea sin persona. Las dos dejan la revisión 2 sin autor probado.
        DB::table('ultron_studio_rules')->whereIn('id', [$sinLinea->id, $sinActor->id])->update(['revision' => 2]);
        app(StudioAudit::class)->record(null, null, 'rule.updated', StudioAuditEvent::SUBJECT_RULE, $sinActor->uuid, 'Edición sin persona', [
            'diff' => ['fromRevision' => 1, 'toRevision' => 2, 'changes' => []],
        ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);

        $uuid = $this->submittedVersion($this->root);

        // Carla no tocó nada, y tampoco: nadie puede probar que no las escribió.
        $this->aprobar($this->carla, $uuid)->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')
            ->assertJsonPath('detail', 'rule_phrase_author')
            ->assertJsonPath('message', 'No consta en el libro quién escribió todas las revisiones de S-001, S-002: no se aprueba una versión que la copie.')
            ->assertJsonPath('rulePhraseAuthor', [
                ['code' => 'S-001', 'revision' => 2, 'authored' => [], 'unverified' => true],
                ['code' => 'S-002', 'revision' => 2, 'authored' => [], 'unverified' => true],
            ]);
        $evento = $this->ultimoDenegado();
        $this->assertSame(['S-001', 'S-002'], $evento->diff['rulePhraseAuthor']);
        $this->assertSame(['S-001', 'S-002'], $evento->diff['rulePhraseUnverified']);

        $this->aprobar($this->ana, $uuid)->assertStatus(403)
            ->assertJsonPath('message', 'Escribiste S-001, revisión 1; S-002, revisión 1: tiene que aprobarla otra persona. No consta en el libro quién escribió todas las revisiones de S-001, S-002: no se aprueba una versión que la copie.');

        // Publicar tampoco (una versión sembrada como aprobada).
        $aprobada = $this->seededVersion($this->root, $this->carla, $this->baseline());
        $this->publishAs($this->pub, $aprobada->uuid)->assertStatus(403)
            ->assertJsonPath('detail', 'rule_phrase_author')
            ->assertJsonPath('rulePhraseAuthor.1.unverified', true);
        $this->assertSame(0, StudioPublication::query()->count());
    }

    public function test_quien_escribio_las_frases_si_puede_rechazar(): void
    {
        $this->frases($this->ana, ['te lo regalo']);
        $uuid = $this->submittedVersion($this->root);

        $this->studio('POST', "versions/{$uuid}/reject", $this->ana, ['reason' => 'Las frases no están bien'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->count());
    }

    // ── Dos cuentas ─────────────────────────────────────────────────────────

    public function test_dos_cuentas_que_funcionan_quien_escribe_las_frases_crea_la_version_y_la_otra_aprueba_y_publica(): void
    {
        $this->frases($this->ana, ['te lo regalo']);
        $uuid = $this->submittedVersion($this->ana);

        $this->aprobar($this->root, $uuid)->assertOk();

        $vista = $this->previewOf($this->root, $uuid);
        $this->assertSame([...self::REQUISITOS, 'rulePhraseAuthor'], array_keys($vista['requirements']));
        $this->assertSame(['ok' => true], $vista['requirements']['fourEyes']);
        $this->assertSame(['ok' => true, 'rules' => []], $vista['requirements']['rulePhraseAuthor']);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->publishAs($this->root, $uuid)->assertCreated();

        // Ana, la creadora, ve sus dos motivos: el de siempre y el de sus frases.
        $suya = $this->studio('GET', "versions/{$uuid}/publication-preview", $this->ana)->assertOk()->json('data');
        $this->assertSame(['ok' => false, 'message' => 'Nadie publica lo que creó: tiene que publicarla otra persona.'], $suya['requirements']['fourEyes']);
        $this->assertSame([['code' => 'S-001', 'revision' => 1, 'authored' => [1], 'unverified' => false]], $suya['requirements']['rulePhraseAuthor']['rules']);
    }

    public function test_dos_cuentas_que_se_bloquean_las_dos_escribieron_frases_y_el_super_admin_tampoco_pasa(): void
    {
        $this->frases($this->ana, ['te lo regalo']);
        $this->frases($this->root, ['oiga']);
        $uuid = $this->submittedVersion($this->ana);

        $this->aprobar($this->root, $uuid)->assertStatus(403)
            ->assertJsonPath('detail', 'rule_phrase_author')
            ->assertJsonPath('rulePhraseAuthor', [['code' => 'S-002', 'revision' => 1, 'authored' => [1], 'unverified' => false]]);
        // La creadora, con el 403 de siempre (sin `detail`).
        $r = $this->aprobar($this->ana, $uuid)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->assertSame(['ok' => false, 'code' => 'four_eyes_required', 'message' => 'Nadie aprueba ni rechaza lo que creó: pide a otra persona con permiso que revise esta versión.'], $r->json());

        // La salida segura: rechazarla.
        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => 'Hace falta una tercera persona'])->assertOk();
    }

    // ── Publicar, promover y revertir (WhatsApp) ────────────────────────────

    public function test_publicar_y_promover_se_niegan_al_autor_de_frases_y_revertir_no(): void
    {
        $this->frases($this->root, ['te lo regalo']);
        $uuid = $this->submittedVersion($this->ana);
        $this->aprobar($this->carla, $uuid)->assertOk();

        // La vista previa de Root: el mismo bloqueo, en la misma posición que
        // para quien la creó, con el motivo; y la misma huella que para Pablo.
        $deRoot = $this->previewOf($this->root, $uuid);
        $dePablo = $this->previewOf($this->pub, $uuid);
        $deAna = $this->studio('GET', "versions/{$uuid}/publication-preview", $this->ana)->json('data');
        $mensaje = 'Escribiste S-001, revisión 1: tiene que publicarla otra persona.';
        $this->assertSame(['four_eyes_required'], $deRoot['blocking']);
        $this->assertSame(['forbidden', 'four_eyes_required'], $deAna['blocking']);
        $this->assertSame([], $dePablo['blocking']);
        $this->assertSame(['ok' => false, 'message' => $mensaje, 'detail' => 'rule_phrase_author'], $deRoot['requirements']['fourEyes']);
        $this->assertSame(['ok' => false, 'message' => $mensaje, 'rules' => [['code' => 'S-001', 'revision' => 1, 'authored' => [1], 'unverified' => false]]], $deRoot['requirements']['rulePhraseAuthor']);
        $this->assertSame(['ok' => true, 'rules' => []], $dePablo['requirements']['rulePhraseAuthor']);
        $this->assertSame($dePablo['previewHash'], $deRoot['previewHash'], 'previewHash no depende de quien mira (Q-4)');
        $this->assertSame(['allowed' => false, 'code' => 'four_eyes_required', 'message' => $mensaje, 'detail' => 'rule_phrase_author'],
            $this->studio('GET', "versions/{$uuid}", $this->root)->json('data.actions.publish'));

        $this->publishAs($this->root, $uuid)->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')
            ->assertJsonPath('message', $mensaje)
            ->assertJsonPath('detail', 'rule_phrase_author');
        $evento = $this->ultimoDenegado();
        $this->assertSame(['publish', ['S-001']], [$evento->diff['action'], $evento->diff['rulePhraseAuthor']]);
        $this->assertSame(0, StudioPublication::query()->count());

        // Una consulta del libro al publicar (la de los cuatro ojos; las puertas la reutilizan).
        $vista = $this->previewOf($this->pub, $uuid);
        $lecturas = $this->contarLecturasDelLibro(function () use ($uuid, $vista): void {
            $this->studio('POST', "versions/{$uuid}/publish", $this->pub, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])->assertCreated();
        });
        $this->assertSame(1, $lecturas);

        // Promover, igual; revertir no tiene cuatro ojos.
        $promover = 'Escribiste S-001, revisión 1: tiene que promoverla otra persona.';
        $this->assertSame(['allowed' => false, 'code' => 'four_eyes_required', 'message' => $promover, 'detail' => 'rule_phrase_author'],
            $this->studio('GET', "versions/{$uuid}", $this->root)->json('data.actions.promote'));
        $this->studio('POST', "versions/{$uuid}/promote", $this->root, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('a', 64)])
            ->assertStatus(403)->assertJsonPath('message', $promover)->assertJsonPath('detail', 'rule_phrase_author');
        $this->assertSame(['promote', ['S-001']], [$this->ultimoDenegado()->diff['action'], $this->ultimoDenegado()->diff['rulePhraseAuthor']]);
        $this->rollbackAs($this->root, $uuid)->assertCreated();
    }

    public function test_una_sola_lectura_del_libro_por_version_con_varias_reglas_y_revisiones(): void
    {
        $a = $this->frases($this->ana, ['te lo regalo']);
        $b = $this->frases($this->beto, ['oiga']);
        $this->frases($this->carla, ['gratis']);
        $this->editar($this->beto, $a, ['priority' => 'high']);
        $this->editar($this->ana, $b, ['priority' => 'high']);
        $this->editar($this->carla, $a, ['priority' => 'critical']);
        $uuid = $this->submittedVersion($this->root);

        // Root la creó: los cuatro ojos de la versión deciden antes y no hace falta el libro.
        $lecturas = $this->contarLecturasDelLibro(function () use ($uuid): void {
            $this->studio('GET', "versions/{$uuid}", $this->root)->assertOk();
        });
        $this->assertSame(0, $lecturas, 'a quien creó la versión no se le lee el libro en el detalle');
        $dora = $this->personWith(['ultron.view', 'ultron.approve', 'ultron.publish'], 'Dora Aprueba y Publica');
        $lecturas = $this->contarLecturasDelLibro(function () use ($uuid, $dora): void {
            $acciones = $this->studio('GET', "versions/{$uuid}", $dora)->assertOk()->json('data.actions');
            $this->assertSame([true, true], [$acciones['approve']['allowed'], $acciones['reject']['allowed']]);
        });
        $this->assertSame(1, $lecturas, 'detalle: aprobar, publicar y promover comparten la lectura');
        $lecturas = $this->contarLecturasDelLibro(function () use ($uuid): void {
            $this->previewOf($this->root, $uuid);
        });
        $this->assertSame(1, $lecturas);
        $this->aprobar($this->beto, $uuid)->assertStatus(403)->assertJsonPath('rulePhraseAuthor', [
            ['code' => 'S-001', 'revision' => 3, 'authored' => [2], 'unverified' => false],
            ['code' => 'S-002', 'revision' => 2, 'authored' => [1], 'unverified' => false],
        ]);
    }

    // ── Voz ─────────────────────────────────────────────────────────────────

    public function test_la_voz_igual_que_whatsapp(): void
    {
        $this->voiceSetUp();
        $autora = $this->personWith([...self::VOICE_PUBLISHER, ...self::VOICE_MANAGER, 'ultron.manage_rules'], 'Autora de frases');
        $pablo = $this->personWith(self::VOICE_PUBLISHER, 'Pablo Publica la voz');
        $this->bindFake();
        $this->frases($autora, ['te lo regalo']);

        $v = $this->approvedVersion($this->root, $this->carla, 'Versión de Root');
        $this->assertSame(['S-001'], StudioRulePhrases::codes(StudioRulePhrases::byRule($v->content)), 'la voz compila sus frases');
        $p = $this->approvedProfile($this->root, $this->carla, null, 'Perfil de Root');
        $this->labVerdictFor($v, $p, $this->root, $this->carla);
        $v = StudioVersion::query()->with('review')->whereKey($v->id)->sole();

        $this->publishVoice($autora, $v, $p)->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')
            ->assertJsonPath('message', 'Escribiste S-001, revisión 1: tiene que publicarla otra persona.')
            ->assertJsonPath('detail', 'rule_phrase_author')
            ->assertJsonPath('rulePhraseAuthor', [['code' => 'S-001', 'revision' => 1, 'authored' => [1], 'unverified' => false]]);
        $this->assertSame([], $this->fake->calls, 'nada se despliega');
        $evento = StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->sole();
        $this->assertSame(['action' => 'publish', 'version' => false, 'profile' => false, 'rulePhraseAuthor' => ['S-001']], $evento->diff);

        $this->publishVoice($pablo, $v, $p)->assertCreated();
        $this->assertSame(1, StudioVoiceAgent::query()->where('serves_canary', true)->count());

        $this->promoteVoice($autora, str_repeat('a', 64))->assertStatus(403)
            ->assertJsonPath('message', 'Escribiste S-001, revisión 1: tiene que promoverla otra persona.')
            ->assertJsonPath('detail', 'rule_phrase_author');
        $this->assertSame('promote', StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->orderByDesc('id')->first()->diff['action']);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @param list<string> $frases */
    private function frases(Admin $as, array $frases, array $when = []): StudioRule
    {
        $uuid = (string) $this->newStudioRule($as, ['layers' => ['persona'], 'conditions' => self::conditionsOf(
            [['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => $frases]],
            $when,
        )])->assertCreated()->json('data.uuid');

        return StudioRule::query()->where('uuid', $uuid)->sole();
    }

    /** @param array<string, mixed> $campos */
    private function editar(Admin $as, StudioRule $regla, array $campos): void
    {
        $this->studio('PATCH', "rules/{$regla->uuid}", $as, ['baseRevision' => (int) $regla->refresh()->revision] + $campos)->assertOk();
    }

    private function aprobar(Admin $as, string $uuid): TestResponse
    {
        $avisos = StudioVersion::query()->where('uuid', $uuid)->sole()->last_validation['warnings'] ?? [];

        return $this->studio('POST', "versions/{$uuid}/approve", $as, ['acknowledgedWarnings' => $avisos]);
    }

    private function ultimoDenegado(): StudioAuditEvent
    {
        return StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->orderByDesc('id')->firstOrFail();
    }

    /** Cuántas veces se lee la autoría en el libro (la consulta de StudioRulePhraseAuthors) mientras corre `$accion`. */
    private function contarLecturasDelLibro(callable $accion): int
    {
        $n = 0;
        DB::listen(static function ($q) use (&$n): void {
            if (str_contains($q->sql, '"ultron_studio_audit_events" as "e"') && str_contains($q->sql, '"ultron_studio_rules" as "r"')) {
                $n++;
            }
        });
        $antes = $n;
        $accion();

        return $n - $antes;
    }
}
