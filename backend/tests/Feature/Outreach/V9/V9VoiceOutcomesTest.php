<?php

namespace Tests\Feature\Outreach\V9;

use App\Services\Outreach\Run\VoiceCallOutcomes;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * V9 · EL NORMALIZADOR DE `voice_calls` (contrato H9, D9-10, D9-8 etapa 8,
 * D9-3 clase `integration`): lo que la telefonía del H8 ya registró de una
 * llamada que pidió una estrategia → resultados del conjunto cerrado por
 * `OutcomeRecorder`, fuente `voice_call`, una vez por llamada y clave. Sin
 * interpretar lo que se dijo (eso es AI9) y sin escribir nada de la voz.
 */
class V9VoiceOutcomesTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    /** Martes 17 de noviembre de 2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    private int $telefono = 3001239100;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->v9VoiceConfig();
        $this->threePeople();
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Un trabajo de voz que SALIÓ (`started`, con su referencia) y su llamada
     * en `$callStatus`.
     *
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $call
     * @return array{job: object, enrollment: object, call: int, member: array<string, mixed>}
     */
    private function salio(array $live, string $callStatus, array $call = []): array
    {
        $m = $this->v9Member(phone: (string) ++$this->telefono);
        $p = $this->v9VoiceJob($live, $m, 'dispatching');
        $id = $this->v9StrategyCall((string) $p['job']->uuid, $m['contact'], $m['e164'], $callStatus, $call);
        DB::table('outreach_jobs')->where('id', $p['job']->id)->update(['status' => 'started', 'voice_call_id' => $id, 'status_changed_at' => now()]);

        return ['job' => DB::table('outreach_jobs')->where('id', $p['job']->id)->first(), 'enrollment' => $p['enrollment'], 'call' => $id, 'member' => $m];
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> los resultados de un trabajo: [resultado, fuente, detalle] */
    private function resultadosDe(object $job): array
    {
        return DB::table('outreach_outcomes')->where('job_id', $job->id)->orderBy('id')->get(['outcome', 'source', 'detail_code', 'channel'])
            ->map(fn (object $o): array => [$o->outcome, $o->source, $o->detail_code])->all();
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private static function llamada(string $status, array $o = []): array
    {
        $t = new DateTimeImmutable('2026-11-17 15:10:00', new DateTimeZone('UTC'));

        return array_merge(['uuid' => '0b4f2c1e-0000-4000-8000-00000000c0de', 'status' => $status, 'failureCode' => null, 'policyReason' => null,
            'providerAccepted' => ! in_array($status, ['requested', 'canceled', 'failed_to_start'], true), 'guardVerdict' => null, 'spokenOptOut' => false,
            'humanRequested' => false, 'startedAt' => $t, 'endedAt' => $t->modify('+3 minutes'), 'statusChangedAt' => $t], $o);
    }

    public function test_el_mapa_de_d9_10_es_puro_determinista_y_no_interpreta_lo_que_se_dijo(): void
    {
        $de = static fn (array $c): array => array_map(static fn (array $m): array => [$m['outcome'], $m['detail'], substr($m['key'], 39)], VoiceCallOutcomes::map($c));

        $this->assertSame([], $de(self::llamada('requested')), 'pedida: nada todavía');
        $this->assertSame([['sent', null, ':sent']], $de(self::llamada('dialing')), 'el proveedor la aceptó: `sent` (no terminal)');
        $this->assertSame([['sent', null, ':sent']], $de(self::llamada('active')));
        $this->assertSame([], $de(self::llamada('ambiguous', ['providerAccepted' => false])), 'ambigua: se espera a la conciliación del H8');
        $this->assertSame([['sent', null, ':sent'], ['answered', 'guard_clean', ':end']], $de(self::llamada('completed', ['guardVerdict' => 'clean'])));
        $this->assertSame([['sent', null, ':sent'], ['answered', 'guard_violation', ':end']], $de(self::llamada('completed', ['guardVerdict' => 'violation'])));
        $this->assertSame([['sent', null, ':sent'], ['answered', null, ':end']], $de(self::llamada('completed')));
        $this->assertSame([['sent', null, ':sent'], ['no_answer', 'busy', ':end']], $de(self::llamada('busy')));
        $this->assertSame([['sent', null, ':sent'], ['no_answer', 'no_answer', ':end']], $de(self::llamada('no_answer')));
        $this->assertSame([['sent', null, ':sent'], ['failed', 'unknown', ':end']], $de(self::llamada('failed', ['failureCode' => 'unknown'])));
        $this->assertSame([['failed', 'client_error', ':end']], $de(self::llamada('failed_to_start', ['failureCode' => 'client_error'])), 'rechazada: nunca salió');
        $this->assertSame([['failed', 'publication_moved', ':end']], $de(self::llamada('canceled', ['policyReason' => 'publication_moved'])), 'el job del H8 la canceló antes de marcar');
        $this->assertSame([['sent', null, ':sent'], ['failed', 'hung_up', ':end']], $de(self::llamada('hung_up')));
        // Las dos herramientas que deciden solas: «no volver a llamar» EN EL ACTO (aunque siga viva) y gana a todo; «hablar con una persona», al cerrar.
        $this->assertSame([['sent', null, ':sent'], ['opted_out', 'no_volver_a_llamar', ':end']], $de(self::llamada('active', ['spokenOptOut' => true])));
        $this->assertSame([['sent', null, ':sent'], ['opted_out', 'no_volver_a_llamar', ':end']], $de(self::llamada('completed', ['spokenOptOut' => true, 'humanRequested' => true, 'guardVerdict' => 'clean'])));
        $this->assertSame([['sent', null, ':sent']], $de(self::llamada('active', ['humanRequested' => true])), 'pidió una persona, pero la llamada sigue');
        $this->assertSame([['sent', null, ':sent'], ['human_requested', 'solicitar_humano', ':end']], $de(self::llamada('completed', ['humanRequested' => true])));
        // Un código que no es un código no pasa como detalle.
        $this->assertSame([['sent', null, ':sent'], ['failed', 'voice_unknown', ':end']], $de(self::llamada('failed', ['failureCode' => 'Fallo Raro!'])));
    }

    public function test_cada_estado_final_queda_como_su_resultado_una_sola_vez_con_su_seguimiento(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $casos = [
            'completed' => [[], [['sent', 'voice_call', null], ['answered', 'voice_call', 'guard_clean']], 'completed'],
            'busy' => [[], [['sent', 'voice_call', null], ['no_answer', 'voice_call', 'busy']], 'active'],
            'no_answer' => [[], [['sent', 'voice_call', null], ['no_answer', 'voice_call', 'no_answer']], 'active'],
            'failed' => [['failure_code' => 'unknown'], [['sent', 'voice_call', null], ['failed', 'voice_call', 'unknown']], 'exited'],
            'failed_to_start' => [['failure_code' => 'client_error'], [['failed', 'voice_call', 'client_error']], 'exited'],
            'canceled' => [['policy_reason' => 'outside_hours'], [['failed', 'voice_call', 'outside_hours']], 'exited'],
            'hung_up' => [[], [['sent', 'voice_call', null], ['failed', 'voice_call', 'hung_up']], 'exited'],
        ];
        $hechos = [];
        foreach ($casos as $estado => [$extra, $esperados, $inscripcion]) {
            $hechos[$estado] = $this->salio($vivo, $estado, $extra);
            if ($estado === 'completed') {
                $this->v9CallEvent($hechos[$estado]['call'], 'guard_verdict', ['outcome' => 'clean']);
            }
        }

        $r = (new VoiceCallOutcomes)->run(now());
        $this->assertSame([false, 7, 12, 0, 0, 0], [$r['skipped'], $r['jobs'], $r['recorded'], $r['duplicates'], $r['ignored'], $r['pending']]);
        foreach ($casos as $estado => [, $esperados, $inscripcion]) {
            $this->assertSame($esperados, $this->resultadosDe($hechos[$estado]['job']), $estado);
            $this->assertSame(['voice_call'], DB::table('outreach_outcomes')->where('job_id', $hechos[$estado]['job']->id)->distinct()->pluck('channel')->all(), "{$estado}: el canal del trabajo");
            // La estrategia dice `answered → end_success` y `no_answer → retry_step`; `failed` sigue el de por defecto (`end_exit`).
            $this->assertSame($inscripcion, DB::table('outreach_enrollments')->where('id', $hechos[$estado]['enrollment']->id)->value('status'), $estado);
        }
        $this->assertNotNull(DB::table('outreach_enrollments')->where('id', $hechos['busy']['enrollment']->id)->value('next_due_at'), 'el reintento de negocio, programado (otra fila cuando toque)');
        $this->assertSame(7, DB::table('outreach_jobs')->count(), 'ningún trabajo nuevo ni reintento automático en la pasada');

        // Otra pasada: los que ya tienen su resultado terminal ni se miran; nada nuevo.
        $otra = (new VoiceCallOutcomes)->run(now());
        $this->assertSame([0, 0, 0], [$otra['jobs'], $otra['recorded'], $otra['duplicates']]);
        $this->assertSame(12, DB::table('outreach_outcomes')->count());

        // Una llamada todavía viva: su `sent`, una sola vez aunque la pasada la vuelva a mirar (la clave es la llamada).
        $viva = $this->salio($vivo, 'dialing');
        $this->assertSame([1, 1, 0, 1], array_values(array_intersect_key((new VoiceCallOutcomes)->run(now()), array_flip(['jobs', 'recorded', 'duplicates', 'pending']))));
        $this->assertSame([1, 0, 1, 1], array_values(array_intersect_key((new VoiceCallOutcomes)->run(now()), array_flip(['jobs', 'recorded', 'duplicates', 'pending']))));
        $this->assertSame([['sent', 'voice_call', null]], $this->resultadosDe($viva['job']));
    }

    public function test_no_volver_a_llamar_es_opted_out_en_el_acto_y_cancela_todo_lo_pendiente_de_la_persona(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $h = $this->salio($vivo, 'active');
        // «No me vuelvan a llamar» en ESA llamada (lo que deja la herramienta `no_volver_a_llamar`).
        DB::table('voice_exclusions')->insert(['uuid' => (string) Str::uuid(), 'phone_hash' => $h['member']['contactHash'], 'phone_last4' => substr($h['member']['e164'], -4),
            'source' => 'spoken_opt_out', 'call_id' => $h['call'], 'contact_id' => $h['member']['contact'], 'actor_type' => 'system',
            'actor_name' => 'Llamada de voz (no_volver_a_llamar)', 'created_at' => now()]);
        // La persona tiene otro trabajo pendiente en OTRA estrategia (asistida).
        $asistida = $this->liveStrategy($this->validContent($this->assistedTemplate()), 'PUBLISHED', ['key' => 'asistida-v9']);
        $otra = $this->insertEnrollment($asistida, ['mode' => 'live', 'part' => 'rest', 'publication_seq' => $asistida['seq'], 'subject_key' => $h['member']['key'],
            'subject_ref' => DB::table('outreach_enrollments')->where('id', $h['enrollment']->id)->value('subject_ref'), 'member_id' => $h['member']['member'],
            'enrolled_at' => '2026-11-17 14:00:00', 'window_ends_at' => '2026-11-27 14:00:00']);
        $pendiente = $this->insertAssistedJob($otra, ['budget_day' => '2026-11-17', 'not_before' => '2026-11-17 16:00:00', 'expires_at' => '2026-11-17 19:00:00']);

        $r = (new VoiceCallOutcomes)->run(now());
        $this->assertSame([['sent', 'voice_call', null], ['opted_out', 'voice_call', 'no_volver_a_llamar']], $this->resultadosDe($h['job']), 'aunque la llamada siga viva');
        $this->assertSame(0, $r['pending'], 'el opted_out ya decidió: no queda nada que esperar de esa llamada');
        $this->assertSame('opted_out', DB::table('outreach_enrollments')->where('id', $h['enrollment']->id)->value('status'));
        $this->assertSame(['canceled', 'opted_out'], [DB::table('outreach_jobs')->where('id', $pendiente)->value('status'), DB::table('outreach_jobs')->where('id', $pendiente)->value('cancel_reason')], 'nada pendiente sale, en ninguna estrategia');
        $this->assertSame('exited', DB::table('outreach_enrollments')->where('id', $otra)->value('status'));
    }

    public function test_solicitar_humano_deriva_a_una_persona(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $h = $this->salio($vivo, 'completed');
        $this->v9CallEvent($h['call'], 'tool_call', ['tool' => 'solicitar_humano', 'outcome' => 'ok']);
        $this->v9CallEvent($h['call'], 'guard_verdict', ['outcome' => 'clean']);

        (new VoiceCallOutcomes)->run(now());
        $this->assertSame([['sent', 'voice_call', null], ['human_requested', 'voice_call', 'solicitar_humano']], $this->resultadosDe($h['job']));
        $this->assertSame('handed_off', DB::table('outreach_enrollments')->where('id', $h['enrollment']->id)->value('status'));
    }

    public function test_un_ambiguo_se_enlaza_por_su_trabajo_y_sin_llamada_falla_pasado_el_plazo(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        // Ambiguo CON llamada (la puerta la confirmó y el proceso murió antes de asentar).
        $m = $this->v9Member(phone: '3001239201');
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');
        DB::table('outreach_jobs')->where('id', $p['job']->id)->update(['status' => 'ambiguous', 'status_changed_at' => now()]);
        $llamada = $this->v9StrategyCall((string) $p['job']->uuid, $m['contact'], $m['e164'], 'no_answer');
        // Ambiguo SIN llamada (nunca llegó a la puerta, o la puerta lo deshizo).
        $n = $this->v9Member(phone: '3001239202');
        $q = $this->v9VoiceJob($vivo, $n, 'dispatching', ['idempotency_key' => hash('sha256', 'v9-ambiguo-sin-llamada')]);
        DB::table('outreach_jobs')->where('id', $q['job']->id)->update(['status' => 'ambiguous', 'status_changed_at' => now()]);

        $r = (new VoiceCallOutcomes)->run(now());
        $this->assertSame([1, 1], [$r['linked'], $r['pending']]);
        $this->assertSame($llamada, (int) DB::table('outreach_jobs')->where('id', $p['job']->id)->value('voice_call_id'), 'la referencia, una vez');
        $this->assertSame([['sent', 'voice_call', null], ['no_answer', 'voice_call', 'no_answer']], $this->resultadosDe($p['job']));
        $this->assertSame([], $this->resultadosDe($q['job']), 'a los 0 min no se sabe todavía');

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(VoiceCallOutcomes::ABSENT_AFTER_MINUTES + 1));
        (new VoiceCallOutcomes)->run(now());
        $this->assertSame([['failed', 'voice_call', 'voice_call_absent']], $this->resultadosDe($q['job']), 'la puerta confirma o deshace: pasado el plazo, nunca la hubo');
        $this->assertNull(DB::table('outreach_jobs')->where('id', $q['job']->id)->value('voice_call_id'));
    }

    public function test_el_coste_real_de_la_llamada_se_lee_desde_el_trabajo(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $h = $this->salio($vivo, 'completed', ['duration_seconds' => 95, 'provider_cost_micros' => 120000, 'carrier_cost_micros' => 30500]);
        $this->v9CallEvent($h['call'], 'guard_verdict', ['outcome' => 'clean']);

        $r = (new VoiceCallOutcomes)->run(now());
        $this->assertSame(150500, $r['costMicros']);
        $coste = DB::table('outreach_jobs as j')->join('voice_calls as c', 'c.id', '=', 'j.voice_call_id')->where('j.id', $h['job']->id)
            ->selectRaw('c.provider_cost_micros + c.carrier_cost_micros AS coste, c.duration_seconds AS duracion')->first();
        $this->assertSame([150500, 95], [(int) $coste->coste, (int) $coste->duracion], 'el coste vive en la llamada y el trabajo lo alcanza por su referencia');
    }

    public function test_solo_lee_la_voz_y_solo_escribe_lo_suyo(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $h = $this->salio($vivo, 'completed');
        $this->v9CallEvent($h['call'], 'guard_verdict', ['outcome' => 'violation']);
        $antes = $this->v9VoiceRows();

        $sql = $this->capturar(fn () => (new VoiceCallOutcomes)->run(now()));
        foreach ($sql as $s) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1) {
                $this->assertMatchesRegularExpression('/^\s*(insert( or ignore)? into|update|delete from)\s+"?(outreach_|incidents|incident_events)/i', $s, "escribe fuera de lo suyo: {$s}");
            }
            $this->assertDoesNotMatchRegularExpression('/\b(insert(\s+or\s+ignore)?\s+into|update|delete\s+from)\s+"?voice_/i', $s);
        }
        $this->assertSame($antes, $this->v9VoiceRows());
        $this->assertSame([['sent', 'voice_call', null], ['answered', 'voice_call', 'guard_violation']], $this->resultadosDe($h['job']));
    }

    public function test_apagada_la_maestra_no_hace_nada_y_la_orden_lo_dice(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $this->salio($vivo, 'completed');
        config(['outreach.enabled' => false]);

        $r = (new VoiceCallOutcomes)->run(now());
        $this->assertSame([true, 'master_flag_off'], [$r['skipped'], $r['reason']]);
        $this->assertSame(0, DB::table('outreach_outcomes')->count());
        $this->assertSame(0, Artisan::call('outreach:voice-outcomes'));
        $this->assertStringContainsString('no se normalizó nada', Artisan::output());

        config(['outreach.enabled' => true]);
        $this->assertSame(0, Artisan::call('outreach:voice-outcomes', ['--json' => true]));
        $this->assertSame(2, json_decode(Artisan::output(), true)['recorded']);
    }

    /** @return list<string> */
    private function capturar(Closure $correr): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $correr();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $sql;
    }
}
