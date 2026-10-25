<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\UltronAbort;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioCanaryEvidence;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA EVIDENCIA DEL CANARIO, CONTADA EN LA BASE (contrato H5 §A6 y §A5.2;
 * Enmienda 1, Q-5): los turnos de la conversación canaria del motor desde la
 * entrada que puso la versión en canario, su reparto por sello (de la MISMA
 * consulta), los sellados con esta versión, las recuperaciones, los frenos, el
 * acta (`ultron:canary-report`, de la que solo salen recuentos), la deriva con
 * una lectura caída y la firma de la cabeza. Sin ids de conversación ni textos
 * en ninguna respuesta. La ruta tiene su propio limitador, por persona.
 */
class UltronStudioPublicationCanaryTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** Una versión en canario, publicada ahora (las 10:00 del 3-11 en Bogotá). */
    private function inCanary(string $label = 'En canario'): StudioVersion
    {
        $v = $this->approvedVersion($this->ana, $this->root, $label);
        $this->publishAs($this->root, $v->uuid)->assertCreated();

        return $v->fresh();
    }

    private function later(int $hours): void
    {
        self::at(CarbonImmutable::now()->addHours($hours)->format('Y-m-d\TH:i:s\Z'));
        $this->freshSessions();
    }

    public function test_los_turnos_y_su_reparto_salen_de_una_sola_consulta_y_solo_cuentan_los_del_canario(): void
    {
        $v = $this->inCanary();
        $desde = CarbonImmutable::now();
        $luego = $desde->addMinutes(30);
        // Cuentan: los entrantes del canario desde la entrada.
        $this->stampTurns(3, $v->uuid, $luego);
        $this->stampTurns(1, (string) Str::uuid(), $luego);                                                       // applied, de OTRA versión
        $this->stampTurns(1, null, $luego, ['state' => 'legacy', 'part' => null, 'version' => null, 'contentHash' => null]);
        $this->stampTurns(1, null, $luego, ['state' => 'fallback', 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => 'signature_invalid']);
        $this->stampTurns(1, null, $luego, ['state' => 'out_of_scope', 'part' => null, 'version' => null, 'contentHash' => null]);
        $this->stampTurns(1, null, $luego, null);                                                                 // sin sello
        $this->stampTurns(1, null, $luego, ['state' => 'raro', 'version' => null]);                               // un sello que no se reconoce
        $this->stampTurns(1, $v->uuid, $luego, [], ['recovery' => ['kind' => 'surrender']]);                      // sellada y con recuperación
        // No cuentan: antes de la entrada, otra conversación y otro origen.
        $this->stampTurns(2, $v->uuid, $desde->subMinute());
        $otraConversacion = MarketingConversation::query()->create(['lead_id' => MarketingLead::query()->value('id'), 'channel' => 'whatsapp', 'status' => 'open']);
        $this->stampTurns(2, $v->uuid, $luego, [], [], 'inbound_message', $otraConversacion->id);
        $this->stampTurns(2, $v->uuid, $luego, [], [], 'followup');

        $this->later(26);
        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            if (str_contains($q->sql, 'count(*) as turns')) {
                $consultas[] = $q->sql;
            }
        });
        $e = $this->evidenceOf($this->root, $v->uuid);

        $this->assertCount(1, $consultas, 'turns, byState, stamped y recoveries: UNA consulta');
        foreach (['as applied', 'as stamped', 'as legacy', 'as out_of_scope', 'as recoveries', 'as fallback_0'] as $columna) {
            $this->assertStringContainsString($columna, $consultas[0]);
        }
        $this->assertSame(10, $e['turns']);
        $this->assertSame(4, $e['stamped'], 'las tres sellas con esta versión y la de la recuperación');
        $this->assertSame([
            'applied' => 5,
            'fallback/contract_unknown' => 0,
            'fallback/hash_mismatch' => 0,
            'fallback/malformed' => 0,
            'fallback/read_failed' => 0,
            'fallback/signature_invalid' => 1,
            'fallback/table_missing' => 0,
            'legacy' => 1,
            'out_of_scope' => 1,
            'unstamped' => 2,
        ], $e['byState']);
        $this->assertSame(1, $e['recoveries']);
        $this->assertSame(array_sum($e['byState']), $e['turns']);
        $this->assertSame(['stamped'], $e['failing'], 'no todos los turnos llevan el sello de esta versión');
        $this->assertSame($desde->format('Y-m-d\TH:i:s\Z'), $e['since']);
    }

    public function test_con_lo_minimo_pasa_y_la_huella_no_depende_del_reloj_sino_de_los_recuentos(): void
    {
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());

        $this->later(23);
        $casi = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame([23, false, ['hours']], [$casi['hours'], $casi['passes'], $casi['failing']]);

        $this->later(1);
        $justo = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame([24, true, []], [$justo['hours'], $justo['passes'], $justo['failing']]);
        $this->assertSame($casi['evidenceHash'], $justo['evidenceHash'], 'la huella no cambia con el paso del tiempo');
        $this->assertSame(['exitCode' => 0, 'total' => 0, 'counts' => array_fill_keys([
            'datos_personales', 'enlaces_no_oficiales', 'horarios_aplazados_en_una_persona', 'negaciones_de_un_cobro_disponible',
            'planes_no_vendibles', 'precios_que_no_son_del_catalogo', 'traspasos_no_autorizados',
        ], 0)], $justo['acta']);
        $this->assertSame(['count' => 0, 'engaged' => false], $justo['brakes']);
        $this->assertSame([[], true], [$justo['drift'], $justo['signatureOk']]);
        $this->assertSame(['minHours' => 24, 'minTurns' => 3], $justo['thresholds']);

        // Un turno más cambia la huella: quien promueve tiene que haber visto todo.
        $this->stampTurns(1, $v->uuid, now()->subHour());
        $this->assertNotSame($justo['evidenceHash'], $this->evidenceOf($this->root, $v->uuid)['evidenceHash']);
        // Y los umbrales también, recortados a sus topes duros (1–168 h, 1–100 turnos).
        config(['ultron_studio.publishing.canary_min_hours' => 900, 'ultron_studio.publishing.canary_min_turns' => 0]);
        $this->assertSame(['minHours' => 168, 'minTurns' => 1], $this->evidenceOf($this->root, $v->uuid)['thresholds']);
    }

    public function test_un_freno_desde_la_entrada_o_echado_ahora_la_suspende(): void
    {
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        // Una parada de ANTES de la entrada, ya soltada: no cuenta.
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola', 'engaged_at' => now()->subDay(), 'released_at' => now()->subHours(20), 'released_by' => 'Consola']);
        $this->later(25);
        $this->assertSame(['count' => 0, 'engaged' => false], $this->evidenceOf($this->root, $v->uuid)['brakes']);

        // Una DESPUÉS de la entrada, ya soltada: cuenta (V-3).
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_ORPHANED_TURN, 'engaged_by' => 'Vigía', 'engaged_at' => now()->subHours(2), 'released_at' => now()->subHour(), 'released_by' => 'Consola']);
        $e = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame([['count' => 1, 'engaged' => false], ['brakes']], [$e['brakes'], $e['failing']]);

        // Y una abierta ahora.
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola', 'engaged_at' => now()]);
        $e = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame(['count' => 2, 'engaged' => true], $e['brakes']);
        $this->promoteAs($this->root, $v->uuid, null, $e['evidenceHash'])->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient');
    }

    public function test_el_acta_solo_entrega_recuentos_y_nunca_su_texto(): void
    {
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        // Un turno del canario con un saliente de máquina que lleva un correo: un hallazgo mecánico.
        $entrante = MarketingMessage::query()->create(['conversation_id' => self::CANARY_ID, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'Hola, ¿qué planes tienen?', 'status' => 'received']);
        MarketingMessage::query()->create(['conversation_id' => self::CANARY_ID, 'direction' => MarketingMessage::DIRECTION_OUTBOUND, 'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'Escríbenos a ventas.secretas@ironbody.test y te contamos', 'status' => 'sent']);
        $this->assertNotNull($entrante->id);
        Log::spy();

        $this->later(25);
        $r = $this->studio('GET', "versions/{$v->uuid}/canary-evidence", $this->root)->assertOk();
        $acta = $r->json('data.acta');

        $this->assertSame(1, $acta['exitCode'], 'el acta no pasa: un hallazgo mecánico');
        $this->assertSame(1, $acta['total']);
        $this->assertSame(1, $acta['counts']['datos_personales']);
        $this->assertContains('acta', $r->json('data.failing'));
        foreach (['ventas.secretas', 'ironbody.test', 'qué planes', (string) self::CANARY_ID, 'turno 1', 'Prospecto'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r->getContent(), "La evidencia lleva «{$prohibido}».");
        }
        // Ni al log.
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_sin_canario_configurado_no_hay_turnos_ni_acta(): void
    {
        $v = $this->inCanary();
        $this->later(25);
        config(['marketing.ultron.canary_conversation_id' => null]);

        $e = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame([0, 0], [$e['turns'], $e['stamped']]);
        $this->assertSame(['exitCode' => 1, 'total' => null, 'counts' => null], $e['acta']);
        $this->assertContains('turns', $e['failing']);
        $this->assertContains('acta', $e['failing']);
        // Y la deriva lo dice: la versión declara canario y el motor ya no lo tiene.
        $this->assertSame([['param' => 'governance.canaryOnly', 'declared' => true, 'engine' => false]], $e['drift']);
    }

    public function test_una_lectura_caida_cuenta_como_deriva_y_bloquea_la_promocion(): void
    {
        // Q-5: falla cerrado, igual que `engine_reading_unavailable` al publicar.
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        $this->later(25);
        config(['marketing.ai.driver' => ['lectura rota']]);

        $e = $this->evidenceOf($this->root, $v->uuid);
        $this->assertSame([['param' => 'intent.driver', 'declared' => 'entorno', 'engine' => null]], $e['drift']);
        $this->assertSame(['drift'], $e['failing']);
        $r = $this->promoteAs($this->root, $v->uuid, null, $e['evidenceHash'])->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient');
        $this->assertSame($e, $r->json('evidence'));
        // El estado de la página y el comando de comprobación también lo ven.
        $this->studio('GET', 'status', $this->root)->assertOk()->assertJsonPath('data.engine.publication.drift', [['param' => 'intent.driver', 'declared' => 'entorno', 'engine' => null]]);
        $this->artisan('ultron-studio:publication-check')->assertFailed();
    }

    public function test_una_cabeza_que_no_verifica_falla_signature_ok(): void
    {
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        $this->later(25);
        $this->assertTrue($this->evidenceOf($this->root, $v->uuid)['signatureOk']);

        // Una entrada escrita a mano encima, sin la clave: el motor la sella `fallback`.
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('a', 64)]);
        $e = $this->evidenceOf($this->root, $v->uuid);
        $this->assertFalse($e['signatureOk']);
        $this->assertContains('signatureOk', $e['failing']);
    }

    public function test_promover_exige_la_misma_evidencia_que_se_vio(): void
    {
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        $this->later(25);
        $vista = $this->evidenceOf($this->root, $v->uuid);
        // Llega un turno más mientras alguien mira la evidencia.
        $this->stampTurns(1, $v->uuid, now()->subMinutes(5));

        $r = $this->promoteAs($this->root, $v->uuid, null, $vista['evidenceHash'])->assertStatus(409)->assertJsonPath('code', 'evidence_changed');
        $this->assertSame(4, $r->json('evidence.turns'));
        $this->assertSame('CANARY', $v->fresh()->status);
        $this->promoteAs($this->root, $v->uuid, null, $r->json('evidence.evidenceHash'))->assertCreated();
        $this->assertSame('PUBLISHED', $v->fresh()->status);
    }

    public function test_la_evidencia_es_solo_de_la_version_en_canario(): void
    {
        $aprobada = $this->approvedVersion($this->ana, $this->root, 'Solo aprobada');
        $this->studio('GET', "versions/{$aprobada->uuid}/canary-evidence", $this->root)
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'APPROVED')->assertJsonPath('allowedFrom', ['CANARY']);
        $this->studio('GET', 'versions/'.Str::uuid().'/canary-evidence', $this->root)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
    }

    public function test_la_evidencia_tiene_su_propio_limitador_por_persona(): void
    {
        $v = $this->inCanary();
        $primera = $this->actingAsAdmin($this->root);
        $segunda = $this->actingAsAdmin($this->root);
        for ($i = 1; $i <= StudioPublications::EVIDENCE_PER_MINUTE; $i++) {
            $this->json('GET', "/api/admin/ultron-studio/versions/{$v->uuid}/canary-evidence", [], $i % 2 === 0 ? $segunda : $primera)->assertOk();
        }
        $r = $this->json('GET', "/api/admin/ultron-studio/versions/{$v->uuid}/canary-evidence", [], $segunda)->assertStatus(429);
        $r->assertJsonPath('code', 'too_many_requests')->assertJsonPath('message', 'Demasiadas consultas seguidas a la evidencia del canario. Espera un minuto.');
        $this->assertGreaterThan(0, $r->json('retry_after'));
        // Otra pestaña u otra sesión de la misma persona no dan más; otra persona sí tiene el suyo.
        $this->json('GET', "/api/admin/ultron-studio/versions/{$v->uuid}/canary-evidence", [], $this->actingAsAdmin($this->root))->assertStatus(429);
        $this->evidenceOf($this->ana, $v->uuid);
        // El cupo de lectura es otro.
        $this->json('GET', '/api/admin/ultron-studio/status', [], $primera)->assertOk();
    }

    /**
     * AS-2 de la revisión de la API (25-10): promover calcula la evidencia
     * entera, acta incluida, así que comparte el cubo de la evidencia (10/min
     * por persona). Diez lecturas y el intento de promover desde otra sesión
     * de la misma persona es el undécimo: 429. Otra persona tiene su cubo.
     */
    public function test_promover_comparte_el_limitador_de_la_evidencia(): void
    {
        $v = $this->inCanary();
        $primera = $this->actingAsAdmin($this->root);
        for ($i = 1; $i <= StudioPublications::EVIDENCE_PER_MINUTE; $i++) {
            $this->json('GET', "/api/admin/ultron-studio/versions/{$v->uuid}/canary-evidence", [], $primera)->assertOk();
        }
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('0', 64)];
        $r = $this->json('POST', "/api/admin/ultron-studio/versions/{$v->uuid}/promote", $cuerpo, $this->actingAsAdmin($this->root));
        $r->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertJsonPath('message', 'Demasiadas consultas seguidas a la evidencia del canario. Espera un minuto.');
        $this->assertSame(1, StudioPublication::query()->count(), 'el 429 no escribe en la cadena');
        // Otra persona pasa el limitador (y cae en la puerta que le toque, nunca en 429).
        $this->assertNotSame(429, $this->promoteAs($this->ana, $v->uuid)->status());
    }

    public function test_ninguna_respuesta_de_la_publicacion_lleva_el_canario_ni_la_cadena_firmada(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $v = $this->inCanary();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        $this->later(25);
        $fila = StudioPublication::query()->sole();

        $cuerpos = '';
        foreach (['status', 'publications', "versions/{$v->uuid}", "versions/{$v->uuid}/publication-preview", "versions/{$v->uuid}/canary-evidence", 'engine/readings', 'audit'] as $ruta) {
            $cuerpos .= $this->studio('GET', $ruta, $this->root)->assertOk()->getContent();
        }
        $cuerpos .= $this->promoteAs($this->root, $v->uuid)->assertCreated()->getContent();

        foreach ([(string) self::CANARY_ID, '3009998877', 'Prospecto del canario', $fila->signature, $fila->effective_json, '"effective_json"', '"signature"'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpos, "Una respuesta de la publicación lleva «{$prohibido}».");
        }
        $this->assertSame(StudioCanaryEvidence::BY_STATE, array_keys($this->evidenceOfLast($v)));
    }

    /** @return array<string, int> el reparto de la última promoción, guardado en la cadena */
    private function evidenceOfLast(StudioVersion $v): array
    {
        return StudioPublication::query()->where('version_id', $v->id)->where('action', 'promote')->sole()->evidence['byState'];
    }
}
