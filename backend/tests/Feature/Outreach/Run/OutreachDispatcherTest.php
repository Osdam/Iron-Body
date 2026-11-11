<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachReconciler;
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
 * El despachador (contrato H9, D9-8 etapas 6 y 7 y «Durabilidad»; §13.1 #4,
 * #15, #18–#20, #22; T15): la reevaluación justo antes de despachar, el canal
 * simulado en sombra, el asistido en vivo con su reserva, las puertas vacías
 * de WhatsApp y voz, los frenos que retienen y la conciliación.
 */
class OutreachDispatcherTest extends TestCase
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

    /** @return array{user: int, member: int, key: string, lead: int} */
    private function eligibleMember(int $days, string $phone): array
    {
        $r = $this->expiringMember(self::utcAt(self::NOW), $days);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $this->canary('+57'.$phone, 'whatsapp');

        return $r + ['lead' => $lead];
    }

    /** Una estrategia viva, un socio que califica y su trabajo planificado (en el modo de las banderas). @return array{live: array<string, mixed>, member: array<string, mixed>, job: object} */
    private function plannedJob(string $mode = 'shadow', array $flags = []): array
    {
        $this->runFlags($mode, $flags);
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->eligibleMember(5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['planned'], json_encode($r));
        // El trabajo nace con un desfase determinista de hasta 14 min dentro del hueco: el despacho llega después.
        Carbon::setTestNow(Carbon::parse(self::LATER, 'UTC'));

        return ['live' => $vivo, 'member' => $socio, 'job' => DB::table('outreach_jobs')->first()];
    }

    // ── Sombra ──────────────────────────────────────────────────────────────

    public function test_en_sombra_el_trabajo_se_simula_y_queda_su_resultado_not_attempted(): void
    {
        $p = $this->plannedJob();
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(1, $r['claimed']);
        $this->assertSame(['simulated' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('simulated', $trabajo->status);
        $this->assertNotNull($trabajo->claimed_at);
        $resultado = DB::table('outreach_outcomes')->first();
        $this->assertSame('not_attempted', $resultado->outcome);
        $this->assertSame('simulated', $resultado->source);
        $this->assertSame((int) $trabajo->id, (int) $resultado->job_id);
        $this->assertSame('simulated', $resultado->channel);
        $this->assertNull($resultado->next_action, 'not_attempted no abre ningún paso: la inscripción espera su ventana');
        $inscripcion = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $inscripcion->status);
        $this->assertSame(1, (int) $inscripcion->touches);
        $this->assertSame('not_attempted', $inscripcion->last_outcome);
        $this->assertSame('completed', DB::table('outreach_runs')->where('kind', 'dispatch')->value('status'));
        // Una segunda pasada no encuentra nada (el trabajo es final) y volver a procesarlo es inerte.
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->assertSame('not_claimed', (new OutreachDispatcher)->process((int) $trabajo->id, now()));
    }

    // ── #4 y #15 · reevaluación justo antes de despachar ───────────────────

    public function test_4_quien_renueva_entre_planificar_y_despachar_sale_converted_sin_llamar_al_canal(): void
    {
        $p = $this->plannedJob();
        // Renueva: la fecha de fin salta 60 días → `success` (days_to_expiry > 30) se cumple.
        DB::table('users')->where('id', $p['member']['user'])->update(['membership_end_date' => (new DateTimeImmutable(self::NOW))->modify('+60 days')->format('Y-m-d')]);

        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['converted' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $trabajo->status);
        $this->assertSame('success_reached', $trabajo->cancel_reason);
        $this->assertSame('completed', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame('converted', DB::table('outreach_outcomes')->where('source', 'system')->value('outcome'));
        $cancelacion = DB::table('outreach_decisions')->where('kind', 'cancel')->first();
        $this->assertSame('N6', $cancelacion->level);
        $this->assertSame(['success_reached'], json_decode((string) $cancelacion->reasons, true));
        $this->assertSame((int) $trabajo->id, (int) $cancelacion->job_id);
        $this->assertSame(0, DB::table('outreach_outcomes')->where('source', 'simulated')->count(), 'el canal no se llamó');
    }

    public function test_15_si_la_audiencia_deja_de_cumplirse_el_despacho_cancela_sin_contactar(): void
    {
        $p = $this->plannedJob();
        // Se congela la membresía: `membership.frozen = false` de la audiencia deja de cumplirse.
        DB::table('users')->where('id', $p['member']['user'])->update(['membership_frozen_at' => '2026-11-17']);

        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['canceled' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $trabajo->status);
        $this->assertSame('context_changed', $trabajo->cancel_reason);
        $inscripcion = DB::table('outreach_enrollments')->first();
        $this->assertSame('exited', $inscripcion->status);
        $this->assertSame('context_changed', $inscripcion->exit_reason);
        $bloqueado = DB::table('outreach_outcomes')->first();
        $this->assertSame('blocked', $bloqueado->outcome);
        $this->assertSame('context_changed', $bloqueado->reason_code);
        $this->assertSame('end_exit', $bloqueado->next_action);
    }

    // ── T15 · la PARADA GLOBAL retiene ──────────────────────────────────────

    public function test_15t_con_un_freno_echado_el_despachador_no_reclama_y_los_planificados_esperan(): void
    {
        $gente = $this->threePeople();
        $p = $this->plannedJob();
        $stop = new OutreachStop;
        $parada = $stop->stopAll('Parada de la prueba', $gente['ana']);
        $this->assertSame(1, $parada['retainedJobs']);

        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertTrue($r['skipped']);
        $this->assertSame('outreach_brake_global', $r['reason']);
        $this->assertSame('planned', DB::table('outreach_jobs')->value('status'), 'espera: no sale ni se cancela');
        $this->assertSame('aborted', DB::table('outreach_runs')->where('kind', 'dispatch')->value('status'));

        // Un freno de la estrategia: tampoco se reclama lo suyo; otra estrategia sí.
        $stop->release($gente['beto'], $parada['brake']['uuid'], 'Fin de la parada de la prueba', (string) Str::uuid());
        $stop->open('strategy', $p['live']['strategyUuid'], 'manual', 'Pausa de la estrategia', $gente['ana'], (string) Str::uuid());
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(0, $r['claimed']);
        $this->assertSame('planned', DB::table('outreach_jobs')->value('status'));

        // Soltado el freno, el trabajo pasa otra vez por la etapa 6 y sale (simulado).
        $freno = DB::table('outreach_brakes')->where('scope', 'strategy')->value('uuid');
        $stop->release($gente['caro'], (string) $freno, 'Se reanuda la estrategia', (string) Str::uuid());
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['simulated' => 1], $r['results']);
    }

    // ── #22 · el asistido en vivo ───────────────────────────────────────────

    public function test_22_en_vivo_el_trabajo_asistido_reserva_su_cupo_y_sale_a_la_bandeja(): void
    {
        $p = $this->plannedJob('live');
        $trabajo = $p['job'];
        $this->assertSame('assisted', $trabajo->channel);
        $this->assertSame('whatsapp', $trabajo->medium);
        $this->assertSame('live', $trabajo->mode);
        $this->assertSame(1, (int) $trabajo->reserved_units);
        $this->assertNotNull($trabajo->template_version_id);
        $contadores = DB::table('outreach_budget_counters')->where('budget_day', $trabajo->budget_day)->where('mode', 'live')->pluck('reserved_units', 'scope')->all();
        $this->assertSame(1, (int) $contadores['channel:assisted']);
        $this->assertSame(1, (int) $contadores['strategy:'.$p['live']['strategyUuid']]);
        $this->assertSame(1, (int) $contadores['phone:'.$trabajo->phone_ref], 'L9-30: un contacto al día por teléfono');
        $this->assertSame(20, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('cap_units'));

        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['started' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('started', $trabajo->status);
        $this->assertNull($trabajo->assisted_claimed_by_admin_id, 'la reclamación del personal llega por la bandeja, después');
        $this->assertSame(0, DB::table('outreach_outcomes')->count(), 'el resultado lo registra el personal');
        $this->assertSame(1, (int) DB::table('outreach_enrollments')->value('touches'));
        $this->assertSame(1, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('reserved_units'), 'lo que salió sigue reservado');
        // Otro trabajo de la misma persona hoy: el tope diario (1) lo niega y el teléfono también.
        $planner = new OutreachPlanner;
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['priority' => 90]), 'CANARY', ['key' => 'otra-renovacion']);
        $planner->sweep(now(), true);
        $plan = $planner->planDue(now());
        $this->assertSame(1, $plan['due']);
        $this->assertSame(0, $plan['planned']);
        $this->assertSame(1, DB::table('outreach_jobs')->count());
    }

    public function test_con_el_asistido_apagado_en_vivo_la_inscripcion_queda_bloqueada_y_no_hay_trabajo(): void
    {
        $this->runFlags('live', ['outreach.assisted.enabled' => false]);
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->eligibleMember(5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['denied']);
        $this->assertSame(0, DB::table('outreach_jobs')->count());
        $bloqueado = DB::table('outreach_outcomes')->first();
        $this->assertSame('blocked', $bloqueado->outcome);
        $this->assertSame('channel_disabled', $bloqueado->reason_code);
        $this->assertSame('wait', $bloqueado->next_action, 'por defecto espera y se vuelve a mirar');
        $this->assertSame('waiting', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame('N4', DB::table('outreach_decisions')->where('kind', 'deny')->value('level'));
    }

    // ── #18, #19, #20 · las puertas vacías ──────────────────────────────────

    public function test_18_19_un_error_del_proveedor_deja_failed_con_su_detalle_sin_reintento_y_libera_la_reserva(): void
    {
        $p = $this->plannedJob('live');
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
        $r = (new OutreachDispatcher(channels: [new SimulatedChannel, $roto, new WhatsappTemplateChannel, new VoiceStrategyChannel]))->dispatch(now(), null, true);
        $this->assertSame(['failed' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('failed_to_start', $trabajo->status);
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva se libera');
        $this->assertSame(0, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('reserved_units'));
        $fallo = DB::table('outreach_outcomes')->first();
        $this->assertSame('failed', $fallo->outcome);
        $this->assertSame('system', $fallo->source);
        $this->assertSame('provider_timeout', $fallo->detail_code, 'el detalle del proveedor, como código');
        $this->assertSame('end_exit', $fallo->next_action, 'la estrategia no dijo on.failed: rige la de por defecto (sin reintento fuera de la regla)');
        $this->assertSame('exited', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame(1, DB::table('outreach_jobs')->count(), 'ningún trabajo vivo ni reintento automático');
    }

    public function test_20_las_puertas_de_whatsapp_y_voz_existen_cerradas_y_no_tocan_la_red(): void
    {
        $p = $this->plannedJob('live');
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        // W9: la puerta de WhatsApp existe; con la constante cerrada no llama a nada (el mismo código que N4).
        $whatsapp = (new WhatsappTemplateChannel)->attempt($p['job'], $e, $live, self::utcAt(self::NOW));
        $this->assertSame('failed_to_start', $whatsapp->status);
        $this->assertSame('sends_disabled_in_code', $whatsapp->detailCode);
        // V9 (Enmienda 17, L9-81): la puerta de la voz también existe; con un trabajo que no es de voz se niega por
        // `channel_mismatch` antes que nada, y no pide nada.
        foreach ([new VoiceStrategyChannel] as $canal) {
            $intento = $canal->attempt($p['job'], $e, $live, self::utcAt(self::NOW));
            $this->assertSame('failed_to_start', $intento->status, $canal->name());
            $this->assertSame('channel_mismatch', $intento->detailCode);
        }
        $this->assertFalse((new AssistedChannel)->attempt($p['job'], $e, $live, self::utcAt(self::NOW))->wentOut() === false, 'el asistido en vivo sí sale');
        // Un trabajo de WhatsApp en vivo que llegara al canal: `failed` con `channel_not_live`, resultado y reserva liberada; nunca se duplica.
        $ambiguo = new class implements OutreachChannel
        {
            public function name(): string
            {
                return 'assisted';
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                return ChannelAttempt::ambiguous('provider_unknown');
            }
        };
        $r = (new OutreachDispatcher(channels: [new SimulatedChannel, $ambiguo, new WhatsappTemplateChannel, new VoiceStrategyChannel]))->dispatch(now(), null, true);
        $this->assertSame(['ambiguous' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('ambiguous', $trabajo->status);
        $this->assertNull($trabajo->budget_released_at, 'un ambiguo no libera: no se sabe si salió');
        $this->assertSame('ambiguous', DB::table('outreach_outcomes')->value('outcome'));
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'), 'se concilia; nada automático');
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed'], 'nunca se reintenta solo');
    }

    // ── Conciliación (#24 en SQLite; la carrera, en PG) ─────────────────────

    public function test_la_conciliacion_cierra_lo_que_quedo_a_medias_sin_reenviar_ni_perder_la_reserva(): void
    {
        $p = $this->plannedJob('live');
        $trabajo = $p['job'];
        // Un trabajador murió tras reclamar: `claimed` hace 20 min.
        DB::table('outreach_jobs')->where('id', $trabajo->id)->update(['status' => 'claimed', 'claimed_at' => '2026-11-17 14:35:00', 'status_changed_at' => '2026-11-17 14:35:00']);
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(1, $r['ambiguous']);
        $this->assertSame('ambiguous', DB::table('outreach_jobs')->value('status'));
        $this->assertSame(1, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:assisted')->value('reserved_units'), 'la reserva no se pierde: no se sabe si salió');
        $this->assertSame('ambiguous', DB::table('outreach_outcomes')->value('outcome'));
        $this->assertSame('stale', DB::table('outreach_outcomes')->value('detail_code'));
        $this->assertSame(1, DB::table('incidents')->where('kind', 'outreach_job_stale')->count());
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed'], 'nunca se reenvía solo');

        // Un planificado cuyo plazo venció: `expired`, reserva liberada y la inscripción vuelve a tocar.
        $this->runFlags('shadow');
        $otro = $this->liveStrategy($this->validContent($this->assistedTemplate()), 'CANARY', ['key' => 'vencidos']);
        $inscripcion = $this->insertEnrollment($otro, ['mode' => 'shadow', 'subject_key' => 'm:5001', 'member_id' => 5001, 'window_ends_at' => '2026-11-20 15:00:00']);
        $this->insertJob($inscripcion, ['mode' => 'shadow', 'not_before' => '2026-11-16 14:00:00', 'expires_at' => '2026-11-17 14:00:00', 'budget_day' => '2026-11-16']);
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(1, $r['expiredJobs']);
        $this->assertSame('expired', DB::table('outreach_jobs')->where('enrollment_id', $inscripcion)->value('status'));
        $this->assertSame(self::LATER, (string) DB::table('outreach_enrollments')->where('id', $inscripcion)->value('next_due_at'));

        // Una inscripción cuya ventana acabó: `expired` con `no_response` y su trabajo vivo vencido.
        $vencida = $this->insertEnrollment($otro, ['mode' => 'shadow', 'subject_key' => 'm:5002', 'member_id' => 5002, 'enrolled_at' => '2026-10-20 15:00:00', 'window_ends_at' => '2026-11-17 14:59:00']);
        $this->insertJob($vencida, ['mode' => 'shadow', 'not_before' => '2026-11-17 14:00:00', 'expires_at' => '2026-11-17 20:00:00', 'budget_day' => '2026-11-17']);
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(1, $r['expiredEnrollments']);
        $fila = DB::table('outreach_enrollments')->where('id', $vencida)->first();
        $this->assertSame('expired', $fila->status);
        $this->assertSame('no_response', $fila->last_outcome);
        $this->assertSame('expired', DB::table('outreach_jobs')->where('enrollment_id', $vencida)->value('status'));
        $this->assertSame('no_response', DB::table('outreach_outcomes')->where('enrollment_id', $vencida)->value('outcome'));
        // Idempotente.
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame([0, 0, 0], [$r['ambiguous'], $r['expiredJobs'], $r['expiredEnrollments']]);
    }
}
