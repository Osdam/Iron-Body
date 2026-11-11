<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachMonitor;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\OutreachStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * Cierre de B9 · el PRESUPUESTO Y EL CUPO DE LA VOZ en el H9 (G-3…G-6 de V9):
 * `config/outreach.php` (`voice.daily_cap`, `voice.daily_budget_usd`,
 * `voice.reserve_usd_per_call`), sus lectores, la entrada de N4, la parcela
 * del planificador y el techo del monitor. Todo nace en 0: con los defectos la
 * escalera niega `budget_zero`; con valores > 0 deja de negar por presupuesto
 * (las demás cerraduras siguen: la constante de código del H9 y la del H8
 * están cerradas y NADA llega a la puerta de la telefonía); sin reserva por
 * llamada, falla cerrado.
 *
 * La «puerta abierta» de la escalera es el doble de W9 (`LadderInputBuilder`
 * con `sendsEnabledInCode`): la constante NO se gira. El canal de voz real
 * sigue preguntando a la constante y se niega (`sends_disabled_in_code`).
 */
class VoiceBudgetTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    /** Martes 17 de noviembre de 2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    private const PREFIXES = ['COMMERCIAL_AUTOMATION_', 'OUTREACH_'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->v9VoiceConfig();
        $this->threePeople();
        $this->v9GreenVoice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** La escalera con la constante «abierta» SOLO en esta prueba (el doble de W9). */
    private static function openInputs(): LadderInputBuilder
    {
        return new LadderInputBuilder(sendsEnabledInCode: static fn (): bool => true);
    }

    /** @param  array<string, mixed>  $voz */
    private function voiceLive(array $voz = []): void
    {
        $this->runFlags('live', ['outreach.voice.enabled' => true] + $voz);
    }

    private const BUDGET = ['outreach.voice.daily_cap' => 2, 'outreach.voice.daily_budget_usd' => '3', 'outreach.voice.reserve_usd_per_call' => '1.5'];

    private function assertNothingAsked(): void
    {
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows(), 'la puerta de la telefonía no recibió nada');
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
        Http::assertNothingSent();
    }

    public function test_las_tres_variables_nacen_en_cero_se_recortan_y_sus_lectores_las_leen(): void
    {
        $sin = $this->configWith([]);
        $this->assertSame([0, '0', '0'], [$sin['voice']['daily_cap'], $sin['voice']['daily_budget_usd'], $sin['voice']['reserve_usd_per_call']]);
        $con = $this->configWith(['COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP' => '50', 'COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD' => '7.5', 'COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD' => '0.97']);
        $this->assertSame([20, '7.5', '0.97'], [$con['voice']['daily_cap'], $con['voice']['daily_budget_usd'], $con['voice']['reserve_usd_per_call']], 'el cupo se recorta a 20, como el del H8');
        $mal = $this->configWith(['COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP' => 'mucho', 'COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD' => '1e9', 'COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD' => '-1']);
        $this->assertSame([0, '0', '0'], [$mal['voice']['daily_cap'], $mal['voice']['daily_budget_usd'], $mal['voice']['reserve_usd_per_call']], 'lo ilegible no deja un techo cualquiera');

        $this->assertSame([0, 0, 0], [OutreachConfig::voiceDailyCap(), OutreachConfig::voiceDailyBudgetMicros(), OutreachConfig::voiceReserveMicros()]);
        $this->assertSame(['dailyCap' => 0, 'dailyBudgetMicros' => 0, 'reserveMicros' => 0], OutreachConfig::voiceBudget());
        config(self::BUDGET);
        $this->assertSame([2, 3_000_000, 1_500_000], [OutreachConfig::voiceDailyCap(), OutreachConfig::voiceDailyBudgetMicros(), OutreachConfig::voiceReserveMicros()]);
        $this->assertSame(['dailyCap' => 2, 'dailyBudgetMicros' => 3_000_000, 'reserveMicros' => 1_500_000], OutreachConfig::voiceBudget());
        config(['outreach.voice.reserve_usd_per_call' => '0']);
        $this->assertSame(['dailyCap' => 0, 'dailyBudgetMicros' => 0, 'reserveMicros' => 0], OutreachConfig::voiceBudget(), 'sin reserva por llamada, nada efectivo (falla cerrado)');
        $this->assertSame([2, 3_000_000], [OutreachConfig::voiceDailyCap(), OutreachConfig::voiceDailyBudgetMicros()], 'los techos CONFIGURADOS siguen ahí para el monitor');
    }

    public function test_con_los_defectos_la_escalera_niega_budget_zero_a_la_voz_aun_con_la_puerta_abierta(): void
    {
        $this->voiceLive();
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $t = $this->v9VoiceJob($vivo, $this->v9Member(), 'claimed')['job'];

        $r = (new OutreachDispatcher(inputs: self::openInputs()))->process((int) $t->id, now());

        $this->assertSame('denied', $r);
        $trabajo = DB::table('outreach_jobs')->where('id', $t->id)->first();
        $this->assertSame(['canceled', 'budget_zero'], [$trabajo->status, $trabajo->cancel_reason], 'N4: la voz sin cupo, techo ni reserva');
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame(['N4', ['budget_zero']], [$decision->level, json_decode((string) $decision->reasons, true)]);
        $this->assertNothingAsked();
    }

    public function test_con_cupo_techo_y_reserva_la_escalera_ya_no_niega_por_presupuesto_y_las_demas_cerraduras_siguen(): void
    {
        $this->voiceLive(self::BUDGET);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');

        // Con la constante REAL (cerrada): la escalera niega por ella y NO por el presupuesto.
        $t1 = $this->v9VoiceJob($vivo, $this->v9Member(phone: '3001239001'), 'claimed')['job'];
        $this->assertSame('denied', (new OutreachDispatcher)->process((int) $t1->id, now()));
        $motivos = json_decode((string) DB::table('outreach_decisions')->where('job_id', $t1->id)->value('reasons'), true);
        $this->assertSame(['sends_disabled_in_code'], $motivos, 'N4 solo por la constante: ni budget_zero, ni budget_exhausted, ni channel_daily_cap');

        // Con la puerta de la escalera abierta (el doble): la escalera DEJA SALIR; el canal real se niega por su cuenta (la constante).
        $t2 = $this->v9VoiceJob($vivo, $this->v9Member(phone: '3001239002'), 'claimed')['job'];
        $this->assertSame('failed', (new OutreachDispatcher(inputs: self::openInputs()))->process((int) $t2->id, now()));
        $this->assertSame(0, DB::table('outreach_decisions')->where('job_id', $t2->id)->count(), 'la escalera no canceló: no hay decisión');
        $trabajo = DB::table('outreach_jobs')->where('id', $t2->id)->first();
        $this->assertSame('failed_to_start', $trabajo->status);
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva vuelve');
        $this->assertSame('sends_disabled_in_code', DB::table('outreach_outcomes')->where('job_id', $t2->id)->value('detail_code'), 'el canal de voz pregunta a la constante: cerrada');
        $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
        $this->assertNothingAsked();
    }

    public function test_sin_reserva_por_llamada_la_voz_falla_cerrado_con_budget_zero(): void
    {
        $this->voiceLive(['outreach.voice.reserve_usd_per_call' => '0'] + self::BUDGET);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $t = $this->v9VoiceJob($vivo, $this->v9Member(), 'claimed')['job'];

        $this->assertSame('denied', (new OutreachDispatcher(inputs: self::openInputs()))->process((int) $t->id, now()));
        $this->assertSame('budget_zero', DB::table('outreach_jobs')->where('id', $t->id)->value('cancel_reason'), 'cupo y techo puestos, reserva 0: el H9 no puede contar lo que gasta');
        $this->assertNothingAsked();
    }

    public function test_el_planificador_reserva_la_parcela_de_voz_con_la_reserva_contra_el_cupo_y_el_techo(): void
    {
        $this->voiceLive(self::BUDGET);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $this->v9Member(phone: '3001239001');
        $this->v9Member(phone: '3001239002');
        $this->v9Member(phone: '3001239003');
        $planner = new OutreachPlanner(inputs: self::openInputs());
        $this->assertSame(3, $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']]['enrolled']);

        $r = $planner->planDue(now());

        // Dos caben (2 × 1,5 US$ = 3 US$, cupo 2); la tercera se aplaza a mañana por N4 (techo y cupo).
        $this->assertSame(2, $r['planned'], json_encode($r));
        $this->assertSame(1, $r['deferred'], json_encode($r));
        $trabajos = DB::table('outreach_jobs')->orderBy('id')->get();
        $this->assertSame([['voice_call', 1, 1_500_000], ['voice_call', 1, 1_500_000]], $trabajos->map(fn ($j) => [$j->channel, (int) $j->reserved_units, (int) $j->reserved_micros])->all());
        $canal = DB::table('outreach_budget_counters')->where('scope', 'channel:voice_call')->where('mode', 'live')->first();
        $this->assertSame([2, 3_000_000, 2, 3_000_000], [(int) $canal->reserved_units, (int) $canal->reserved_micros, (int) $canal->cap_units, (int) $canal->cap_micros]);
        $aplazo = DB::table('outreach_decisions')->where('kind', 'defer')->sole();
        $this->assertSame('N4', $aplazo->level);
        $this->assertEqualsCanonicalizing(['budget_exhausted', 'channel_daily_cap'], json_decode((string) $aplazo->reasons, true));
        $this->assertNothingAsked();
    }

    public function test_el_monitor_frena_la_voz_por_budget_breach_con_el_techo_que_la_configuracion_dice_ahora(): void
    {
        $this->voiceLive(['outreach.monitor.enabled' => true] + self::BUDGET);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $this->v9Member(phone: '3001239001');
        $this->v9Member(phone: '3001239002');
        $planner = new OutreachPlanner(inputs: self::openInputs());
        $planner->sweep(now(), true);
        $this->assertSame(2, $planner->planDue(now())['planned']);
        $monitor = new OutreachMonitor;
        $this->assertSame([], $monitor->run(now())['opened'], 'dentro del techo: nada');

        // El techo baja a 2 US$ con 3 US$ ya reservados: el monitor echa el freno del canal de voz (nunca lo suelta).
        config(['outreach.voice.daily_budget_usd' => '2']);
        $r = $monitor->run(now());

        $this->assertSame([['scope' => 'channel', 'scopeRef' => 'voice_call', 'reason' => 'budget_breach']], $r['opened']);
        $this->assertSame(['voice_call'], (new OutreachStop)->openScopes()['channels']);
        $evidencia = json_decode((string) DB::table('incidents')->where('source', OutreachStop::INCIDENT_SOURCE)->where('kind', OutreachStop::INCIDENT_KIND)->value('evidence'), true);
        $this->assertSame([2, 2, 3_000_000, 2_000_000], [$evidencia['reserved_units'], $evidencia['cap_units'], $evidencia['reserved_micros'], $evidencia['cap_micros']]);
        // Los dos planificados esperan (canal frenado): el despachador no reclama ninguno.
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $this->assertSame(0, (new OutreachDispatcher(inputs: self::openInputs()))->dispatch(now(), null, true)['claimed']);
        $this->assertSame(2, DB::table('outreach_jobs')->where('status', 'planned')->count());
        $this->assertNothingAsked();
    }

    /**
     * `config/outreach.php` evaluado SIN las variables del H9 del entorno y con
     * las que se pasen (como la guarda de SEC9, `OutreachFlagsGuardTest`).
     *
     * @param  array<string, string>  $puestas
     * @return array<string, mixed>
     */
    private function configWith(array $puestas): array
    {
        $nombres = [];
        foreach (array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys((array) getenv()), array_keys($puestas)) as $nombre) {
            foreach (self::PREFIXES as $prefijo) {
                if (is_string($nombre) && str_starts_with($nombre, $prefijo)) {
                    $nombres[$nombre] = true;
                }
            }
        }
        $antes = [];
        foreach (array_keys($nombres) as $nombre) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
        }
        foreach ($puestas as $nombre => $valor) {
            $_SERVER[$nombre] = $valor;
            $_ENV[$nombre] = $valor;
            putenv("{$nombre}={$valor}");
        }

        try {
            return require base_path('config/outreach.php');
        } finally {
            foreach ($puestas as $nombre => $valor) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
            }
            foreach ($antes as $nombre => [$servidor, $entorno, $proceso]) {
                if ($servidor !== null) {
                    $_SERVER[$nombre] = $servidor;
                }
                if ($entorno !== null) {
                    $_ENV[$nombre] = $entorno;
                }
                if ($proceso !== false) {
                    putenv("{$nombre}={$proceso}");
                }
            }
        }
    }
}
