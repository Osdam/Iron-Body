<?php

namespace Tests\Feature\Outreach\V9;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Voice\VoiceCallPolicy;
use App\Support\Privacy\PhoneFingerprint;
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
 * H8 propuesta). C-4: con las llamadas abiertas en el código
 * (`CALLS_ENABLED_IN_CODE = true`, el commit `voice(calls-open)`):
 *
 *  - con TODO en regla, la puerta ACEPTA la llamada de la estrategia: UNA fila
 *    `requested` con el solicitante `strategy` (sin cuenta), el sello de la
 *    parte que atiende el alcance, la reserva, su evento y el job; y la entrada
 *    de las personas ve esa fila (los mismos hechos);
 *  - recorre LA MISMA política que la entrada de las personas: para el mismo
 *    destino y el mismo mundo, los MISMOS motivos, uno a uno, con cada motivo
 *    de `OutboundPolicy` que se puede provocar sin una llamada previa, y cada
 *    uno basta para negar sin crear nada;
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

    /**
     * C-4: la puerta ACEPTÓ (202) la llamada de la estrategia. UNA fila nueva
     * `requested` del solicitante `strategy` (sin cuenta ni rol, con la
     * etiqueta de la estrategia y el trabajo como `client_request_id`), con el
     * destino, el sello de la parte y su agente, la huella y los 4 dígitos
     * (nunca el número) y la reserva de un móvil (0,9605 US$) en el día de
     * Bogotá; su evento `outbound_requested` de sistema, y el job para ESA fila.
     *
     * @param  array{http: int, body: array<string, mixed>, callId: ?int}  $r
     * @param  array{e164: string, contact: int}  $m
     */
    private function assertAceptada(array $r, array $m, int $filasAntes, string $fuente, string $parte, string $agente, ?string $canario = null): VoiceCall
    {
        $this->assertSame([202, true], [$r['http'], $r['body']['ok']]);
        $this->assertIsInt($r['callId']);
        $this->assertSame($filasAntes + 1, VoiceCall::query()->count(), 'la puerta crea UNA fila');
        $fila = VoiceCall::query()->findOrFail($r['callId']);
        $this->assertSame($fila->uuid, $r['body']['call']['uuid']);
        $this->assertSame(['outbound', 'requested', $fuente, $fuente === 'voice_contacts' ? $m['contact'] : null, $canario, null, null],
            [$fila->direction, $fila->status, $fila->target_source, $fila->voice_contact_id, $fila->canary_number_uuid, $fila->caller_authorized, $fila->policy_reason]);
        $this->assertSame(['strategy', null, 'Estrategia '.self::KEY, null, 1], [$fila->requested_by_type, $fila->requested_by_admin_id, $fila->requested_by_name, $fila->requested_by_role, $fila->attempt]);
        $this->assertSame(['applied', $parte, $agente], [$fila->stamp_state, $fila->part, $fila->provider_agent_id], 'el sello de la parte que atiende el alcance');
        $this->assertSame([self::v9Hash($m['e164']), substr($m['e164'], -4)], [$fila->remote_hash, $fila->remote_last4]);
        $this->assertSame([960500, '2026-11-17'], [(int) $fila->reserved_micros, (string) $fila->budget_day], 'la reserva de un móvil, en el día de Bogotá');
        $evento = VoiceCallEvent::query()->where('call_id', $fila->id)->sole();
        $this->assertSame(['outbound_requested', 'system', 'Estrategia '.self::KEY], [$evento->kind, $evento->actor_type, $evento->actor_name]);
        $this->assertSame(['strategy', 'commercial', $parte, 960500], [$evento->codes['requester'], $evento->codes['purposeKey'], $evento->codes['part'], $evento->codes['reservedMicros']]);
        Queue::assertPushed(PlaceOutboundVoiceCall::class, static fn (PlaceOutboundVoiceCall $j): bool => $j->callId === (int) $fila->id);
        $this->assertStringNotContainsString(substr($m['e164'], 1), (string) json_encode($r['body']), 'la respuesta no lleva el número');

        return $fila;
    }

    /** La llamada aceptada, cancelada como la cancela el job sin petición: deja de contar y la siguiente mirada parte de cero. */
    private function liberar(VoiceCall $llamada): void
    {
        $this->assertTrue(VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => 'outbound_disabled', 'ended_at' => now()]));
    }

    /** El reloj a otra hora de Bogotá con el número que llama vigilado otra vez (la conciliación vale 2 h): solo cambia el calendario. */
    private function aLaHora(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota'));
        VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, true, false);
    }

    public function test_con_todo_en_regla_la_puerta_acepta_la_llamada_de_la_estrategia_y_la_entrada_de_las_personas_la_ve(): void
    {
        $this->assertTrue(VoiceCallPolicy::CALLS_ENABLED_IN_CODE, 'el árbol abierto: el commit voice(calls-open) del H8');
        $this->v9GreenVoice();
        $m = $this->v9Member();

        $r = $this->porEstrategia($m);
        $this->assertArrayNotHasKey('reasons', $r['body'], 'banderas, número, proveedor, cadena, destino, calendario, cupos y presupuesto en regla');
        $this->assertAceptada($r, $m, 0, 'voice_contacts', 'rest', self::V9_REST_AGENT);
        $this->assertSame(['voice_calls' => 1, 'voice_call_events' => 1], $this->v9VoiceRows());

        // La entrada de las personas, para el mismo contacto, ve esa llamada viva: los mismos hechos, la misma política.
        $this->assertSame(['frequency_exceeded', 'concurrency_exceeded'], $this->porPersona($m));
        $this->assertSame(['voice_calls' => 1, 'voice_call_events' => 1], $this->v9VoiceRows(), 'la negada no creó nada');
        Http::assertNothingSent();
    }

    /**
     * Cada motivo de `OutboundPolicy` que se puede provocar sin una llamada
     * previa, con el resto en regla: la entrada de las estrategias lo da SOLO
     * (C-4: con las llamadas abiertas, basta para negar), en el orden cerrado,
     * y la de las personas da EXACTAMENTE los mismos motivos para el mismo
     * contacto.
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
            'fuera del horario legal' => ['outside_hours', static fn (self $t) => $t->aLaHora('2026-11-17 19:30:00')],
            'festivo' => ['holiday', static fn (self $t) => $t->aLaHora('2026-12-08 10:00:00')],
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
        $this->assertSame([$motivo], $r['body']['reasons'], 'solo el motivo provocado: el resto en regla, y la constante abierta no añade nada');
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
        $this->assertSame([409, ['no_publication']], [$r['http'], $r['body']['reasons']]);
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
        $this->assertSame(['contact_not_authorized', 'no_voice_consent', 'rne_check_missing'], $r['body']['reasons'], 'sin contacto no hay número ni consulta al RNE');

        // Con contacto y sin consentimiento.
        $sinConsentimiento = $this->v9Member(consent: false, phone: '3001239002');
        $this->assertSame(['no_voice_consent'], $this->porEstrategia($sinConsentimiento)['body']['reasons']);
        $this->assertNadaCreado();

        // Con consentimiento comercial: la comercial se acepta (C-4), a ESE contacto; cobranza y servicio no lo tienen (D9-15).
        $conConsentimiento = $this->v9Member(phone: '3001239003');
        $this->liberar($this->assertAceptada($this->porEstrategia($conConsentimiento, 'commercial'), $conConsentimiento, 0, 'voice_contacts', 'rest', self::V9_REST_AGENT));
        foreach (['collections', 'service'] as $finalidad) {
            $this->assertSame(['no_voice_consent'], $this->porEstrategia($conConsentimiento, $finalidad)['body']['reasons'], $finalidad);
        }
        $this->assertSame(['voice_calls' => 1, 'voice_call_events' => 1], $this->v9VoiceRows(), 'solo la comercial creó su fila; las negadas, nada');
        Queue::assertPushed(PlaceOutboundVoiceCall::class, 1);
    }

    public function test_nunca_un_canario_fuera_de_la_parte_canaria_ni_sin_consentimiento(): void
    {
        $this->v9GreenVoice();
        config(['voice.outbound.scope' => 'canary']);
        $m = $this->v9Member();   // su número es también un canario de voz ACTIVO

        // La estrategia en su parte `rest` con el alcance `canary` (4a): el destino es el contacto → la 4a no lo admite.
        $this->assertSame(['not_an_active_voice_canary'], $this->porEstrategia($m, parteCanaria: false)['body']['reasons']);
        $this->assertNadaCreado();
        // En su parte canaria: el canario que es ESE número, y la puerta lo acepta (C-4) con la parte canaria.
        $canario = (string) DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->where('phone_hash', PhoneFingerprint::of($m['e164']))->value('uuid');
        $this->assertNotSame('', $canario, 'el canario de voz de ese número');
        $this->liberar($this->assertAceptada($this->porEstrategia($m, parteCanaria: true), $m, 0, 'canary_registry', 'canary', self::V9_CANARY_AGENT, $canario));
        // El canario de ese número sin consentimiento de voz de la persona: nunca.
        $sin = $this->v9Member(consent: false, phone: '3001239004');
        $this->assertSame(['not_an_active_voice_canary'], $this->porEstrategia($sin, parteCanaria: true)['body']['reasons']);
        // Fuera del alcance `canary`, la parte canaria sigue llamando al contacto (4b), con la parte `rest`.
        config(['voice.outbound.scope' => 'contacts']);
        $this->liberar($this->assertAceptada($this->porEstrategia($m, parteCanaria: true), $m, 1, 'voice_contacts', 'rest', self::V9_REST_AGENT));
        $this->assertSame(['voice_calls' => 2, 'voice_call_events' => 2], $this->v9VoiceRows(), 'solo las dos aceptadas; las negadas, nada');
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
