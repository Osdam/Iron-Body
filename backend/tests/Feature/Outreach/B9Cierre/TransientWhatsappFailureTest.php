<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachDispatcher;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * Cierre de B9 · W9 gancho 1: `OutreachDispatcher::settle()` ante un
 * `failed_to_start` PASAJERO. La CARRERA real: la escalera de la etapa 6 ve el
 * agente activo y la pausa llega justo antes de la puerta de WhatsApp (la de
 * Marketing, la de siempre), que devuelve `agent_paused`. Antes del cierre eso
 * era `failed` → `end_exit` (la persona perdía el ciclo); ahora la reserva
 * vuelve, la inscripción ESPERA (+15 min, como N2) con una decisión `cancel`
 * que lo explica, sin resultado, y al soltar la pausa la persona sigue.
 * Lo que no es pasajero sigue igual (`failed`).
 *
 * La «puerta abierta» es el doble de W9 (la constante no se gira); Meta es
 * el Graph falso de W9 y nada sale a la red.
 */
class TransientWhatsappFailureTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** El despachador con la puerta abierta solo aquí y el canal de WhatsApp que se pide. */
    private function dispatcherWith(OutreachChannel $whatsapp): OutreachDispatcher
    {
        return new OutreachDispatcher(
            inputs: new LadderInputBuilder(sendsEnabledInCode: self::gateOpen()),
            channels: [new SimulatedChannel, new AssistedChannel, $whatsapp, new VoiceStrategyChannel],
        );
    }

    /** El canal REAL de W9, con algo que ocurre justo antes de su petición (la carrera). */
    private static function racing(Closure $antes, OutreachChannel $canal): OutreachChannel
    {
        return new class($antes, $canal) implements OutreachChannel
        {
            public function __construct(private readonly Closure $antes, private readonly OutreachChannel $canal) {}

            public function name(): string
            {
                return $this->canal->name();
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                ($this->antes)();

                return $this->canal->attempt($job, $enrollment, $live, $now);
            }
        };
    }

    private static function pauseAgent(): void
    {
        DB::table('marketing_agent_settings')->updateOrInsert(['id' => 1], ['paused' => true, 'paused_at' => now(), 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function test_la_pausa_que_llega_entre_la_escalera_y_la_puerta_no_es_un_fallo_la_reserva_vuelve_y_la_inscripcion_espera(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $this->assertSame(1, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:whatsapp_template')->value('reserved_units'));

        $r = $this->dispatcherWith(self::racing(self::pauseAgent(...), new WhatsappTemplateChannel(self::gateOpen())))->dispatch(now(), null, true);

        $this->assertSame(['waiting' => 1], $r['results'], 'pasajera: espera, no falla');
        $trabajo = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame('failed_to_start', $trabajo->status, 'desde `dispatching` el grafo solo admite esta salida');
        $this->assertNull($trabajo->cancel_reason);
        $this->assertNotNull($trabajo->budget_released_at);
        foreach (DB::table('outreach_budget_counters')->where('mode', 'live')->get() as $contador) {
            $this->assertSame([0, 0], [(int) $contador->reserved_units, (int) $contador->reserved_micros], "la reserva vuelve: {$contador->scope}");
        }
        $this->assertSame(0, DB::table('outreach_outcomes')->count(), 'sin `failed`: nada cierra la secuencia');
        $e = DB::table('outreach_enrollments')->where('id', $p['job']->enrollment_id)->first();
        $this->assertSame('active', $e->status);
        $this->assertNull($e->exit_reason);
        $this->assertSame(now()->addMinutes(OutreachDispatcher::WAIT_MINUTES)->format('Y-m-d H:i:s'), substr((string) $e->next_due_at, 0, 19), 'se vuelve a mirar en 15 min');
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame(['N2', ['agent_paused'], (int) $p['job']->id], [$decision->level, json_decode((string) $decision->reasons, true), (int) $decision->job_id]);
        $this->assertSame(0, DB::table('marketing_messages')->count(), 'la puerta no dejó fila');
        Http::assertNothingSent();

        // Mientras siga la pausa, la escalera la ve (N2) y espera sin trabajo; al soltarla, la persona sigue en su ciclo.
        Carbon::setTestNow(now()->addMinutes(OutreachDispatcher::WAIT_MINUTES));
        $planner = $this->openPlanner();
        $this->assertSame(1, $planner->planDue(now())['waiting']);
        DB::table('marketing_agent_settings')->where('id', 1)->update(['paused' => false, 'updated_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(OutreachDispatcher::WAIT_MINUTES));
        $this->assertSame(1, $planner->planDue(now())['planned']);
        $this->assertSame(2, (int) DB::table('outreach_jobs')->where('enrollment_id', $p['job']->enrollment_id)->where('status', 'planned')->value('attempt'), 'otro intento, otra fila');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pasajeros(): array
    {
        return [
            'el canal se apagó' => ['channel_disabled', 'N4'],
            'la maestra dejó el modo en vivo' => ['mode_not_live', 'N2'],
            'una persona tomó la conversación' => ['human_takeover', 'N2'],
        ];
    }

    #[DataProvider('pasajeros')]
    public function test_cada_condicion_pasajera_del_canal_hace_esperar_con_su_nivel(string $codigo, string $nivel): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));

        $r = $this->dispatcherWith(self::stub($codigo))->dispatch(now(), null, true);

        $this->assertSame(['waiting' => 1], $r['results']);
        $this->assertSame([$nivel, [$codigo]], [DB::table('outreach_decisions')->where('kind', 'cancel')->value('level'), json_decode((string) DB::table('outreach_decisions')->where('kind', 'cancel')->value('reasons'), true)]);
        $this->assertSame(0, DB::table('outreach_outcomes')->count());
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $p['job']->enrollment_id)->value('status'));
    }

    public function test_lo_que_no_es_pasajero_sigue_siendo_failed_con_su_detalle(): void
    {
        foreach (['template_not_approved_by_meta', 'sends_disabled_in_code', 'no_proactive_consent'] as $codigo) {
            $this->assertArrayNotHasKey($codigo, OutreachDispatcher::TRANSIENT_FAILURES, $codigo);
        }
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));

        $r = $this->dispatcherWith(self::stub('template_not_approved_by_meta'))->dispatch(now(), null, true);

        $this->assertSame(['failed' => 1], $r['results']);
        $this->assertSame(['failed', 'template_not_approved_by_meta'], [DB::table('outreach_outcomes')->value('outcome'), DB::table('outreach_outcomes')->value('detail_code')]);
        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', 'cancel')->count());
        $this->assertNotNull(DB::table('outreach_jobs')->where('id', $p['job']->id)->value('budget_released_at'));
    }

    /** Un canal de WhatsApp que devuelve ese `failed_to_start` (lo que diría la puerta). */
    private static function stub(string $codigo): OutreachChannel
    {
        return new class($codigo) implements OutreachChannel
        {
            public function __construct(private readonly string $codigo) {}

            public function name(): string
            {
                return WhatsappTemplateChannel::NAME;
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                return ChannelAttempt::failed($this->codigo);
            }
        };
    }
}
