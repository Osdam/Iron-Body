<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachDispatcher;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * Cierre de B9 · los motivos PASAJEROS de la voz en `settle()` (W9 gancho 1,
 * «evalúa también los de la voz, como el freno»): la escalera dejó salir
 * (presupuesto de voz puesto, la constante abierta solo con el doble de W9) y
 * entre la escalera y la puerta del H8 se echó el freno de voz (`voice_braked`,
 * el MISMO que N2 mira por `Facts`), se apagó el canal o la maestra dejó el
 * modo en vivo: la reserva vuelve y la inscripción espera, sin `failed`. Lo que
 * la escalera no ve (concurrencia, cupos y horario propio del H8) sigue siendo
 * `failed`, y el `on.failed` del paso decide. La puerta del H8 la representa
 * un canal doble: en el árbol compartido la constante del H9 cierra el canal
 * real antes de llegar a ella.
 */
class TransientVoiceFailureTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    private const NOW = '2026-11-17 15:00:00';

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
        $this->runFlags('live', ['outreach.voice.enabled' => true, 'outreach.voice.daily_cap' => 2, 'outreach.voice.daily_budget_usd' => '3', 'outreach.voice.reserve_usd_per_call' => '1.5']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function motivos(): array
    {
        return [
            'el freno de voz del H8' => ['voice_braked', 'waiting', 'N2'],
            'la maestra dejó el modo en vivo' => ['mode_off', 'waiting', 'N2'],
            'el canal de voz se apagó' => ['channel_disabled', 'waiting', 'N4'],
            'otra llamada en curso (la escalera no la ve)' => ['concurrency_exceeded', 'failed', ''],
            'el horario propio del H8 (la escalera no lo ve)' => ['outside_hours', 'failed', ''],
        ];
    }

    #[DataProvider('motivos')]
    public function test_la_puerta_de_la_voz_niega_por_algo_que_cambio_entre_la_escalera_y_ella(string $codigo, string $esperado, string $nivel): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $p = $this->v9VoiceJob($vivo, $this->v9Member(), 'claimed');
        $despachador = new OutreachDispatcher(
            inputs: new LadderInputBuilder(sendsEnabledInCode: static fn (): bool => true),
            channels: [new SimulatedChannel, new AssistedChannel, new WhatsappTemplateChannel, self::gate($codigo)],
        );

        $this->assertSame($esperado, $despachador->process((int) $p['job']->id, now()));

        $trabajo = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame('failed_to_start', $trabajo->status);
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva vuelve siempre');
        $e = DB::table('outreach_enrollments')->where('id', $p['enrollment']->id)->first();
        if ($esperado === 'waiting') {
            $this->assertSame(0, DB::table('outreach_outcomes')->count(), 'sin `failed`');
            $this->assertSame('active', $e->status);
            $this->assertSame(now()->addMinutes(OutreachDispatcher::WAIT_MINUTES)->format('Y-m-d H:i:s'), substr((string) $e->next_due_at, 0, 19));
            $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
            $this->assertSame([$nivel, [$codigo]], [$decision->level, json_decode((string) $decision->reasons, true)]);
        } else {
            $this->assertSame(['failed', $codigo], [DB::table('outreach_outcomes')->value('outcome'), DB::table('outreach_outcomes')->value('detail_code')]);
            $this->assertSame(0, DB::table('outreach_decisions')->where('kind', 'cancel')->count());
        }
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
    }

    /** La puerta del H8 diciendo ese motivo (el canal de voz lo traduce a `failed_to_start` con él). */
    private static function gate(string $codigo): OutreachChannel
    {
        return new class($codigo) implements OutreachChannel
        {
            public function __construct(private readonly string $codigo) {}

            public function name(): string
            {
                return VoiceStrategyChannel::NAME;
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                return VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['code' => 'outbound_denied', 'reasons' => [$this->codigo]], 'callId' => null]);
            }
        };
    }
}
