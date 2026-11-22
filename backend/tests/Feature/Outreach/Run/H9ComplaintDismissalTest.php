<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\Admin;
use App\Models\MarketingAiAction;
use App\Observers\Commercial\ComplaintCommercialEmitter;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\EngineFacts;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Q1-8 (b) · DESCARTAR UNA QUEJA QUE NO LO ERA (Enmienda Q1-8 del contrato H9):
 * al resolver en el Inbox una revisión marcada como queja con
 * `complaint_category = not_a_complaint`, las quejas que marcó la IA en esa
 * conversación desde la resolución anterior dejan de frenar
 * (`complaint_dismissed` con la clave de cada una y quién lo decidió). Sobre
 * las clases REALES: la ruta del Inbox, `MarketingStaffReviewService`,
 * `ComplaintCommercialEmitter`, el observador de la IA, `EngineFacts` y el
 * planificador. Y las mutaciones del predicado del lector: la fuente, la
 * ventana, la clave y lo que no se puede leer.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z).
 */
class H9ComplaintDismissalTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private ?Admin $quien = null;

    /** @var array<string, string> */
    private array $sesion = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
        $this->quien = $this->threePeople()['ana'];
        $this->sesion = $this->actingAsAdmin($this->quien);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->sesion = $this->actingAsAdmin($this->quien);
    }

    /** La queja que marca la IA en una conversación, por el emisor REAL, y la revisión que deja abierta. */
    private function quejaDeLaIa(int $leadId, int $conversacion): void
    {
        MarketingAiAction::create([
            'lead_id' => $leadId, 'conversation_id' => $conversacion,
            'action_type' => 'staff_review', 'reason' => 'complaint', 'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ]);
        DB::table('marketing_conversations')->where('id', $conversacion)->update(['staff_review_pending' => true, 'staff_review_reason' => 'complaint']);
    }

    private function resolver(int $conversacion, array $cuerpo = []): TestResponse
    {
        return $this->postJson("/api/admin/marketing/inbox/conversations/{$conversacion}/staff-review/resolve", $cuerpo, $this->sesion);
    }

    private function conversacionDe(int $leadId): int
    {
        return (int) DB::table('marketing_conversations')->where('lead_id', $leadId)->orderBy('id')->value('id');
    }

    /** ¿Frena hoy la queja a esta persona en lo comercial? Por la foto REAL (BatchSnapshotBuilder → EngineFacts). */
    private function frena(string $clave, string $ahora = self::NOW): ?bool
    {
        $eventos = CoreProjection::snapshot($this->snap([$clave], $ahora, CoreProjection::selectionFor(['events' => ['complaint_created']]))->subject($clave))->events;

        return LadderInputBuilder::complaintRecently($eventos, self::utcAt($ahora), 'commercial');
    }

    /** @return list<string> las claves de las quejas, sin el prefijo del evento */
    private static function claves(string $evento = 'complaint_created'): array
    {
        return array_map(static fn ($k): string => substr((string) $k, strlen($evento) + 1), DB::table('commercial_events')->where('event', $evento)->orderBy('id')->pluck('dedupe_key')->all());
    }

    public function test_sin_categoria_todo_como_hoy_y_la_queja_sigue_frenando(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);

        $r = $this->resolver($c, ['note' => 'Lo vimos en recepción'])->assertOk();
        $this->assertSame(['ok' => true, 'staff_review_pending' => false], $r->json(), 'la forma de siempre');
        $this->assertFalse((bool) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_pending'));
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
        $this->assertTrue($this->frena($socio['key']));
    }

    public function test_not_a_complaint_deja_de_frenar_queda_quien_lo_decidio_y_no_borra_nada(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        $queja = DB::table('commercial_events')->where('event', 'complaint_created')->first();
        $this->assertTrue($this->frena($socio['key']));

        $r = $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertOk();
        $this->assertSame(['ok' => true, 'staff_review_pending' => false, 'complaint' => ['category' => 'not_a_complaint', 'dismissed' => 1]], $r->json());

        $d = DB::table('commercial_events')->where('event', 'complaint_dismissed')->first();
        $this->assertSame('complaint_dismissed:'.$queja->dedupe_key, $d->dedupe_key, 'referencia la queja por su clave');
        $this->assertSame([(int) $queja->member_id, (int) $queja->marketing_lead_id], [(int) $d->member_id, (int) $d->marketing_lead_id], 'la misma persona: la supresión 1581 la alcanza igual');
        $carga = json_decode((string) $d->payload, true);
        ksort($carga);
        $this->assertSame(['category' => 'not_a_complaint', 'complaint_event_id' => (int) $queja->id, 'conversation_id' => $c, 'dismissed_by' => (int) $this->quien->id], $carga);
        $this->assertSame((array) $queja, (array) DB::table('commercial_events')->where('id', $queja->id)->first(), 'la queja sigue ahí, igual');
        $this->assertSame((int) $this->quien->id, (int) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_resolved_by'));
        $this->assertFalse($this->frena($socio['key']));

        // De extremo a extremo: ya no sale por la queja; se planifica.
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->first();
        $this->assertFalse(json_decode((string) $plan->trace_json, true)[0]['policy']['assisted:whatsapp']['input']['context']['complaintRecently']);
        foreach (DB::table('outreach_decisions')->orderBy('id')->get() as $decision) {
            $this->assertSame(OutreachReplay::SAME, (new OutreachReplay)->replay((string) $decision->uuid)['result']);
        }

        // Repetir la resolución no descarta nada más (lo ya resuelto no se toca): sin revisión pendiente, 409 (NB1).
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertStatus(409)->assertJsonPath('code', 'staff_review_not_pending');
        $this->assertSame(1, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
    }

    /**
     * NB1 (`informe-apisec-quejas.md`, sonda C): con la revisión ya resuelta (motivo `complaint`, sin pendiente), una
     * queja que la IA registró DESPUÉS no la ha visto nadie. «No era una queja» no la descarta: 409
     * `staff_review_not_pending`, nada escrito, la resolución anterior intacta y la queja frenando. Antes: 200
     * `dismissed: 1`.
     */
    public function test_sin_revision_pendiente_no_se_descarta_nada_409(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->reloj('2026-11-16 15:00:00');
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->resolver($c, ['complaint_category' => 'complaint_confirmed'])->assertOk();
        $resuelta = DB::table('marketing_conversations')->where('id', $c)->first(['staff_review_pending', 'staff_review_reason', 'staff_review_resolved_at', 'staff_review_resolved_by']);
        $this->assertSame([false, 'complaint'], [(bool) $resuelta->staff_review_pending, $resuelta->staff_review_reason]);

        // Hoy la IA registra otra queja en la misma conversación, pero la revisión no se reabre.
        $this->reloj(self::NOW);
        MarketingAiAction::create([
            'lead_id' => $socio['lead'], 'conversation_id' => $c,
            'action_type' => 'staff_review', 'reason' => 'complaint', 'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ]);
        $this->assertCount(2, self::claves(), 'la de ayer (confirmada) y la de hoy');

        $this->resolver($c, ['complaint_category' => 'not_a_complaint', 'note' => 'no lo era'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'code' => 'staff_review_not_pending', 'message' => 'Esta revisión ya está resuelta: no hay ninguna queja pendiente que descartar.']);
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
        $this->assertEquals($resuelta, DB::table('marketing_conversations')->where('id', $c)->first(['staff_review_pending', 'staff_review_reason', 'staff_review_resolved_at', 'staff_review_resolved_by']), 'la resolución de ayer, intacta');
        $this->assertSame(0, DB::table('marketing_conversation_notes')->count(), 'ni la nota');
        $this->assertTrue($this->frena($socio['key']), 'la queja de hoy sigue frenando');

        // Confirmar o «otra» sin pendiente siguen como antes (vuelven a resolver; no tocan quejas).
        $this->resolver($c, ['complaint_category' => 'complaint_confirmed'])->assertOk()->assertJsonPath('complaint.dismissed', 0);
        $this->assertTrue($this->frena($socio['key']));
    }

    public function test_complaint_confirmed_y_other_siguen_frenando(): void
    {
        foreach (['complaint_confirmed', 'other'] as $i => $categoria) {
            $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '300123456'.$i);
            $c = $this->conversacionDe($socio['lead']);
            $this->quejaDeLaIa($socio['lead'], $c);
            $this->resolver($c, ['complaint_category' => $categoria])->assertOk()->assertJsonPath('complaint', ['category' => $categoria, 'dismissed' => 0]);
            $this->assertFalse((bool) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_pending'));
            $this->assertTrue($this->frena($socio['key']), $categoria);
        }
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
    }

    public function test_una_queja_del_personal_nunca_se_descarta(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        app(ComplaintCommercialEmitter::class)->recordStaffComplaint('member', $socio['member'], 'in_person', 'billing', (int) $this->quien->id, 'aaaaaaaa-0000-4000-8000-000000000001', now());

        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 1);
        $this->assertSame(['conversation:'.$c.':complaint:20261117'], array_map(static fn (string $k): string => substr($k, strlen('complaint_created:')), self::claves('complaint_dismissed')), 'solo la de la IA');
        $this->assertTrue($this->frena($socio['key']), 'la del personal sigue frenando');

        // Aunque alguien forzara un descarte con la clave de la del personal, el lector no lo acepta (la fuente).
        $staff = DB::table('commercial_events')->where('event', 'complaint_created')->where('dedupe_key', 'like', '%:staff:%')->first();
        app(CommercialEventRecorder::class)->record('complaint_dismissed', ['member_id' => (int) $staff->member_id, 'lead_id' => null], [], (string) $staff->dedupe_key);
        $this->assertSame(1, DB::table('commercial_events')->where('dedupe_key', 'complaint_dismissed:'.$staff->dedupe_key)->count());
        $this->assertTrue($this->frena($socio['key']));

        // Y el emisor tampoco descarta una con la clave de la conversación si su fuente no es la IA.
        $otro = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234568');
        $c2 = $this->conversacionDe($otro['lead']);
        app(CommercialEventRecorder::class)->record('complaint_created', ['lead_id' => $otro['lead'], 'member_id' => $otro['member']],
            ['source' => 'staff', 'conversation_id' => $c2], "conversation:{$c2}:complaint:20261117", now());
        DB::table('marketing_conversations')->where('id', $c2)->update(['staff_review_pending' => true, 'staff_review_reason' => 'complaint']);
        $this->resolver($c2, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 0);
        $this->assertTrue($this->frena($otro['key']));
    }

    public function test_ni_otra_conversacion_ni_lo_ya_resuelto(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $a = $this->conversacionDe($socio['lead']);
        $b = $this->conversation($socio['lead']);
        // Ayer, una queja real en A, confirmada.
        $this->reloj('2026-11-16 15:00:00');
        $this->quejaDeLaIa($socio['lead'], $a);
        $this->resolver($a, ['complaint_category' => 'complaint_confirmed'])->assertOk();
        // Hoy, otra en A (falsa) y una en B.
        $this->reloj(self::NOW);
        $this->quejaDeLaIa($socio['lead'], $a);
        $this->quejaDeLaIa($socio['lead'], $b);

        $this->resolver($a, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 1);
        $this->assertSame(["conversation:{$a}:complaint:20261117"], array_map(static fn (string $k): string => substr($k, strlen('complaint_created:')), self::claves('complaint_dismissed')));
        $this->assertTrue($this->frena($socio['key']), 'la de ayer (confirmada) y la de B siguen frenando');
        $this->resolver($b, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 1);
        $this->assertTrue($this->frena($socio['key']), 'la de ayer, confirmada, sigue');
        $this->assertFalse($this->frena($socio['key'], '2026-12-17 05:00:00'), 'la ventana de la confirmada sí caduca (día 31)');
    }

    public function test_una_queja_nueva_despues_de_un_descarte_vuelve_a_frenar_tambien_el_mismo_dia(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->reloj('2026-11-17 16:00:00');
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 1);
        $this->assertFalse($this->frena($socio['key'], '2026-11-17 16:00:00'));

        // Una hora después, en la misma conversación, otra queja: el mismo día, otra clave, y frena.
        $this->reloj('2026-11-17 17:00:00');
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->assertSame(["conversation:{$c}:complaint:20261117", "conversation:{$c}:complaint:20261117:2"], self::claves());
        $this->assertTrue($this->frena($socio['key'], '2026-11-17 17:00:00'));
        // Otra más ese día, sin descartar: es la misma (una al día).
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->assertCount(2, self::claves());

        // Se descarta también; la siguiente abre la tercera.
        $this->reloj('2026-11-17 18:00:00');
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertOk()->assertJsonPath('complaint.dismissed', 1);
        $this->assertFalse($this->frena($socio['key'], '2026-11-17 18:00:00'));
        $this->reloj('2026-11-17 19:00:00');
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->assertSame("conversation:{$c}:complaint:20261117:3", self::claves()[2]);
        $this->assertTrue($this->frena($socio['key'], '2026-11-17 19:00:00'));
    }

    public function test_la_carrera_un_descarte_entre_la_comprobacion_y_el_registro_no_se_traga_la_queja_nueva(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        $base = "conversation:{$c}:complaint:20261117";
        $queja = DB::table('commercial_events')->where('event', 'complaint_created')->first();
        // Otra queja de la IA una hora después. Justo después de que el observador compruebe que la del día sigue viva,
        // otra persona la descarta (determinista: un escuchador de consultas lo hace en ese instante).
        $this->reloj('2026-11-17 16:00:00');
        $hecho = false;
        DB::listen(function (QueryExecuted $q) use (&$hecho, $base, $queja): void {
            if (! $hecho && in_array('complaint_dismissed:complaint_created:'.$base, $q->bindings, true)) {
                $hecho = true;
                app(CommercialEventRecorder::class)->record('complaint_dismissed', ['lead_id' => (int) $queja->marketing_lead_id, 'member_id' => (int) $queja->member_id],
                    ['complaint_event_id' => (int) $queja->id], 'complaint_created:'.$base, now());
            }
        });
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->assertTrue($hecho);
        $this->assertSame([$base, "{$base}:2"], self::claves(), 'la queja nueva no se pierde dentro del duplicado de la descartada');
        $this->assertTrue($this->frena($socio['key'], '2026-11-17 16:00:00'));
    }

    public function test_la_categoria_en_una_revision_que_no_es_queja_y_la_categoria_mala_son_422_y_nada_cambia(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        DB::table('marketing_conversations')->where('id', $c)->update(['staff_review_reason' => 'payment']);
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertStatus(422)->assertJsonPath('code', 'complaint_category_not_applicable');
        DB::table('marketing_conversations')->where('id', $c)->update(['staff_review_reason' => 'complaint']);
        $this->resolver($c, ['complaint_category' => 'no_era'])->assertStatus(422)->assertJsonValidationErrors('complaint_category');
        $this->assertTrue((bool) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_pending'));
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
        $this->assertTrue($this->frena($socio['key']));
    }

    public function test_con_el_registro_apagado_409_y_la_revision_sigue_pendiente(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        config(['commercial.events_enabled' => false]);
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertStatus(409)->assertJsonPath('code', 'commercial_events_disabled');
        $this->assertTrue((bool) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_pending'));
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count());
        // Con la fuente apagada el H9 no lee eventos: N6 niega igual (sin datos, falla cerrado).
        $this->assertNull($this->frena($socio['key']));
    }

    public function test_si_un_descarte_no_queda_es_503_todo_o_nada_y_sigue_frenando(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        // La frontera: el registrador contiene el fallo y devuelve null.
        $this->app->instance(CommercialEventRecorder::class, Mockery::mock(CommercialEventRecorder::class, ['record' => null]));
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertStatus(503)->assertJsonPath('code', 'complaint_dismissal_failed');
        $fila = DB::table('marketing_conversations')->where('id', $c)->first();
        $this->assertSame([true, null], [(bool) $fila->staff_review_pending, $fila->staff_review_resolved_by], 'la revisión sigue pendiente');
        $this->assertTrue($this->frena($socio['key']));
    }

    public function test_si_falla_el_segundo_descarte_no_queda_ninguno(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->reloj('2026-11-16 15:00:00');
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->reloj(self::NOW);
        $this->quejaDeLaIa($socio['lead'], $c);
        $this->assertCount(2, self::claves(), 'dos días, dos quejas sin resolver');
        // El primero se registra de verdad; el segundo, el registrador lo contiene y devuelve null.
        $real = new CommercialEventRecorder;
        $n = 0;
        $doble = Mockery::mock(CommercialEventRecorder::class);
        $doble->shouldReceive('record')->andReturnUsing(function (...$args) use ($real, &$n) {
            return ++$n === 1 ? $real->record(...$args) : null;
        });
        $this->app->instance(CommercialEventRecorder::class, $doble);
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertStatus(503);
        $this->assertSame(2, $n);
        $this->assertSame(0, DB::table('commercial_events')->where('event', 'complaint_dismissed')->count(), 'todo o nada');
        $this->assertTrue((bool) DB::table('marketing_conversations')->where('id', $c)->value('staff_review_pending'));
        $this->assertTrue($this->frena($socio['key']));
    }

    public function test_el_predicado_del_lector_fuente_clave_ventana_y_lo_que_no_se_puede_leer(): void
    {
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $c = $this->conversacionDe($socio['lead']);
        $this->quejaDeLaIa($socio['lead'], $c);
        $queja = DB::table('commercial_events')->where('event', 'complaint_created')->first();

        // Un descarte POSTERIOR a «ahora» no cuenta todavía.
        $this->reloj('2026-11-17 16:00:00');
        $this->resolver($c, ['complaint_category' => 'not_a_complaint'])->assertOk();
        $this->assertTrue($this->frena($socio['key'], '2026-11-17 15:30:00'), 'antes del descarte, frena');
        $this->assertFalse($this->frena($socio['key'], '2026-11-17 16:00:00'));

        // Lo que no se puede leer cuenta como queja: la carga de la queja sin fuente, o ilegible.
        foreach (['{}', '{"source":"otra"}', 'no es json', '{"source":"staff"}'] as $carga) {
            DB::table('commercial_events')->where('id', $queja->id)->update(['payload' => $carga === 'no es json' ? null : $carga]);
            $this->assertTrue($this->frena($socio['key'], '2026-11-17 16:00:00'), $carga);
        }
        DB::table('commercial_events')->where('id', $queja->id)->update(['payload' => $queja->payload]);
        $this->assertFalse($this->frena($socio['key'], '2026-11-17 16:00:00'));

        // Un descarte con OTRA clave no la toca; con la queja sin clave, tampoco.
        $otro = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234568');
        $this->commercialEvent('complaint_created', '2026-11-17 14:00:00', $otro['member']);
        app(CommercialEventRecorder::class)->record('complaint_dismissed', ['member_id' => $otro['member'], 'lead_id' => null], [], 'complaint_created:conversation:999:complaint:20261117', now());
        $this->assertTrue($this->frena($otro['key'], '2026-11-17 16:00:00'));

        // EngineFacts::events() da solo los tipos pedidos: el descarte se lee para descontar, no sale si no se pide.
        $motor = new EngineFacts;
        $soloQuejas = $motor->events($this->pass([$socio['key']], '2026-11-17 15:30:00'), ['complaint_created'])[$socio['key']];
        $this->assertSame(['complaint_created'], array_column($soloQuejas, 'key'), 'antes del descarte');
        $this->assertSame([], $motor->events($this->pass([$socio['key']], '2026-11-17 16:00:00'), ['complaint_created'])[$socio['key']], 'después: ni la queja ni el descarte');
        $this->assertSame(['complaint_dismissed'], array_column($motor->events($this->pass([$socio['key']], '2026-11-17 16:00:00'), ['complaint_dismissed'])[$socio['key']], 'key'), 'el descarte, si se pide');
        // Un descarte solo descuenta QUEJAS: con la clave de otro hecho de la IA, ese hecho sigue.
        app(CommercialEventRecorder::class)->record('human_requested', ['lead_id' => $socio['lead'], 'member_id' => $socio['member']], ['source' => 'ultron'], "conversation:{$c}:takeover:1", now());
        app(CommercialEventRecorder::class)->record('complaint_dismissed', ['lead_id' => $socio['lead'], 'member_id' => $socio['member']], [], "human_requested:conversation:{$c}:takeover:1", now());
        $leidos = $motor->events($this->pass([$socio['key']], '2026-11-17 16:00:00'), ['complaint_created', 'human_requested'])[$socio['key']];
        $this->assertSame(['human_requested'], array_column($leidos, 'key'));

        // Con la fuente de eventos apagada no se sabe: null (N6 niega), haya descartes o no.
        config(['commercial.events_enabled' => false]);
        $this->assertNull($this->frena($socio['key'], '2026-11-17 16:00:00'));
    }
}
