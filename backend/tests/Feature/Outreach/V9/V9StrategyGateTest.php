<?php

namespace Tests\Feature\Outreach\V9;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Voice\VoiceCallPolicy;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * V9 · LA ENTRADA DE LAS ESTRATEGIAS EN LA PUERTA DE SALIENTES DEL H8
 * (`OutboundGate::requestForStrategy()`; contrato H9, D9-9.4; Enmienda 17 del
 * H8 propuesta). En el árbol compartido, con `CALLS_ENABLED_IN_CODE = false`:
 *
 *  - con TODO lo demás en regla, la única negativa es la constante del H8 y no
 *    se crea nada (ni fila, ni evento, ni job);
 *  - recorre LA MISMA política que la entrada de las personas: para el mismo
 *    destino y el mismo mundo, los MISMOS motivos, uno a uno, con cada motivo
 *    de `OutboundPolicy` que se puede provocar sin una llamada previa;
 *  - el destino lo elige la puerta: el contacto de voz con consentimiento de
 *    la persona; cobranza y servicio no tienen consentimiento de voz; nunca un
 *    canario fuera de la parte canaria de la estrategia ni sin consentimiento;
 *  - una petición sin forma es 422 y no toca nada; la idempotencia es por
 *    trabajo (la misma llamada; otro destino, 409).
 *
 * Cero red (`Http::preventStrayRequests()`); la cola, falsa.
 */
class V9StrategyGateTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    /** Martes 17 de noviembre de 2026, 10:00 de Bogotá: laborable, sin festivo, dentro de la ventana legal. */
    private const NOW = '2026-11-17 15:00:00';

    private const KEY = 'renovacion-voz';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->v9VoiceConfig();
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array{member: int, lead: int}  $m @return array{http: int, body: array<string, mixed>, callId: ?int} */
    private function porEstrategia(array $m, string $finalidad = 'commercial', bool $parteCanaria = false, ?string $trabajo = null): array
    {
        return app(OutboundGate::class)->requestForStrategy((string) ($trabajo ?? Str::uuid()), $finalidad, [$m['member']], [$m['lead']], $parteCanaria, self::KEY);
    }

    /** @param  array{contact: int}  $m @return list<string> los motivos de la entrada de las PERSONAS para el mismo contacto */
    private function porPersona(array $m): array
    {
        $uuid = (string) DB::table('voice_contacts')->where('id', $m['contact'])->value('uuid');
        $r = app(OutboundGate::class)->request($this->threePeople()['ana'], OutboundGate::TARGET_CONTACT, $uuid, (string) Str::uuid());

        return $r['body']['reasons'] ?? ['__aceptada__'];
    }

    private function assertNadaCreado(): void
    {
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows(), 'la puerta no creó nada');
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
    }

    public function test_con_todo_en_regla_la_unica_negativa_es_la_constante_del_h8_y_no_se_crea_nada(): void
    {
        $this->assertFalse(VoiceCallPolicy::CALLS_ENABLED_IN_CODE, 'el árbol compartido nunca abre la constante del H8');
        $this->v9GreenVoice();
        $m = $this->v9Member();

        $r = $this->porEstrategia($m);
        $this->assertSame(409, $r['http']);
        $this->assertSame('outbound_denied', $r['body']['code']);
        $this->assertSame(['calls_disabled_in_code'], $r['body']['reasons'], 'banderas, número, proveedor, cadena, destino, calendario, cupos y presupuesto en regla');
        $this->assertNull($r['callId']);
        $this->assertSame(['calls_disabled_in_code'], $this->porPersona($m), 'la entrada de las personas dice lo mismo');
        $this->assertNadaCreado();
        Http::assertNothingSent();
    }

    /**
     * Cada motivo de `OutboundPolicy` que se puede provocar sin una llamada
     * previa, con el resto en regla: la entrada de las estrategias lo da, en el
     * orden cerrado, detrás de la constante, y la de las personas da
     * EXACTAMENTE los mismos motivos para el mismo contacto.
     *
     * @return array<string, array{0: string, 1: Closure}>
     */
    public static function motivos(): array
    {
        return [
            'salientes apagadas' => ['outbound_disabled', static fn (self $t) => config(['voice.outbound.enabled' => false])],
            'Twilio Voice apagado' => ['twilio_voice_disabled', static fn (self $t) => config(['voice.twilio.enabled' => false])],
            'sin número de voz' => ['no_voice_number', static fn (self $t) => config(['voice.twilio.from' => null])],
            'el número es el de la mensajería' => ['voice_number_is_whatsapp', static fn (self $t) => config(['meta.whatsapp_display_phone' => self::V9_VOICE_FROM])],
            'número sin vigilar' => ['number_unverified', static fn (self $t) => Cache::forget(VoiceNumberGuard::OBSERVED_CACHE_KEY)],
            'número que no es el declarado' => ['number_mismatch', static fn (self $t) => VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, false, false)],
            'ElevenLabs apagado' => ['elevenlabs_disabled', static fn (self $t) => config(['voice.elevenlabs.enabled' => false])],
            'ElevenLabs sin configurar' => ['elevenlabs_not_configured', static fn (self $t) => config(['voice.elevenlabs.api_key' => null])],
            'ElevenLabs sin verificar' => ['elevenlabs_unverified', static fn (self $t) => Cache::forget(VoiceProviderState::VERIFIED_CACHE_KEY)],
            'cadena de voz falsificada' => ['publication_unreadable', static fn (self $t) => $t->v9ForgeVoiceHead()],
            'deriva del agente' => ['agent_drift', static fn (self $t) => Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::V9_REST_AGENT, true, 300)],
            'freno de voz' => ['voice_braked', static fn (self $t) => VoiceBrake::open('cost_cap', 'cost_cap:v9-prueba', [])],
            'exclusión del número' => ['excluded', static fn (self $t, array $m) => $t->voiceExclusion($m['contactHash'], 'admin')],
            'RNE sin vigencia' => ['rne_check_missing', static fn (self $t) => config(['voice.contacts.rne_validity_days' => 0])],
            'fuera del horario legal' => ['outside_hours', static fn (self $t) => Carbon::setTestNow(Carbon::parse('2026-11-17 19:30:00', 'America/Bogota'))],
            'festivo' => ['holiday', static fn (self $t) => Carbon::setTestNow(Carbon::parse('2026-12-08 10:00:00', 'America/Bogota'))],
            'cupo del día en cero' => ['daily_cap_zero', static fn (self $t) => config(['voice.twilio.daily_call_cap' => 0])],
            'presupuesto del día agotado' => ['budget_exhausted', static fn (self $t) => config(['voice.limits.daily_budget_usd' => '0.01'])],
        ];
    }

    #[DataProvider('motivos')]
    public function test_cada_motivo_de_la_politica_sale_igual_que_en_la_entrada_de_las_personas(string $motivo, Closure $provocar): void
    {
        $this->v9GreenVoice();
        $m = $this->v9Member();
        $provocar($this, $m);

        $r = $this->porEstrategia($m);
        $this->assertSame(409, $r['http'], $motivo);
        $this->assertContains($motivo, $r['body']['reasons']);
        $this->assertSame('calls_disabled_in_code', $r['body']['reasons'][0], 'la constante del H8, la primera');
        $this->assertSame(array_values(array_intersect(OutboundPolicy::REASONS, $r['body']['reasons'])), $r['body']['reasons'], 'en el orden cerrado de OutboundPolicy::REASONS');
        $this->assertSame($this->porPersona($m), $r['body']['reasons'], "la misma política que la entrada de las personas ({$motivo})");
        $this->assertNadaCreado();
    }

    public function test_sin_publicacion_de_voz_no_hay_llamada(): void
    {
        VoiceProviderState::recordStudioVerification(true, now());
        VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, true, false);
        $m = $this->v9Member();

        $r = $this->porEstrategia($m);
        $this->assertSame(['calls_disabled_in_code', 'no_publication'], $r['body']['reasons']);
        $this->assertSame($this->porPersona($m), $r['body']['reasons']);
        $this->assertNadaCreado();
    }

    public function test_el_destino_lo_elige_la_puerta_y_solo_con_consentimiento_para_la_finalidad(): void
    {
        $this->v9GreenVoice();

        // Sin contacto de voz: la política dice por qué.
        $sinContacto = $this->expiringMember(self::utcAt(self::NOW), 5);
        $lead = $this->lead(['member_id' => $sinContacto['member'], 'phone' => '3001239777', 'consent_status' => 'granted']);
        $r = app(OutboundGate::class)->requestForStrategy((string) Str::uuid(), 'commercial', [$sinContacto['member']], [$lead], false, self::KEY);
        $this->assertSame(['calls_disabled_in_code', 'contact_not_authorized', 'no_voice_consent', 'rne_check_missing'], $r['body']['reasons'], 'sin contacto no hay número ni consulta al RNE');

        // Con contacto y sin consentimiento.
        $sinConsentimiento = $this->v9Member(consent: false, phone: '3001239002');
        $this->assertSame(['calls_disabled_in_code', 'no_voice_consent'], $this->porEstrategia($sinConsentimiento)['body']['reasons']);

        // Con consentimiento comercial: cobranza y servicio no lo tienen (D9-15).
        $conConsentimiento = $this->v9Member(phone: '3001239003');
        $this->assertSame(['calls_disabled_in_code'], $this->porEstrategia($conConsentimiento, 'commercial')['body']['reasons']);
        foreach (['collections', 'service'] as $finalidad) {
            $this->assertSame(['calls_disabled_in_code', 'no_voice_consent'], $this->porEstrategia($conConsentimiento, $finalidad)['body']['reasons'], $finalidad);
        }
        $this->assertNadaCreado();
    }

    public function test_nunca_un_canario_fuera_de_la_parte_canaria_ni_sin_consentimiento(): void
    {
        $this->v9GreenVoice();
        config(['voice.outbound.scope' => 'canary']);
        $m = $this->v9Member();   // su número es también un canario de voz ACTIVO

        // La estrategia en su parte `rest` con el alcance `canary` (4a): el destino es el contacto → la 4a no lo admite.
        $this->assertSame(['calls_disabled_in_code', 'not_an_active_voice_canary'], $this->porEstrategia($m, parteCanaria: false)['body']['reasons']);
        // En su parte canaria: el canario que es ESE número; solo queda la constante.
        $this->assertSame(['calls_disabled_in_code'], $this->porEstrategia($m, parteCanaria: true)['body']['reasons']);
        // El canario de ese número sin consentimiento de voz de la persona: nunca.
        $sin = $this->v9Member(consent: false, phone: '3001239004');
        $this->assertSame(['calls_disabled_in_code', 'not_an_active_voice_canary'], $this->porEstrategia($sin, parteCanaria: true)['body']['reasons']);
        // Fuera del alcance `canary`, la parte canaria sigue llamando al contacto (4b).
        config(['voice.outbound.scope' => 'contacts']);
        $this->assertSame(['calls_disabled_in_code'], $this->porEstrategia($m, parteCanaria: true)['body']['reasons']);
        $this->assertNadaCreado();
    }

    public function test_una_peticion_sin_forma_es_422_y_no_toca_nada(): void
    {
        $this->v9GreenVoice();
        $puerta = app(OutboundGate::class);
        foreach ([
            'trabajo sin uuid' => [['no-es-un-uuid', 'commercial', [1], [], false, self::KEY], ['job_uuid_invalid']],
            'finalidad fuera de la lista' => [[(string) Str::uuid(), 'ventas', [1], [], false, self::KEY], ['purpose_key_invalid']],
            'persona vacía' => [[(string) Str::uuid(), 'commercial', [], [], false, self::KEY], ['subject_invalid']],
            'ids que no son enteros positivos' => [[(string) Str::uuid(), 'commercial', [0], ['7'], false, self::KEY], ['subject_invalid']],
            'clave de estrategia sin forma' => [[(string) Str::uuid(), 'commercial', [1], [], false, 'Renovación!'], ['strategy_key_invalid']],
            'todo mal' => [['x', 'x', [], [], false, 'x'], ['job_uuid_invalid', 'purpose_key_invalid', 'subject_invalid', 'strategy_key_invalid']],
        ] as $caso => [$args, $codigos]) {
            $r = $puerta->requestForStrategy(...$args);
            $this->assertSame([422, 'strategy_request_invalid', $codigos, null], [$r['http'], $r['body']['code'], $r['body']['reasons'], $r['callId']], $caso);
        }
        $this->assertNadaCreado();
    }

    public function test_la_idempotencia_es_por_trabajo_y_otro_destino_es_un_conflicto(): void
    {
        $this->v9GreenVoice();
        $m = $this->v9Member();
        $trabajo = (string) Str::uuid();
        // La llamada que ese trabajo ya pidió (la forma que escribe la puerta).
        $previa = $this->v9StrategyCall($trabajo, $m['contact'], $m['e164'], 'dialing');

        $r = $this->porEstrategia($m, trabajo: strtoupper($trabajo));
        $this->assertSame([200, true, true, $previa], [$r['http'], $r['body']['ok'], $r['body']['duplicate'], $r['callId']], 'la misma fila, sin pasar por la política');
        $this->assertArrayNotHasKey('reasons', $r['body']);
        $this->assertSame(1, VoiceCall::query()->count());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);

        // La persona tiene ahora un contacto MÁS RECIENTE con consentimiento: la puerta elegiría otro destino → conflicto, nada nuevo.
        $nuevo = $this->v9VoiceContact('member', $m['member'], '+573001239005');
        $this->voiceConsent($nuevo['id'], 'granted', 2, '2026-11-10 12:00', 1);
        $this->rneCheck($nuevo['hash'], '2026-11-15 12:00');
        $conflicto = $this->porEstrategia($m, trabajo: $trabajo);
        $this->assertSame([409, 'client_request_reused', null], [$conflicto['http'], $conflicto['body']['code'], $conflicto['callId']]);
        $this->assertSame(1, VoiceCall::query()->count());
    }
}
