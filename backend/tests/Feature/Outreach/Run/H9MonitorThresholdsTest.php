<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachMonitor;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachStop;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #18 «freno si supera el umbral» (P9-20), T15 y D9-14; REV9 M-3,
 * TEST9-c: los tres umbrales del monitor —`opt_out_spike` (de la estrategia),
 * `failure_spike` (del canal) y `budget_breach` (del canal)— con filas que
 * salen de la ejecución REAL (trabajos que salieron por la bandeja y sus
 * resultados del personal, fallos del canal, reservas de la planificación):
 * la muestra mínima (una muestra de 4 no abre nada), la comparación ESTRICTA
 * en tanto por mil («>»), la ventana de 24 h, el incidente de IRON GUARD con
 * sus cifras, la idempotencia (uno abierto por alcance), lo que retiene cada
 * freno (el barrido salta la estrategia; el despachador no reclama el canal)
 * y la regla de oro: EL MONITOR NUNCA SUELTA NADA. Lo suelta una persona, y
 * si la causa sigue, el monitor lo vuelve a echar.
 *
 * `chain_unreadable` y `drift` están en `OutreachStopAndGateTest`.
 */
class H9MonitorThresholdsTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const LATER = '2026-11-17 15:15:00';

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

    /**
     * EN VIVO con el monitor encendido: una estrategia viva, cinco socios que
     * califican (cinco teléfonos) y sus cinco trabajos asistidos planificados
     * con su reserva; el reloj queda en LATER (los cinco vencidos).
     *
     * @return array{live: array<string, mixed>, members: list<array<string, mixed>>}
     */
    private function fivePlanned(): array
    {
        $this->runFlags('live', ['outreach.monitor.enabled' => true]);
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socios = [];
        for ($i = 1; $i <= 5; $i++) {
            $socios[] = $this->qualifyingMember(self::utcAt(self::NOW), 5, '300123456'.$i);
        }
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(5, $r['planned'], json_encode($r));
        $this->assertSame(5, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('reserved_units'));
        Carbon::setTestNow(Carbon::parse(self::LATER, 'UTC'));

        return ['live' => $vivo, 'members' => $socios];
    }

    /** El incidente de IRON GUARD de un freno del monitor: su evidencia decodificada. @return array<string, mixed> */
    private function incidentEvidence(string $scope, string $scopeRef): array
    {
        $filas = DB::table('incidents')->where('source', OutreachStop::INCIDENT_SOURCE)->where('kind', OutreachStop::INCIDENT_KIND)->orderBy('id')->get(['severity', 'evidence']);
        foreach ($filas as $f) {
            $evidencia = json_decode((string) $f->evidence, true);
            if (($evidencia['scope'] ?? null) === $scope && ($evidencia['scope_ref'] ?? null) === $scopeRef) {
                $this->assertSame('high', $f->severity);

                return $evidencia;
            }
        }
        $this->fail("No hay incidente del freno {$scope}:{$scopeRef}.");
    }

    /**
     * Pasadas del monitor que no abren nada más: el freno sigue tal cual, uno
     * abierto por alcance, nunca suelto; y pasada la ventana de 24 h la causa
     * desaparece de las cifras, pero el freno no se mueve. El reloj se le
     * pasa siempre (`run(now())`): sin argumento usa el reloj real.
     */
    private function assertMonitorNeverReleases(OutreachMonitor $monitor, int $abiertos, int $flaggedNow): void
    {
        $r = $monitor->run(now());
        $this->assertSame([], $r['opened'], 'uno abierto por alcance: no se duplica');
        $this->assertSame($flaggedNow, array_sum(array_map(static fn (array $c): int => (int) ($c['flagged'] ?? 0), $r['checks'])), 'la causa sigue ahí: se marca, no se vuelve a abrir');
        $antes = Carbon::now();
        Carbon::setTestNow($antes->copy()->addHours(25));
        $r = $monitor->run(now());
        $this->assertSame([], $r['opened']);
        $this->assertSame(0, array_sum(array_map(static fn (array $c): int => (int) ($c['flagged'] ?? 0), $r['checks'])), 'fuera de la ventana de 24 h (o de su día) la causa ya no cuenta');
        Carbon::setTestNow($antes);
        $this->assertSame($abiertos, DB::table('outreach_brakes')->whereNull('released_at')->count());
        $this->assertSame(0, DB::table('outreach_brakes')->whereNotNull('released_at')->count(), 'el monitor nunca suelta');
        $this->assertGreaterThan(0, DB::table('outreach_runs')->where('kind', 'monitor')->where('status', 'completed')->count(), 'cada pasada deja su fila');
    }

    // ── opt_out_spike · de la estrategia ────────────────────────────────────

    public function test_18_opt_out_por_encima_del_umbral_en_24_h_echa_el_freno_de_la_estrategia_con_incidente_y_el_monitor_nunca_lo_suelta(): void
    {
        $gente = $this->threePeople();
        $w = $this->fivePlanned();
        $uuid = $w['live']['strategyUuid'];
        $monitor = new OutreachMonitor;
        $cola = new AssistedQueue;

        // Salen cuatro por la bandeja y los cuatro piden no recibir más: 4 de 4, pero la muestra no llega a la mínima (5): nada.
        $this->assertSame(['started' => 4], (new OutreachDispatcher)->dispatch(now(), 4, true)['results']);
        foreach (DB::table('outreach_jobs')->where('status', 'started')->orderBy('id')->pluck('uuid') as $trabajo) {
            $cola->claim($gente['ana'], (string) $trabajo, (string) Str::uuid());
            $this->assertSame('end_exit', $cola->recordOutcome($gente['ana'], (string) $trabajo, 'opted_out', (string) Str::uuid())['transition']);
        }
        $this->assertSame(4, DB::table('outreach_outcomes')->where('outcome', 'opted_out')->where('source', 'staff')->count());
        $r = $monitor->run(now());
        $this->assertFalse($r['skipped']);
        $this->assertSame([], $r['opened'], 'con 4 de muestra no se juzga una tasa (min_sample = 5)');
        $this->assertSame(0, $r['checks']['optOut']['flagged']);

        // Sale el quinto (no se queja): 4 de 5 = 800 ‰. La comparación es estricta: con el umbral en 800 no abre; en 799, sí.
        $this->assertSame(['started' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        config(['outreach.monitor.opt_out_threshold_permille' => 800]);
        $this->assertSame([], $monitor->run(now())['opened']);
        config(['outreach.monitor.opt_out_threshold_permille' => 799]);
        $r = $monitor->run(now());
        $this->assertSame([['scope' => 'strategy', 'scopeRef' => $uuid, 'reason' => 'opt_out_spike']], $r['opened']);
        $freno = DB::table('outreach_brakes')->first();
        $this->assertSame('strategy', $freno->scope);
        $this->assertSame($uuid, $freno->scope_ref);
        $this->assertSame('opt_out_spike', $freno->reason);
        $this->assertSame('system', $freno->opened_by_type);
        $this->assertNull($freno->opened_by_admin_id);
        $this->assertNull($freno->opened_by_person_id);
        $this->assertNull($freno->note);
        $this->assertNull($freno->released_at);
        $evidencia = $this->incidentEvidence('strategy', $uuid);
        $this->assertSame(4, $evidencia['opted_out']);
        $this->assertSame(5, $evidencia['started']);
        $this->assertSame(799, $evidencia['permille_threshold']);
        $this->assertSame((string) $freno->uuid, $evidencia['brake_uuid']);
        $this->assertSame([$uuid], (new OutreachStop)->openScopes()['strategies']);

        // Lo que sigue en la estrategia espera: el barrido la salta y nadie nuevo se inscribe.
        config(['outreach.monitor.opt_out_threshold_permille' => 50]);
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234566');
        $this->assertSame(['skipped' => 'outreach_brake_strategy'], (new OutreachPlanner)->sweep(now(), true)['strategies'][$uuid]);
        $this->assertSame(5, DB::table('outreach_enrollments')->count());

        $this->assertMonitorNeverReleases($monitor, 1, 1);

        // Solo una PERSONA lo suelta (a cuatro ojos: lo abrió el sistema, no ella); y si la causa sigue, el monitor lo vuelve a echar.
        $suelto = (new OutreachStop)->release($gente['caro'], (string) $freno->uuid, 'Revisado: se observa otra vez', (string) Str::uuid());
        $this->assertTrue($suelto['released']);
        $this->assertSame([], (new OutreachStop)->openScopes()['strategies']);
        $r = $monitor->run(now());
        $this->assertSame([['scope' => 'strategy', 'scopeRef' => $uuid, 'reason' => 'opt_out_spike']], $r['opened']);
        $this->assertSame(2, DB::table('outreach_brakes')->count());
        $this->assertSame(1, DB::table('outreach_brakes')->whereNull('released_at')->count());
    }

    // ── failure_spike · del canal ───────────────────────────────────────────

    public function test_18_fallos_del_canal_por_encima_del_umbral_echan_el_freno_del_canal_y_lo_planificado_de_ese_canal_espera(): void
    {
        $w = $this->fivePlanned();
        $monitor = new OutreachMonitor;
        $roto = new class implements OutreachChannel
        {
            public function name(): string
            {
                return 'assisted';
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                return ChannelAttempt::failed('provider_timeout');
            }
        };
        $conRoto = new OutreachDispatcher(channels: [new SimulatedChannel, $roto, new WhatsappTemplateChannel, new VoiceStrategyChannel]);

        // Dos fallos del canal: 2 de 2, pero la muestra (los que salieron más los que fallaron) no llega a 5.
        $this->assertSame(['failed' => 2], $conRoto->dispatch(now(), 2, true)['results']);
        $this->assertSame(2, DB::table('outreach_jobs')->where('status', 'failed_to_start')->count());
        $r = $monitor->run(now());
        $this->assertSame([], $r['opened']);
        $this->assertSame(0, $r['checks']['failures']['flagged']);

        // Salen bien los otros tres: 2 de 5 = 400 ‰ > 200 ‰ (el defecto). Estricto: en 400 no abre; en 399, sí.
        $this->assertSame(['started' => 3], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        config(['outreach.monitor.failure_threshold_permille' => 400]);
        $this->assertSame([], $monitor->run(now())['opened']);
        config(['outreach.monitor.failure_threshold_permille' => 399]);
        $r = $monitor->run(now());
        $this->assertSame([['scope' => 'channel', 'scopeRef' => 'assisted', 'reason' => 'failure_spike']], $r['opened']);
        $freno = DB::table('outreach_brakes')->first();
        $this->assertSame('channel', $freno->scope);
        $this->assertSame('assisted', $freno->scope_ref);
        $this->assertSame('failure_spike', $freno->reason);
        $this->assertSame('system', $freno->opened_by_type);
        $evidencia = $this->incidentEvidence('channel', 'assisted');
        $this->assertSame(2, $evidencia['failed']);
        $this->assertSame(5, $evidencia['total']);
        $this->assertSame(399, $evidencia['permille_threshold']);
        $this->assertSame(['assisted'], (new OutreachStop)->openScopes()['channels']);

        // Lo planificado de ese canal espera: otra estrategia con un trabajo asistido planificado no se reclama (el simulado, otro canal, no está frenado).
        config(['outreach.monitor.failure_threshold_permille' => 200]);
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['priority' => 70]), 'CANARY', ['key' => 'otra']);
        $inscripcion = $this->insertEnrollment($otra, ['mode' => 'live', 'subject_key' => 'm:7001', 'member_id' => 7001, 'window_ends_at' => '2026-11-20 15:00:00']);
        $this->insertAssistedJob($inscripcion, ['mode' => 'live', 'not_before' => self::NOW, 'expires_at' => '2026-11-17 20:00:00', 'budget_day' => '2026-11-17']);
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->assertSame('planned', DB::table('outreach_jobs')->where('enrollment_id', $inscripcion)->value('status'), 'espera: no sale ni se cancela');
        $this->assertSame(['global' => false, 'channel' => true, 'strategy' => false], OutreachStop::ladderBrakes((new OutreachStop)->openScopes(), $otra['strategyUuid'], 'assisted'));
        $this->assertSame(['global' => false, 'channel' => false, 'strategy' => false], OutreachStop::ladderBrakes((new OutreachStop)->openScopes(), $otra['strategyUuid'], 'simulated'));

        $this->assertMonitorNeverReleases($monitor, 1, 1);
    }

    // ── budget_breach · del canal ───────────────────────────────────────────

    public function test_18_el_gasto_reservado_por_encima_del_techo_que_la_configuracion_dice_ahora_echa_el_freno_del_canal(): void
    {
        $this->fivePlanned();
        $monitor = new OutreachMonitor;
        $this->assertSame([], $monitor->run(now())['opened'], '5 reservadas con techo 20: nada');

        // El techo se baja a 4 con 5 ya reservadas (el contador nunca baja, §3.7 de informe-run): lo reservado supera el techo de AHORA.
        config(['outreach.assisted.daily_cap' => 4]);
        // Y WhatsApp: gasto reservado por encima del presupuesto en dólares (la puerta no existe el 15-11: la fila del contador se siembra).
        config(['outreach.whatsapp.daily_cap' => 10, 'outreach.whatsapp.daily_budget_usd' => '4']);
        $this->insertBudget(['budget_day' => '2026-11-17', 'scope' => 'channel:whatsapp_template', 'mode' => 'live', 'cap_units' => 10, 'cap_micros' => 5_000_000, 'reserved_units' => 1, 'reserved_micros' => 5_000_000]);
        $r = $monitor->run(now());
        $this->assertSame([
            ['scope' => 'channel', 'scopeRef' => 'whatsapp_template', 'reason' => 'budget_breach'],
            ['scope' => 'channel', 'scopeRef' => 'assisted', 'reason' => 'budget_breach'],
        ], $r['opened']);
        $this->assertSame(2, $r['checks']['budget']['flagged']);
        $this->assertSame(['assisted', 'whatsapp_template'], (new OutreachStop)->openScopes()['channels']);
        $this->assertSame(2, DB::table('outreach_brakes')->where('opened_by_type', 'system')->where('reason', 'budget_breach')->count());
        $asistido = $this->incidentEvidence('channel', 'assisted');
        $this->assertSame(5, $asistido['reserved_units']);
        $this->assertSame(4, $asistido['cap_units']);
        $whatsapp = $this->incidentEvidence('channel', 'whatsapp_template');
        $this->assertSame(5_000_000, $whatsapp['reserved_micros']);
        $this->assertSame(4_000_000, $whatsapp['cap_micros']);

        // Los cinco asistidos planificados esperan (canal frenado), ninguno se cancela ni pierde su reserva.
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->assertSame(5, DB::table('outreach_jobs')->where('status', 'planned')->count());
        $this->assertSame(5, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('reserved_units'));

        $this->assertMonitorNeverReleases($monitor, 2, 2);
    }
}
