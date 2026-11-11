<?php

namespace Tests\Feature\Outreach\V9;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\MarketingConversation;
use App\Models\Voice\VoiceCall;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\VoiceCallOutcomes;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceCallStarter;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * V9 · LA VÍA ABIERTA, SOLO EN UNA COPIA DEL ÁRBOL (protección 2 del H9 y del
 * H8: las constantes NUNCA se giran en el árbol compartido). La lanza
 * `docs/ultron-studio/ops/v9-abierta.sh`, que copia el árbol, gira en la
 * copia `VoiceCallPolicy::CALLS_ENABLED_IN_CODE` y
 * `OutreachSendGate::SENDS_ENABLED_IN_CODE` y declara `voice_call` en
 * `OutreachSendGate::LIVE_CHANNELS` (y comprueba que la copia no difiere en
 * nada más). En el árbol compartido, estas pruebas se omiten.
 *
 * Con todo encendido:
 *  - la petición del canal LLEGA a la puerta: 202, la fila `requested` con el
 *    solicitante `strategy`, el trabajo `started` con su referencia, y el job
 *    de la saliente en la cola; la llamada sigue `requested` hasta que corre
 *    el worker, y con el worker FALSO pasa a `dialing` (nada sale a la red);
 *  - cada motivo de `OutboundPolicy` que la puerta puede dar a una estrategia
 *    es `failed_to_start` con ese detalle, sin fila, sin job, la reserva
 *    liberada y el resultado `failed`;
 *  - aun así, el despachador NO llega al canal: la escalera niega
 *    `budget_zero` (N4) porque la voz no tiene presupuesto ni cupo propios en
 *    la configuración del H9 (los ganchos que faltan, informe de V9).
 */
class V9OpenGateTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    /** Martes 17 de noviembre de 2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        if (! (VoiceCallPolicy::CALLS_ENABLED_IN_CODE === true && OutreachSendGate::sendsEnabledInCode() && OutreachSendGate::channelLive(VoiceStrategyChannel::NAME))) {
            $this->markTestSkipped('Solo en una COPIA del árbol con las dos constantes abiertas y la voz declarada viva (docs/ultron-studio/ops/v9-abierta.sh); nunca en el árbol compartido.');
        }
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->v9VoiceConfig();
        $this->threePeople();
        $this->v9GreenVoice();
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $vivo @param  array{job: object, enrollment: object}  $p */
    private function intento(array $vivo, array $p): ChannelAttempt
    {
        $live = (new OutreachPublishedStrategies)->liveVersion($vivo['version'], $vivo['part']);

        return (new VoiceStrategyChannel)->attempt($p['job'], $p['enrollment'], $live, now()->toDateTimeImmutable());
    }

    /**
     * R-1 de sec-fix-baja (L9-90; D9-15): quien escribió «BAJA» por WhatsApp en una conversación que el H9 nunca vio
     * (la de ULTRON Legacy, sin ningún trabajo del H9 detrás) no recibe la llamada de una estrategia: el canal se niega
     * con `opt_out_word` ANTES de la puerta y no se crea ninguna llamada. El control, sin la baja, sí llega a la puerta.
     */
    public function test_una_baja_escrita_por_whatsapp_fuera_del_h9_impide_la_llamada_de_una_estrategia(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member();
        $conversacion = MarketingConversation::query()->where('lead_id', $m['lead'])->where('channel', 'whatsapp')->firstOrFail();
        app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.legacy-baja', 'BAJA', ['message_type' => 'text']);
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');

        $intento = $this->intento($vivo, $p);
        $this->assertSame([ChannelAttempt::FAILED_TO_START, 'opt_out_word'], [$intento->status, $intento->detailCode]);
        $this->assertSame(0, VoiceCall::query()->count(), 'ninguna llamada pedida a la puerta');
    }

    public function test_la_peticion_llega_a_la_puerta_y_queda_requested_hasta_el_worker_falso(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member();
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');

        $intento = $this->intento($vivo, $p);
        $this->assertSame(ChannelAttempt::STARTED, $intento->status, (string) $intento->detailCode);
        $llamada = VoiceCall::query()->findOrFail($intento->refs['voice_call_id']);
        $this->assertSame(
            ['requested', 'strategy', null, null, 'Estrategia '.DB::table('ultron_studio_strategies')->where('id', $vivo['strategy'])->value('key'), strtolower((string) $p['job']->uuid), 1,
                'voice_contacts', $m['contact'], null, self::v9Hash($m['e164']), 'rest', self::V9_REST_AGENT],
            [$llamada->status, $llamada->requested_by_type, $llamada->requested_by_admin_id, $llamada->requested_by_role, $llamada->requested_by_name, $llamada->client_request_id, $llamada->attempt,
                $llamada->target_source, $llamada->voice_contact_id, $llamada->canary_number_uuid, $llamada->remote_hash, $llamada->part, $llamada->provider_agent_id],
        );
        $evento = DB::table('voice_call_events')->where('call_id', $llamada->id)->sole();
        $this->assertSame(['outbound_requested', 'system'], [$evento->kind, $evento->actor_type]);
        $this->assertSame(['strategy', 'commercial'], [json_decode((string) $evento->codes, true)['requester'], json_decode((string) $evento->codes, true)['purposeKey']]);
        Queue::assertPushed(PlaceOutboundVoiceCall::class, 1);

        // El despachador asienta: `started` con la referencia, una vez.
        $this->assertSame('started', (new ReflectionMethod(OutreachDispatcher::class, 'settle'))->invoke(new OutreachDispatcher, $p['job'], $p['enrollment'], $vivo['strategyUuid'], $intento, now()->toDateTimeImmutable()));
        $this->assertSame(['started', (int) $llamada->id], [DB::table('outreach_jobs')->where('id', $p['job']->id)->value('status'), (int) DB::table('outreach_jobs')->where('id', $p['job']->id)->value('voice_call_id')]);

        // Repetir la petición del MISMO trabajo es la misma llamada (sin fila, ni evento, ni job nuevos).
        $otra = app(OutboundGate::class)->requestForStrategy((string) $p['job']->uuid, 'commercial', [$m['member']], [$m['lead']], false, 'renovacion-voz');
        $this->assertSame([200, (int) $llamada->id], [$otra['http'], $otra['callId']]);
        $this->assertSame(1, VoiceCall::query()->count());
        Queue::assertPushed(PlaceOutboundVoiceCall::class, 1);

        // Sin worker la llamada sigue `requested`: nada salió.
        $this->assertSame('requested', $llamada->refresh()->status);
        $this->assertSame([], (new VoiceCallOutcomes)->run(now())['outcomes']);

        // Con el worker FALSO: el job vuelve a mirarlo todo y "marca" contra el falso → `dialing`. Ninguna red.
        $falso = new class implements VoiceCallStarter
        {
            public ?OutboundCallRequest $recibida = null;

            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                $this->recibida = $request;

                return new OutboundCallResult(OutboundCallResult::OUTCOME_STARTED, null, 'conv_v9_abierta_0001', 'CA'.substr(hash('sha256', 'v9-abierta'), 0, 32), 200);
            }
        };
        $this->assertSame('dialing', (new PlaceOutboundVoiceCall((int) $llamada->id))->run($falso));
        $this->assertSame([self::V9_REST_AGENT, $m['e164'], (string) $llamada->call_ref], [$falso->recibida->agentId, $falso->recibida->toNumber(), $falso->recibida->callRef]);
        Http::assertNothingSent();

        // El normalizador lo ve salir.
        $this->assertSame(['sent' => 1], (new VoiceCallOutcomes)->run(now())['outcomes']);
    }

    /**
     * Cada motivo de la política que la puerta puede dar a una ESTRATEGIA, con
     * el resto en regla. Fuera de la lista, y por qué: `calls_disabled_in_code`
     * (solo con la constante cerrada: `V9StrategyGateTest`), `no_publication`
     * (la cadena de voz es de solo inserción: sin publicar, en
     * `V9StrategyGateTest`), `no_voice_consent`
     * y `contact_not_authorized` (el canal los niega ANTES de pedir, con
     * `no_consent_for_purpose_channel`; la puerta los da en `V9StrategyGateTest`),
     * `source_not_allowed` (el destino de una estrategia es siempre un contacto
     * o un canario) y `duplicate_request` (la idempotencia es antes de la
     * política: la misma llamada).
     *
     * @return array<string, array{0: string, 1: Closure}>
     */
    public static function motivos(): array
    {
        $vigilar = static function (): void {
            VoiceProviderState::recordStudioVerification(true, now());
            VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, true, false);
        };

        return [
            'salientes apagadas' => ['outbound_disabled', static fn () => config(['voice.outbound.enabled' => false])],
            'Twilio Voice apagado' => ['twilio_voice_disabled', static fn () => config(['voice.twilio.enabled' => false])],
            'sin número de voz' => ['no_voice_number', static fn () => config(['voice.twilio.from' => null])],
            'el número es el de la mensajería' => ['voice_number_is_whatsapp', static fn () => config(['meta.whatsapp_display_phone' => self::V9_VOICE_FROM])],
            'número sin vigilar' => ['number_unverified', static fn () => Cache::forget(VoiceNumberGuard::OBSERVED_CACHE_KEY)],
            'número que no es el declarado' => ['number_mismatch', static fn () => VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, false, false)],
            'ElevenLabs sin configurar' => ['elevenlabs_not_configured', static fn () => config(['voice.elevenlabs.api_key' => null])],
            'ElevenLabs apagado' => ['elevenlabs_disabled', static fn () => config(['voice.elevenlabs.enabled' => false])],
            'ElevenLabs sin verificar' => ['elevenlabs_unverified', static fn () => Cache::forget(VoiceProviderState::VERIFIED_CACHE_KEY)],
            'cadena de voz falsificada' => ['publication_unreadable', static fn (self $t) => $t->v9ForgeVoiceHead()],
            'deriva del agente' => ['agent_drift', static fn () => Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::V9_REST_AGENT, true, 300)],
            'freno de voz' => ['voice_braked', static fn () => VoiceBrake::open('cost_cap', 'cost_cap:v9-abierta', [])],
            'la 4a no admite contactos' => ['not_an_active_voice_canary', static fn () => config(['voice.outbound.scope' => 'canary'])],
            'exclusión del número' => ['excluded', static fn (self $t, array $m) => $t->voiceExclusion($m['contactHash'], 'admin')],
            'RNE sin vigencia' => ['rne_check_missing', static fn () => config(['voice.contacts.rne_validity_days' => 0])],
            'fuera del horario legal' => ['outside_hours', static function () use ($vigilar): void {
                Carbon::setTestNow(Carbon::parse('2026-11-17 19:30:00', 'America/Bogota'));
                $vigilar();
            }],
            'festivo' => ['holiday', static function () use ($vigilar): void {
                Carbon::setTestNow(Carbon::parse('2026-12-08 10:00:00', 'America/Bogota'));
                $vigilar();
            }],
            'frecuencia del número' => ['frequency_exceeded', static fn (self $t, array $m) => $t->v9StrategyCall((string) Str::uuid(), $m['contact'], $m['e164'], 'completed')],
            'concurrencia' => ['concurrency_exceeded', static function (self $t): void {
                $otro = $t->v9Member(phone: '3001239301');
                $t->v9StrategyCall((string) Str::uuid(), $otro['contact'], $otro['e164'], 'dialing');
            }],
            'cupo del día en cero' => ['daily_cap_zero', static fn () => config(['voice.twilio.daily_call_cap' => 0])],
            'cupo del día alcanzado' => ['daily_cap_reached', static function (self $t): void {
                config(['voice.twilio.daily_call_cap' => 1]);
                $otro = $t->v9Member(phone: '3001239302');
                $t->v9StrategyCall((string) Str::uuid(), $otro['contact'], $otro['e164'], 'completed');
            }],
            'presupuesto del día agotado' => ['budget_exhausted', static fn () => config(['voice.limits.daily_budget_usd' => '0.01'])],
        ];
    }

    #[DataProvider('motivos')]
    public function test_cada_motivo_de_la_politica_es_failed_to_start_con_su_detalle_sin_fila_y_con_la_reserva_liberada(string $motivo, Closure $provocar): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member();
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');
        $provocar($this, $m);
        $antes = $this->v9VoiceRows();

        $intento = $this->intento($vivo, $p);
        $this->assertSame([ChannelAttempt::FAILED_TO_START, $motivo], [$intento->status, $intento->detailCode], $motivo);
        $this->assertSame($antes, $this->v9VoiceRows(), 'ni fila ni evento');
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
        $this->assertContains($motivo, OutboundPolicy::REASONS);

        // El despachador: `failed_to_start` y la reserva liberada; nada se reintenta. Un motivo PASAJERO (el freno de voz,
        // D-3 de b9-cierre, L9-88) no es un fallo: la inscripción espera y queda la decisión `cancel` con el motivo; los
        // demás dejan `failed` con el motivo.
        $pasajero = array_key_exists($motivo, OutreachDispatcher::TRANSIENT_FAILURES);
        $this->assertSame($pasajero ? 'waiting' : 'failed', (new ReflectionMethod(OutreachDispatcher::class, 'settle'))->invoke(new OutreachDispatcher, $p['job'], $p['enrollment'], $vivo['strategyUuid'], $intento, now()->toDateTimeImmutable()));
        $j = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame(['failed_to_start', null], [$j->status, $j->voice_call_id]);
        $this->assertNotNull($j->budget_released_at);
        if ($pasajero) {
            $this->assertSame(0, DB::table('outreach_outcomes')->where('job_id', $p['job']->id)->count(), 'un motivo pasajero no deja resultado');
            $canceladas = DB::table('outreach_decisions')->where('kind', 'cancel')->get()->filter(static fn (object $d): bool => in_array($motivo, (array) json_decode((string) $d->reasons, true), true));
            $this->assertCount(1, $canceladas, 'queda UNA decisión cancel con el motivo pasajero');
            $this->assertNotNull(DB::table('outreach_enrollments')->where('id', $p['enrollment']->id)->value('next_due_at'), 'la inscripción espera');
        } else {
            $this->assertSame([$motivo], DB::table('outreach_outcomes')->where('job_id', $p['job']->id)->where('outcome', 'failed')->pluck('detail_code')->all());
        }
        Http::assertNothingSent();
    }

    public function test_sin_consentimiento_el_canal_no_pide_nada(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $p = $this->v9VoiceJob($vivo, $this->v9Member(consent: false), 'dispatching');

        $intento = $this->intento($vivo, $p);
        $this->assertSame([ChannelAttempt::FAILED_TO_START, 'no_consent_for_purpose_channel'], [$intento->status, $intento->detailCode]);
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
    }

    public function test_el_despachador_no_llega_al_canal_sin_presupuesto_ni_cupo_de_voz_en_el_h9(): void
    {
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $p = $this->v9VoiceJob($vivo, $this->v9Member(), 'claimed');

        (new OutreachDispatcher)->process((int) $p['job']->id, now());
        $j = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame(['canceled', 'budget_zero'], [$j->status, $j->cancel_reason], 'N4: la voz no tiene presupuesto ni cupo propios en config/outreach.php (gancho pendiente)');
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
    }
}
