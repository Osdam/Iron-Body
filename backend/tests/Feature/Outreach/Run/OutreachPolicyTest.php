<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Facts\SubjectTimeline;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use App\Services\Outreach\Run\OutreachStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * La escalera EN LA EJECUCIÓN (contrato H9, D9-7, D9-8 etapa 3; §13.1 #5,
 * #11; T17, T21; D9-12 replay): la prioridad entre dos estrategias para la
 * misma persona, la regla de un canal por semana, los frenos y banderas de N2
 * con su motivo exacto, `human_requested` reciente y la reproducibilidad.
 */
class OutreachPolicyTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── #5 · dos estrategias para la misma persona ──────────────────────────

    public function test_5_dos_estrategias_para_la_misma_persona_un_solo_trabajo_vivo_y_la_segunda_defer_lower_priority(): void
    {
        $this->runFlags('shadow');
        $plantilla = $this->assistedTemplate();
        $alta = $this->liveStrategy($this->validContent($plantilla, ['priority' => 80]), 'CANARY', ['key' => 'alta']);
        $baja = $this->liveStrategy($this->validContent($plantilla, ['priority' => 30]), 'CANARY', ['key' => 'baja']);
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(2, DB::table('outreach_enrollments')->count(), 'califica en las dos');

        $r = $planner->planDue(now());
        $this->assertSame(2, $r['due']);
        $this->assertSame(1, $r['planned']);
        $this->assertSame(1, $r['deferred']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame($alta['strategy'], (int) $trabajo->strategy_id, 'gana la prioridad mayor');
        $aplazada = DB::table('outreach_decisions')->where('kind', 'defer')->first();
        $this->assertSame($baja['strategy'], (int) $aplazada->strategy_id);
        $this->assertSame('N6', $aplazada->level);
        $this->assertSame(['lower_priority'], json_decode((string) $aplazada->reasons, true));
        $this->assertSame('2026-11-18 05:00:00', (string) $aplazada->retry_at, 'hasta las 00:00 de Bogotá del día siguiente');
        $this->assertSame('2026-11-18 05:00:00', (string) DB::table('outreach_enrollments')->where('strategy_id', $baja['strategy'])->value('next_due_at'));
        $this->assertSame(1, DB::table('outreach_jobs')->count(), 'un solo trabajo vivo por persona');

        // La perdedora no puede colarse: el índice parcial de un trabajo vivo por persona y modo lo rechaza.
        $otra = DB::table('outreach_enrollments')->where('strategy_id', $baja['strategy'])->first();
        $this->assertNull((new OutreachStore)->insertJob($otra, 0, 1, 'simulated', null, null, null, null, self::utcAt(self::NOW), self::utcAt('2026-11-18 15:00:00'), '2026-11-17', ['units' => 0, 'micros' => 0], self::utcAt(self::NOW)));
    }

    // ── #11 · un canal por semana tras un contacto directo ──────────────────

    public function test_11_tras_una_llamada_hace_dos_dias_el_segundo_canal_queda_defer_cross_channel_week_rule_y_el_historial_lo_muestra(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        // Una saliente de voz del H8 hace dos días al número del socio (un contacto directo contado, L9-22).
        $this->voiceContact('member', $socio['member'], '+573001234567');
        $this->outboundCall('+573001234567', 'completed', '2026-11-15 15:00:00');

        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['deferred'], json_encode($r));
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'nunca el segundo canal en la semana');
        $decision = DB::table('outreach_decisions')->where('kind', 'defer')->first();
        $this->assertSame('N1', $decision->level);
        $motivos = json_decode((string) $decision->reasons, true);
        $this->assertContains('cross_channel_week_rule', $motivos);
        $this->assertNotContains('min_hours_between_contacts', $motivos, 'a las 48 h justas las horas mínimas ya no niegan (paridad con ContactPolicy)');
        $this->assertSame('2026-11-23 13:00:00', (string) $decision->retry_at, '7 días + 1 s después de la llamada, en el primer hueco legal (lunes 23-11 a las 08:00 de Bogotá)');
        $this->assertSame((string) $decision->retry_at, (string) DB::table('outreach_enrollments')->value('next_due_at'));

        // El historial unificado por persona enseña la decisión aplazada con su motivo.
        $historial = (new SubjectTimeline)->forSubject($socio['key']);
        $this->assertTrue($historial['available']);
        $aplazada = array_values(array_filter($historial['entries'], static fn (array $x): bool => $x['kind'] === 'decision' && $x['result'] === 'defer'));
        $this->assertCount(1, $aplazada);
        $this->assertSame('cross_channel_week_rule', $aplazada[0]['reason']);
        $this->assertSame('retry', $aplazada[0]['nextAction']);
        $this->assertSame('2026-11-23T13:00:00Z', $aplazada[0]['nextAt']);
    }

    // ── T17 · N2: nada sale, con el motivo exacto ───────────────────────────

    public function test_t17_la_autonomia_del_motor_encendida_niega_en_vivo_y_avisa_en_sombra(): void
    {
        config(['commercial.autonomy_enabled' => true]);
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['planned'], 'en sombra es un aviso (D9-1): se anota y se simula');
        $plan = json_decode((string) DB::table('outreach_decisions')->where('kind', 'plan')->value('trace_json'), true)[0];
        $this->assertContains(['level' => 'N2', 'reason' => 'legacy_autonomy_on'], $plan['policy']['assisted:whatsapp']['output']['warnings']);

        // En vivo: N2 niega con el motivo exacto y la inscripción espera (no se cancela nada, no se contacta). Los dos socios
        // vuelven a inscribirse en vivo (otro modo, otra ocurrencia) y los dos esperan.
        $this->runFlags('live');
        $this->qualifyingMember(self::utcAt(self::NOW), 6, '3001234568');
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(2, $r['waiting'], json_encode($r));
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->orderByDesc('id')->first();
        $this->assertSame('N2', $negada->level);
        $this->assertSame(['legacy_autonomy_on'], json_decode((string) $negada->reasons, true));
        $this->assertSame(1, DB::table('outreach_jobs')->count(), 'ningún trabajo nuevo en vivo');
        $this->assertSame(['2026-11-17 15:15:00', '2026-11-17 15:15:00'], DB::table('outreach_enrollments')->where('mode', 'live')->pluck('next_due_at')->map(fn ($v) => (string) $v)->all(), 'esperan a que se suelte');
    }

    public function test_t17_una_conversacion_tomada_por_una_persona_niega_en_n2(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        DB::table('marketing_conversations')->where('lead_id', $socio['lead'])->update(['human_takeover' => true]);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['waiting']);
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->first();
        $this->assertSame('N2', $negada->level);
        $this->assertSame(['human_in_control'], json_decode((string) $negada->reasons, true));
        $this->assertSame(0, DB::table('outreach_jobs')->count());
    }

    // ── T21 · N6: human_requested reciente deriva y para ────────────────────

    public function test_t21_human_requested_reciente_deriva_y_para_la_secuencia(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $this->commercialEvent('human_requested', '2026-11-10 15:00', $socio['member']);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['handed_off']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('handed_off', $e->status);
        $this->assertSame('human_requested_recently', $e->exit_reason);
        $this->assertSame('handoff', DB::table('outreach_decisions')->where('level', 'N6')->value('kind'));
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'ningún contacto automático');
    }

    // ── T12 · reproducibilidad ──────────────────────────────────────────────

    public function test_t12_replay_da_la_misma_huella_y_no_escribe_nada(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $antes = DB::table('outreach_decisions')->count() + DB::table('outreach_jobs')->count() + DB::table('outreach_runs')->count();
        foreach (DB::table('outreach_decisions')->pluck('uuid') as $uuid) {
            $r = (new OutreachReplay)->replay((string) $uuid);
            $this->assertSame(OutreachReplay::SAME, $r['result'], json_encode($r));
            $this->assertSame($r['storedHash'], $r['replayedHash']);
        }
        $this->assertSame($antes, DB::table('outreach_decisions')->count() + DB::table('outreach_jobs')->count() + DB::table('outreach_runs')->count(), 'solo lectura');
        $this->artisan('outreach:replay', ['decision' => (string) DB::table('outreach_decisions')->where('kind', 'plan')->value('uuid')])->expectsOutputToContain('LA MISMA huella')->assertExitCode(0);
        $this->assertSame('completed', DB::table('outreach_runs')->where('kind', 'replay')->value('status'));
        $this->artisan('outreach:replay', ['decision' => '00000000-0000-4000-8000-000000000000'])->assertExitCode(2);
    }
}
