<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * I-6 de la auditoría de cierre B (§2 y §4.2): los tres disparadores PERIÓDICOS
 * que solo tenían la prueba de su prefiltro (`MoneyAndAttendanceFactsTest`)
 * recorren aquí el camino ENTERO en sombra, sobre las clases reales
 * (planificador, prefiltros, puerta, escalera, despacho) y con la cadena
 * firmada de `RunWorld`: barrido → inscripción → decisión (`plan` con trabajo
 * `simulated`, o la negativa con su nivel y motivo exactos cuando el contrato
 * manda negar) → despacho simulado.
 *
 *  - `member.inactive` (contrato H9, D9-3; ciclo `inact:<semana ISO de Bogotá>`).
 *  - `payment_link.abandoned` (ciclo `tx:<id>`; el plazo de cortesía en horas).
 *  - `receivable.overdue` (finalidad `collections`; ciclo `rcv:<id>:<generación>`):
 *    D9-15, matriz de consentimiento, «cobranza: ninguna hoy» [P9-15] → toda
 *    persona inscrita se niega en N1 `no_consent_for_purpose_channel`, aunque
 *    tenga la autorización comercial y sea canaria (la comercial no vale para
 *    cobranza).
 *
 * Lo desplegado el 11-11 que estas pruebas respetan: en sombra «solo canario»
 * es AVISO de N3 (I-1, `H9ShadowCanaryOnlyTest`), así que quien no es canario
 * se simula con los avisos N3 y N5; y el barrido recorre la audiencia por
 * páginas sucesivas (I-4, `H9SweepCursorTest`): cada disparador tiene aquí su
 * caso de varias páginas (páginas de 2) con SU posición (ids de `users`,
 * `payment_transactions` o `receivables`, distintos a propósito de los de
 * `members`).
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), con la fuente de
 * eventos del motor encendida (L9-26), como el resto de B9-run.
 */
class H9SweepTriggersEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    /** La semana ISO del día de Bogotá de NOW (`ScanCommercialFacts.php:188`). */
    private const ISO_WEEK = '2026-W47';

    /** Los avisos de la sombra para quien NO es canario (I-1): N3 lo anota y N5 también (would_scope). */
    private const NON_CANARY_WARNINGS = [['level' => 'N3', 'reason' => 'canary_only_declared'], ['level' => 'N5', 'reason' => 'canary_scope_mismatch']];

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

    // ── El mundo de estas pruebas ───────────────────────────────────────────

    /** Un socio con plan vigente que vence en `$days` días de Bogotá. @return array{user: int, member: int, key: string} */
    private function member(int $days = 30, array $user = []): array
    {
        return $this->expiringMember(self::utcAt(self::NOW), $days, $user);
    }

    /** Su prospecto de WhatsApp autorizado (`granted`) con conversación; canario o no. @param  array{member: int}  $m */
    private function whatsapp(array $m, string $phone, bool $canary = false): void
    {
        $this->conversation($this->lead(['member_id' => $m['member'], 'phone' => $phone, 'consent_status' => 'granted']));
        if ($canary) {
            $this->canary('+57'.$phone, 'whatsapp');
        }
    }

    /** La salida y la entrada de la escalera del canal asistido de una decisión guardada. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function ladderOf(object $decision): array
    {
        $p = json_decode((string) $decision->trace_json, true)[0]['policy']['assisted:whatsapp'];

        return [$p['output'], $p['input']];
    }

    /**
     * Los recuentos de una pasada que importan aquí, EN ESTE ORDEN (o en el de `$keys`), para comparar con `assertSame`.
     *
     * @param  array<string, mixed>  $c
     * @param  list<string>|null  $keys
     * @return array<string, mixed>
     */
    private static function pick(array $c, ?array $keys = null): array
    {
        $r = [];
        foreach ($keys ?? ['candidates', 'evaluated', 'enrolled', 'not_in_audience', 'excluded', 'success_reached', 'insufficient_facts', 'already_enrolled', 'open_enrollment', 'unknown_subject', 'more'] as $k) {
            $r[$k] = $c[$k] ?? null;
        }

        return $r;
    }

    /** @return list<string> */
    private static function enrolledKeys(): array
    {
        return DB::table('outreach_enrollments')->orderBy('id')->pluck('subject_key')->all();
    }

    /** La posición que guardó la última pasada `sweep` completada de la estrategia. @return array{0: string, 1: string} */
    private function position(array $vivo): array
    {
        $c = json_decode((string) DB::table('outreach_runs')->where('kind', 'sweep')->where('strategy_id', $vivo['strategy'])->where('status', 'completed')->orderByDesc('id')->value('counts'), true);

        return [(string) $c[OutreachRuns::SWEEP_CURSOR_FROM], (string) $c[OutreachRuns::SWEEP_CURSOR_NEXT]];
    }

    /** El plan en sombra de una persona: el trabajo simulado, sin reserva, y la escalera en `allow` con los avisos dados. */
    private function assertSimulatedPlan(string $subjectKey, array $warnings): void
    {
        $trabajo = DB::table('outreach_jobs')->where('subject_key', $subjectKey)->first();
        $this->assertNotNull($trabajo, "{$subjectKey} tiene su trabajo");
        $this->assertSame(['planned', 'simulated', null, 'shadow', 0, 0], [$trabajo->status, $trabajo->channel, $trabajo->medium, $trabajo->mode, (int) $trabajo->reserved_units, (int) $trabajo->reserved_micros]);
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->where('subject_key', $subjectKey)->first();
        $this->assertNotNull($plan);
        $this->assertSame((int) $trabajo->id, (int) $plan->job_id);
        [$salida] = self::ladderOf($plan);
        $this->assertSame(['allow', null, [], $warnings], [$salida['verdict'], $salida['level'], $salida['reasons'], $salida['warnings']], $subjectKey);
        $this->assertSame(array_fill(0, 7, 'pass'), array_column($salida['levels'], 'status'), 'N1–N7 evaluados y en `pass`');
    }

    /** La negativa permanente de N1 por consentimiento: decisión `deny`, ningún trabajo, `blocked` con el motivo y la inscripción esperando. */
    private function assertDeniedNoConsent(string $subjectKey): void
    {
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->where('subject_key', $subjectKey)->first();
        $this->assertNotNull($negada, "{$subjectKey} tiene su `deny`");
        $this->assertSame(['N1', ['no_consent_for_purpose_channel']], [$negada->level, json_decode((string) $negada->reasons, true)], $subjectKey);
        [$salida, $entrada] = self::ladderOf($negada);
        $this->assertSame('missing', $entrada['consent']['whatsapp']);
        $this->assertSame(['deny', [], false], [$salida['verdict'], $salida['warnings'], $salida['waitsForRelease']], 'N1 decide antes que el aviso del canario (N3 ni se evalúa)');
        $this->assertSame(['deny', 'not_evaluated', 'not_evaluated', 'not_evaluated', 'not_evaluated', 'not_evaluated', 'not_evaluated'], array_column($salida['levels'], 'status'));
        $this->assertSame(0, DB::table('outreach_jobs')->where('subject_key', $subjectKey)->count(), 'ningún contacto, ni simulado');
        $bloqueo = DB::table('outreach_outcomes')->where('subject_key', $subjectKey)->first();
        $this->assertSame(['blocked', 'system', 'no_consent_for_purpose_channel', 'wait'], [$bloqueo->outcome, $bloqueo->source, $bloqueo->reason_code, $bloqueo->next_action]);
        $inscripcion = DB::table('outreach_enrollments')->where('subject_key', $subjectKey)->first();
        $this->assertSame(['waiting', '2026-11-18 15:00:00'], [$inscripcion->status, (string) $inscripcion->next_due_at], 'se vuelve a mirar a las 24 h');
    }

    // ── member.inactive ─────────────────────────────────────────────────────

    /** Reactivación: activos sin congelar, 14 días o más sin venir, y que no vencen en menos de 7 (eso es la renovación). @return array<string, mixed> */
    private function inactiveStrategy(): array
    {
        return $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'reactivate',
            'trigger' => ['event' => 'member.inactive', 'params' => ['days' => 14]],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
                ['fact' => 'attendance.days_since_last', 'op' => 'gte', 'value' => 14],
                ['fact' => 'membership.days_to_expiry', 'op' => 'gte', 'value' => 7],
            ]],
            'success' => ['fact' => 'attendance.days_since_last', 'op' => 'lt', 'value' => 7],
        ]));
    }

    public function test_member_inactive_quien_dejo_de_venir_se_inscribe_por_semana_y_se_simula_y_el_que_viene_o_nunca_vino_no_entra(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->inactiveStrategy();
        // Califica: vino por última vez hace 20 días; prospecto de WhatsApp autorizado, NO canario.
        $inactivo = $this->member();
        $this->entry($inactivo['user'], '2026-10-28 15:00:00', $inactivo['member']);
        $this->whatsapp($inactivo, '3001234561');
        // Califica en el borde: 23:59:59 de Bogotá del 03-11 son 14 días justos. Socio SIN prospecto (I-2): N1 lo niega.
        $borde = $this->member();
        $this->entry($borde['user'], '2026-11-04 04:59:59', $borde['member']);
        // No son candidatos: a las 00:00 de Bogotá del 04-11 son 13 días; vino hace 3; nunca vino (desconocido, no inactivo); congelado.
        $trece = $this->member();
        $this->entry($trece['user'], '2026-11-04 05:00:00', $trece['member']);
        $this->whatsapp($trece, '3001234563');
        $reciente = $this->member();
        $this->entry($reciente['user'], '2026-11-14 15:00:00', $reciente['member']);
        $this->whatsapp($reciente, '3001234564');
        $nunca = $this->member();
        $this->whatsapp($nunca, '3001234565');
        $congelado = $this->member(30, ['membership_frozen_at' => '2026-11-01']);
        $this->entry($congelado['user'], '2026-10-01 15:00:00', $congelado['member']);
        $this->whatsapp($congelado, '3001234566');
        // Candidato del prefiltro pero fuera de la audiencia: inactivo, y vence en 3 días (el prefiltro no decide, D9-5).
        $vencePronto = $this->member(3);
        $this->entry($vencePronto['user'], '2026-10-28 15:00:00', $vencePronto['member']);
        $this->whatsapp($vencePronto, '3001234567');

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 2, 'not_in_audience' => 1, 'excluded' => 0, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0, 'more' => false], self::pick($r));
        $this->assertSame([$inactivo['key'], $borde['key']], self::enrolledKeys());
        foreach (DB::table('outreach_enrollments')->get() as $e) {
            $this->assertSame(['inact:'.self::ISO_WEEK, 'shadow', 'canary', 'commercial'], [$e->cycle_key, $e->mode, $e->part, $e->purpose], 'la ocurrencia es la semana ISO de Bogotá');
            $this->assertSame(1, DB::table('outreach_decisions')->where('kind', 'enroll')->where('enrollment_id', $e->id)->count(), 'cada inscripción con su decisión `enroll`');
        }

        $r = $planner->planDue(now());
        $this->assertSame([2, 1, 1], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSimulatedPlan($inactivo['key'], self::NON_CANARY_WARNINGS);
        $this->assertDeniedNoConsent($borde['key']);
        $this->assertSame(0, DB::table('outreach_budget_counters')->count(), 'en sombra no se reserva nada');

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame([1, ['simulated' => 1]], [$d['claimed'], $d['results']], json_encode($d));
        $resultado = DB::table('outreach_outcomes')->where('subject_key', $inactivo['key'])->first();
        $this->assertSame(['not_attempted', 'simulated'], [$resultado->outcome, $resultado->source]);

        // La misma semana otra vez (forzada): la ocurrencia ya está; nadie nuevo entra.
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(['candidates' => 3, 'evaluated' => 1, 'enrolled' => 0, 'not_in_audience' => 1, 'already_enrolled' => 2], self::pick($r, ['candidates', 'evaluated', 'enrolled', 'not_in_audience', 'already_enrolled']));
        $this->assertSame(2, DB::table('outreach_enrollments')->count());
        $this->assertStringNotContainsString('3001234561', json_encode(DB::table('outreach_decisions')->get()), 'ni teléfonos en las decisiones');
    }

    public function test_member_inactive_pagina_por_users_id_sin_repetir_ni_saltar_y_vuelve_a_empezar(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->inactiveStrategy();
        // Tres fichas de socio sin usuario delante: los ids de `members` y de `users` dejan de coincidir (la posición es de `users`).
        foreach (range(1, 3) as $_) {
            $this->socio(['__none__' => true]);
        }
        $inactivo = function (int $days = 30): array {
            $m = $this->member($days);
            $this->entry($m['user'], '2026-10-28 15:00:00', $m['member']);

            return $m;
        };
        $a = $inactivo();
        $b = $this->member();                                   // vino hace 2 días: no es candidato
        $this->entry($b['user'], '2026-11-15 15:00:00', $b['member']);
        $c = $inactivo(3);                                      // candidato fuera de la audiencia
        $d = $inactivo();
        $this->member();                                        // nunca vino: no es candidato
        $f = $inactivo();
        $g = $inactivo();
        $this->assertNotSame($a['user'], $a['member'], 'los ids de usuario y de socio no coinciden');
        $u = static fn (array $m): string => (string) $m['user'];
        $planner = new OutreachPlanner;

        $esperado = [
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 1, 'not_in_audience' => 1, 'already_enrolled' => 0, 'more' => true], ['', $u($c)]],
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 2, 'not_in_audience' => 0, 'already_enrolled' => 0, 'more' => true], [$u($c), $u($f)]],
            [['candidates' => 1, 'evaluated' => 1, 'enrolled' => 1, 'not_in_audience' => 0, 'already_enrolled' => 0, 'more' => false], [$u($f), '']],
            // Acabada la vuelta, la siguiente empieza por el principio: el primero ya está (la misma semana), el segundo sigue fuera.
            [['candidates' => 2, 'evaluated' => 1, 'enrolled' => 0, 'not_in_audience' => 1, 'already_enrolled' => 1, 'more' => true], ['', $u($c)]],
        ];
        foreach ($esperado as $i => [$recuentos, $posicion]) {
            $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
            $this->assertSame($recuentos, self::pick($r, array_keys($recuentos)), "pasada {$i}");
            $this->assertSame($posicion, $this->position($vivo), "pasada {$i}: la posición es el id de `users`");
        }
        $this->assertSame([$a['key'], $d['key'], $f['key'], $g['key']], self::enrolledKeys(), 'todos los que califican, una vez cada uno');
        $this->assertSame(['inact:'.self::ISO_WEEK], DB::table('outreach_enrollments')->distinct()->pluck('cycle_key')->all());
    }

    // ── payment_link.abandoned ──────────────────────────────────────────────

    /** Recuperar el enlace: quien tiene un enlace abierto, salvo quien tuvo un rechazo esta semana; éxito, ya no hay enlace abierto. @return array<string, mixed> */
    private function abandonedLinkStrategy(): array
    {
        return $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'recover_payment_link',
            'trigger' => ['event' => 'payment_link.abandoned', 'params' => ['graceHours' => 6]],
            'audience' => ['fact' => 'payment.has_pending_link', 'op' => 'eq', 'value' => true],
            'exclusions' => ['fact' => 'payment.declined_last_7d', 'op' => 'gt', 'value' => 0],
            'success' => ['fact' => 'payment.has_pending_link', 'op' => 'eq', 'value' => false],
        ]));
    }

    /** Un enlace de pago en vuelo de un socio (por `member_id`), creado a esa hora UTC. */
    private function link(array $m, string $createdUtc, array $o = []): int
    {
        return $this->tx($o + ['member_id' => $m['member'], 'created_at' => self::utc($createdUtc), 'updated_at' => self::utc($createdUtc)]);
    }

    public function test_payment_link_abandoned_el_enlace_sin_usar_pasado_el_plazo_se_inscribe_por_transaccion_y_quien_paga_antes_del_despacho_sale_converted(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->abandonedLinkStrategy();
        // Califican: un enlace de hace 7 h (NO canario) y otro, solo con `user_id`, de hace 6 h justas (canario).
        $abandonado = $this->member();
        $this->whatsapp($abandonado, '3001234561');
        $txAbandonado = $this->link($abandonado, '2026-11-17 08:00:00');
        $soloUsuario = $this->member();
        $this->whatsapp($soloUsuario, '3001234562', true);
        $txSoloUsuario = $this->tx(['user_id' => $soloUsuario['user'], 'member_id' => null, 'created_at' => self::utc('2026-11-17 09:00:00')]);
        // Candidato excluido: el enlace abierto y un rechazo ayer.
        $rechazado = $this->member();
        $this->whatsapp($rechazado, '3001234563');
        $this->link($rechazado, '2026-11-16 15:00:00', ['status' => 'declined']);
        $txRechazado = $this->link($rechazado, '2026-11-17 07:00:00');
        // No son candidatos: dentro del plazo (5 h 59 min 59 s), caducado, aprobado, sin URL, de hace más de 174 h, sin persona.
        foreach ([
            ['2026-11-17 09:00:01', []],
            ['2026-11-17 08:00:00', ['expires_at' => self::utc('2026-11-17 14:00:00')]],
            ['2026-11-17 08:00:00', ['status' => 'approved']],
            ['2026-11-17 08:00:00', ['checkout_url' => null]],
            ['2026-11-10 08:59:59', []],
        ] as $i => [$creado, $o]) {
            $m = $this->member();
            $this->whatsapp($m, '30012346'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->link($m, $creado, $o);
        }
        $this->tx(['created_at' => self::utc('2026-11-17 08:00:00')]);

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 2, 'not_in_audience' => 0, 'excluded' => 1, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0, 'more' => false], self::pick($r));
        $this->assertSame([$abandonado['key'], $soloUsuario['key']], self::enrolledKeys());
        $this->assertSame(['tx:'.$txAbandonado, 'tx:'.$txSoloUsuario], DB::table('outreach_enrollments')->orderBy('id')->pluck('cycle_key')->all(), 'la ocurrencia es la transacción');
        $this->assertSame(0, DB::table('outreach_enrollments')->where('cycle_key', 'tx:'.$txRechazado)->count());

        $r = $planner->planDue(now());
        $this->assertSame([2, 2, 0], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSimulatedPlan($abandonado['key'], self::NON_CANARY_WARNINGS);
        $this->assertSimulatedPlan($soloUsuario['key'], []);

        // Quien paga entre planificar y despachar sale `converted` sin contacto; el otro se simula.
        DB::table('payment_transactions')->where('id', $txAbandonado)->update(['status' => 'approved']);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        ksort($d['results']);
        $this->assertSame([2, ['converted' => 1, 'simulated' => 1]], [$d['claimed'], $d['results']], json_encode($d));
        $pagado = DB::table('outreach_jobs')->where('subject_key', $abandonado['key'])->first();
        $this->assertSame(['canceled', 'success_reached'], [$pagado->status, $pagado->cancel_reason]);
        $this->assertSame('converted', DB::table('outreach_outcomes')->where('subject_key', $abandonado['key'])->value('outcome'));
        $this->assertSame(0, DB::table('outreach_outcomes')->where('subject_key', $abandonado['key'])->where('source', 'simulated')->count(), 'el canal no se llamó');
        $this->assertSame('completed', DB::table('outreach_enrollments')->where('subject_key', $abandonado['key'])->value('status'));
        $this->assertSame(['not_attempted', 'simulated'], [DB::table('outreach_outcomes')->where('subject_key', $soloUsuario['key'])->value('outcome'), DB::table('outreach_outcomes')->where('subject_key', $soloUsuario['key'])->value('source')]);
    }

    public function test_payment_link_abandoned_pagina_por_id_de_transaccion_y_el_segundo_enlace_de_la_misma_persona_no_abre_otra_inscripcion(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->abandonedLinkStrategy();
        $p = [];
        foreach (range(1, 5) as $i) {
            $p[$i] = $this->member();
        }
        // Tres transacciones aprobadas delante: los ids de las transacciones dejan de coincidir con los de los socios.
        foreach (range(1, 3) as $i) {
            $this->link($p[$i], '2026-11-01 12:00:00', ['status' => 'approved']);
        }
        $l1 = $this->link($p[1], '2026-11-16 12:00:00');
        $this->link($p[2], '2026-11-17 12:00:00');              // dentro del plazo: no es candidato
        $l3 = $this->link($p[3], '2026-11-16 12:00:00');
        $l4 = $this->link($p[1], '2026-11-16 13:00:00');        // el segundo enlace de la persona 1
        $l5 = $this->link($p[4], '2026-11-16 12:00:00');
        $l6 = $this->link($p[5], '2026-11-16 12:00:00');
        $planner = new OutreachPlanner;

        $esperado = [
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 2, 'open_enrollment' => 0, 'already_enrolled' => 0, 'more' => true], ['', (string) $l3]],
            [['candidates' => 2, 'evaluated' => 1, 'enrolled' => 1, 'open_enrollment' => 1, 'already_enrolled' => 0, 'more' => true], [(string) $l3, (string) $l5]],
            [['candidates' => 1, 'evaluated' => 1, 'enrolled' => 1, 'open_enrollment' => 0, 'already_enrolled' => 0, 'more' => false], [(string) $l5, '']],
            [['candidates' => 2, 'evaluated' => 0, 'enrolled' => 0, 'open_enrollment' => 0, 'already_enrolled' => 2, 'more' => true], ['', (string) $l3]],
        ];
        foreach ($esperado as $i => [$recuentos, $posicion]) {
            $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
            $this->assertSame($recuentos, self::pick($r, array_keys($recuentos)), "pasada {$i}");
            $this->assertSame($posicion, $this->position($vivo), "pasada {$i}: la posición es el id de la transacción");
        }
        $this->assertSame([$p[1]['key'], $p[3]['key'], $p[4]['key'], $p[5]['key']], self::enrolledKeys());
        $this->assertSame(['tx:'.$l1, 'tx:'.$l3, 'tx:'.$l5, 'tx:'.$l6], DB::table('outreach_enrollments')->orderBy('id')->pluck('cycle_key')->all(), 'la persona 1 sigue con su primer enlace');
        $this->assertSame(0, DB::table('outreach_enrollments')->where('cycle_key', 'tx:'.$l4)->count());
    }

    // ── receivable.overdue (cobranza) ───────────────────────────────────────

    /** Cobranza: cartera vencida hace entre 3 y 30 días; éxito, ya no hay cartera vencida. @return array<string, mixed> */
    private function overdueStrategy(): array
    {
        $plantilla = $this->assistedTemplate(['purpose' => 'collections', 'content' => ['medium' => 'whatsapp', 'script' => 'Saluda y recuerda con respeto que hay una cuota pendiente; ofrece ayuda para ponerse al día.']]);

        return $this->liveStrategy($this->validContent($plantilla, [
            'goal' => 'collect_payment',
            'purpose' => 'collections',
            'trigger' => ['event' => 'receivable.overdue', 'params' => ['daysMin' => 3, 'daysMax' => 30]],
            'audience' => ['fact' => 'receivable.has_overdue', 'op' => 'eq', 'value' => true],
            'success' => ['fact' => 'receivable.has_overdue', 'op' => 'eq', 'value' => false],
        ]));
    }

    public function test_receivable_overdue_inscribe_la_cartera_vencida_en_el_intervalo_cerrado_y_n1_la_niega_porque_la_cobranza_no_tiene_autorizacion(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->overdueStrategy();
        // Califica, con la autorización COMERCIAL de WhatsApp y canario: no vale para cobranza (D9-15, P9-15).
        $moroso = $this->member();
        $this->whatsapp($moroso, '3001234561', true);
        $rMoroso = $this->receivable($moroso['member'], '2026-11-07');
        // Los dos extremos del intervalo cerrado [3, 30]; la de 3 días con un abono (generación 1).
        $minimo = $this->member();
        $rMinimo = $this->receivable($minimo['member'], '2026-11-14', 'partially_paid');
        $this->receivablePayment($rMinimo);
        $maximo = $this->member();
        $rMaximo = $this->receivable($maximo['member'], '2026-10-18');
        // No son candidatos: hace 2 días, hace 31, vence hoy (todavía no ha vencido) y una pagada.
        foreach ([['2026-11-15', 'pending'], ['2026-10-17', 'pending'], ['2026-11-17', 'pending'], ['2026-11-07', 'paid']] as [$vence, $estado]) {
            $this->receivable($this->member()['member'], $vence, $estado);
        }

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 3, 'not_in_audience' => 0, 'excluded' => 0, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0, 'more' => false], self::pick($r));
        $this->assertSame([$moroso['key'], $minimo['key'], $maximo['key']], self::enrolledKeys());
        $this->assertSame(["rcv:{$rMoroso}:0", "rcv:{$rMinimo}:1", "rcv:{$rMaximo}:0"], DB::table('outreach_enrollments')->orderBy('id')->pluck('cycle_key')->all(), 'la cuenta y su generación');
        $this->assertSame(['collections'], DB::table('outreach_enrollments')->distinct()->pluck('purpose')->all());

        $r = $planner->planDue(now());
        $this->assertSame([3, 0, 3], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        foreach ([$moroso, $minimo, $maximo] as $m) {
            $this->assertDeniedNoConsent($m['key']);
        }
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'ningún trabajo de cobranza, ni simulado');
        $this->assertStringNotContainsString('80000', json_encode(DB::table('outreach_decisions')->get()), 'sin importes en las decisiones');

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
    }

    public function test_receivable_overdue_pagina_por_id_de_la_cuenta_y_la_segunda_cuenta_del_mismo_socio_no_abre_otra_inscripcion(): void
    {
        $this->runFlags('shadow', ['outreach.sweep.batch' => 2]);
        $vivo = $this->overdueStrategy();
        $m = [];
        foreach (range(1, 5) as $i) {
            $m[$i] = $this->member();
        }
        // Dos cuentas que vencen hoy delante (no son candidatas): los ids de `receivables` dejan de coincidir con los de `members`.
        $this->receivable($m[1]['member'], '2026-11-17');
        $this->receivable($m[2]['member'], '2026-11-17');
        $r1 = $this->receivable($m[1]['member'], '2026-11-07');
        $this->receivable($m[2]['member'], '2026-11-16');        // hace 1 día: no es candidata
        $r3 = $this->receivable($m[3]['member'], '2026-11-07');
        $r4 = $this->receivable($m[1]['member'], '2026-10-28');  // la segunda cuenta vencida del socio 1
        $r5 = $this->receivable($m[4]['member'], '2026-11-12');
        $r6 = $this->receivable($m[5]['member'], '2026-10-18');
        $planner = new OutreachPlanner;

        $esperado = [
            [['candidates' => 2, 'evaluated' => 2, 'enrolled' => 2, 'open_enrollment' => 0, 'already_enrolled' => 0, 'more' => true], ['', (string) $r3]],
            [['candidates' => 2, 'evaluated' => 1, 'enrolled' => 1, 'open_enrollment' => 1, 'already_enrolled' => 0, 'more' => true], [(string) $r3, (string) $r5]],
            [['candidates' => 1, 'evaluated' => 1, 'enrolled' => 1, 'open_enrollment' => 0, 'already_enrolled' => 0, 'more' => false], [(string) $r5, '']],
            [['candidates' => 2, 'evaluated' => 0, 'enrolled' => 0, 'open_enrollment' => 0, 'already_enrolled' => 2, 'more' => true], ['', (string) $r3]],
        ];
        foreach ($esperado as $i => [$recuentos, $posicion]) {
            $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
            $this->assertSame($recuentos, self::pick($r, array_keys($recuentos)), "pasada {$i}");
            $this->assertSame($posicion, $this->position($vivo), "pasada {$i}: la posición es el id de la cuenta");
        }
        $this->assertSame([$m[1]['key'], $m[3]['key'], $m[4]['key'], $m[5]['key']], self::enrolledKeys());
        $this->assertSame(["rcv:{$r1}:0", "rcv:{$r3}:0", "rcv:{$r5}:0", "rcv:{$r6}:0"], DB::table('outreach_enrollments')->orderBy('id')->pluck('cycle_key')->all());
        $this->assertSame(0, DB::table('outreach_enrollments')->where('cycle_key', "rcv:{$r4}:0")->count());
    }
}
