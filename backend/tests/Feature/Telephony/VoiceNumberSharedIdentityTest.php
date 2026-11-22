<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceContacts;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Voice\VoiceCallPolicy;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * ENMIENDA DEL 22-11-2026, POR DECISIÓN EXPRESA DEL PROPIETARIO (protección 6
 * y D8-5 del contrato H8; texto literal en `MATRIZ_CIERRE_V2.md` §9): el
 * número empresarial (el visible de la mensajería, `WHATSAPP_DISPLAY_PHONE`)
 * como ORIGEN de las salientes, de punta a punta sobre las clases reales: la
 * conciliación (`voice:reconcile --numbers`), la caché, la puerta de salientes
 * por HTTP, el job, la iniciación de entrantes, `GET admin/voice/status` y el
 * doctor. Sin red (`Http::fake`); números de ficción.
 *
 * Aquí todo está en regla para la enmienda (la declaración, el número es el
 * visible, «Outbound Only» declarado, entrantes apagadas y el proveedor dice
 * solo saliente y sin agente); cada negativa quita UNA cosa y comprueba que
 * todo queda EXACTAMENTE como antes: `number_is_messaging` con su freno en la
 * conciliación y `voice_number_is_whatsapp` en la puerta.
 *
 * Las pruebas valen con la constante cerrada (el árbol compartido) y abierta
 * (la copia de la apertura): el motivo `calls_disabled_in_code` se comprueba
 * aparte ({@see puerta()}), está si y solo si la constante está cerrada.
 */
class VoiceNumberSharedIdentityTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const SIM = 'http://127.0.0.1:8096';

    /** Un número de ficción que no es de la mensajería: el importado cuando no es el declarado. */
    private const OTRO = '+576010000097';

    /** Otro número de ficción de la mensajería que NO es el visible (un protegido de la lista). */
    private const VISIBLE_DISTINTO = '+576010000098';

    private const KEY = VoiceNumberGuard::OBSERVED_CACHE_KEY;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 de Bogotá, en UTC: dentro del horario legal de salientes.
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->voiceWorld([
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'xi-centinela-SHARED-0123456789', 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID, 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.region' => 'default',
            // El número de voz ES el visible de la mensajería, y la enmienda en regla.
            'meta.whatsapp_display_phone' => self::VOICE_FROM, 'meta.protected_numbers' => [],
            'voice.number.shared_with_messaging' => true, 'voice.number.inbound_capable' => false, 'voice.inbound.enabled' => false,
            // La puerta abierta salvo lo que pruebe cada caso (la constante, aparte).
            'voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5',
        ]);
    }

    /**
     * El número importado, tal como lo da el proveedor: llama con `$llama` (null = sin el campo), con `$campos`
     * (por defecto «Outbound Only») y con `$agente` asignado (null = sin asignar).
     *
     * @param  array<string, mixed>  $campos
     */
    private function provider(?string $llama = self::VOICE_FROM, array $campos = ['supports_inbound' => false, 'supports_outbound' => true], ?string $agente = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::SIM.'/v1/convai/phone-numbers/'.self::PHONE_NUMBER_ID => Http::response(['phone_number_id' => self::PHONE_NUMBER_ID, 'provider' => 'twilio',
                'assigned_agent' => $agente === null ? null : ['agent_id' => $agente, 'agent_name' => 'x']] + ($llama === null ? [] : ['phone_number' => $llama]) + $campos, 200),
            self::SIM.'/v1/convai/agents/*' => Http::response(['detail' => 'x'], 404),
        ]);
    }

    /** @return array<string, mixed> */
    private function reconcile(bool $seco = false): array
    {
        $codigo = Artisan::call('voice:reconcile', ['--numbers' => true, '--json' => true] + ($seco ? ['--dry-run' => true] : []));

        return ['code' => $codigo] + json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['number'];
    }

    /**
     * Lo que dice la puerta de salientes por HTTP, SIN el motivo de la constante: ese se comprueba aquí (está si y
     * solo si la constante está cerrada). Con la constante abierta y nada más que decir, la puerta acepta: [].
     *
     * @return list<string>
     */
    private function puerta(string $uuid, string $kind = 'canary'): array
    {
        $r = $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => $kind, 'uuid' => $uuid]],
            $this->adminWithPermissions(['telephony.call_place']));
        if ($r->status() === 202) {
            $this->assertTrue(VoiceCallPolicy::CALLS_ENABLED_IN_CODE, 'solo con la constante abierta acepta');

            return [];
        }
        $r->assertStatus(409);
        $motivos = $r->json('reasons');
        $this->assertSame(! VoiceCallPolicy::CALLS_ENABLED_IN_CODE, in_array('calls_disabled_in_code', $motivos, true), 'la constante, por su nombre, solo si está cerrada');

        return array_values(array_diff($motivos, ['calls_disabled_in_code']));
    }

    /** Lo que responde la recomprobación del job justo antes de marcar (DF-G9, pasos 1–5, ya pasada la constante). */
    private function recheckDelJob(VoiceCall $llamada): ?string
    {
        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))
            ->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), VoiceCall::query()->findOrFail($llamada->id));
    }

    /** @return array<string, mixed> el bloque `number` de `GET admin/voice/status` */
    private function estado(): array
    {
        return $this->getJson('/api/admin/voice/status', $this->adminWithPermissions(['telephony.view']))->assertOk()->json('number');
    }

    private function doctor(): string
    {
        Artisan::call('voice:doctor', ['--telefonia' => true]);

        return Artisan::output();
    }

    /**
     * LA POSITIVA. Con las siete en regla, la conciliación da `outbound_only` sin freno, la caché guarda el
     * veredicto con los tres hechos del proveedor (nunca el número) y la puerta ya no dice `voice_number_is_whatsapp`;
     * todo lo demás sigue diciéndose: el canario sin activar, el cupo, el presupuesto, los de los contactos y la constante.
     */
    public function test_con_las_siete_condiciones_la_conciliacion_no_frena_y_la_puerta_sigue_con_todo_lo_demas(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->assertTrue(VoiceNumberGuard::collidesWithConfig(), 'el HECHO: el número de voz es el de la mensajería');

        // Sin conciliar: no se admite (no hay observación) y todo es como hoy.
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observation_unverified'], VoiceNumberGuard::sharedIdentity());
        $this->assertSame(['voice_number_is_whatsapp', 'number_unverified'], $this->puerta($canario));

        $this->provider();
        $r = $this->reconcile();
        $this->assertSame([0, 'outbound_only', 'unassigned', 'unassigned', true, true, ['declared' => true, 'admitted' => true, 'reason' => null], false],
            [$r['code'], $r['result'], $r['observed'], $r['expected'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['sharedWithMessaging'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(0, VoiceBrakeEntry::query()->count());

        // La caché: el veredicto de siempre más los tres hechos del proveedor (ronda 2: el número observado es el visible);
        // la puerta lee la forma de siempre.
        $this->assertSame(['at' => '2026-11-09T15:00:00Z', 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => true,
            'inboundCapable' => false, 'agentAssigned' => false, 'businessNumber' => true], Cache::get(self::KEY));
        $this->assertStringNotContainsString('6010000090', (string) json_encode(Cache::get(self::KEY)), 'la caché no guarda el número');
        $this->assertSame(['at' => '2026-11-09T15:00:00Z', 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => true], VoiceNumberGuard::lastObservation());
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict());
        $this->assertSame(['declared' => true, 'admitted' => true, 'reason' => null], VoiceNumberGuard::sharedIdentity());
        $this->assertTrue(VoiceNumberGuard::collidesWithConfig(), 'el hecho no cambia');
        $this->assertFalse(VoiceNumberGuard::collidesForPolicy(), 'admitida: la política ya no deniega por el número');

        // El estado: `collidesWithMessaging` sigue siendo el HECHO; `sharedWithMessaging`, aditivo, dice que se admite.
        $numero = $this->estado();
        $this->assertSame([true, ['declared' => true, 'admitted' => true, 'reason' => null], 'verified'],
            [$numero['collidesWithMessaging'], $numero['sharedWithMessaging'], $numero['check']]);
        $texto = $this->doctor();
        foreach (['ADMITIDO como origen de las salientes (enmienda del 22-11-2026)', 'identidad compartida admitida, sin freno · 2026-11-09T15:00:00Z',
            'Compartido : ADMITIDO: el número empresarial es el origen de las salientes'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertStringNotContainsString('CHOCA', $texto);
        $this->assertStringNotContainsString('6010000090', $texto.json_encode($numero));
        Artisan::call('voice:reconcile', ['--numbers' => true]);
        $this->assertStringContainsString('número empresarial de la mensajería ADMITIDO como origen', Artisan::output());

        // La puerta sigue diciendo todo lo demás (D8-12): un canario sin activar…
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Registra');
        $sinActivar = (string) $this->registerCanary($ana, '+573000000041', 'voice', 'Sin activar')->assertCreated()->json('data.number.uuid');
        $this->assertSame(['not_an_active_voice_canary'], $this->puerta($sinActivar));
        // …el cupo del día a 0 y el presupuesto a 0…
        config(['voice.twilio.daily_call_cap' => 0]);
        $this->assertSame(['daily_cap_zero'], $this->puerta($canario));
        config(['voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '0']);
        $this->assertSame(['budget_exhausted'], $this->puerta($canario));
        config(['voice.limits.daily_budget_usd' => '5']);
        // …los de los contactos reales (4b): sin consentimiento ni consulta al RNE…
        config(['voice.outbound.scope' => 'contacts', 'voice.contacts.enabled' => true]);
        $this->publishVoice('agent_h8fx_rest_0001', 'rest');
        $contacto = (string) app(VoiceContacts::class)->register($ana, ['clientRequestId' => (string) Str::uuid(), 'number' => '+573000000071',
            'sourceType' => 'manual', 'sourceId' => null])['contact']['uuid'];
        $this->assertSame(['no_voice_consent', 'rne_check_missing'], $this->puerta($contacto, 'contact'));
        config(['voice.outbound.scope' => 'canary']);
        $this->assertSame(0, VoiceCall::query()->count(), 'nada de lo denegado deja fila');

        // Al final, con todo en regla: solo la constante (o, abierta, la acepta).
        $this->assertSame([], $this->puerta($canario));
        $this->assertSame(VoiceCallPolicy::CALLS_ENABLED_IN_CODE ? 1 : 0, VoiceCall::query()->count());
    }

    /**
     * El job sin cambio (TOCTOU): admitida en la puerta, si la siguiente conciliación ve otra cosa (un agente en el
     * número), echa el freno y el job cancela con `voice_braked`; si la observación caduca, `number_unverified`.
     */
    public function test_si_la_observacion_cambia_o_caduca_entre_la_puerta_y_el_marcado_el_job_no_marca(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        $this->provider();
        $this->assertSame('outbound_only', $this->reconcile()['result']);
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => self::CANARY_PHONE]);
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
        $this->assertNull($this->recheckDelJob($llamada), 'admitida y todo en regla: el job pasaría');

        // Caduca: 2 h después, sin conciliar.
        $this->travel(121)->minutes();
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada));
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observation_unverified'], VoiceNumberGuard::sharedIdentity());

        // Una conciliación nueva ve un agente en el número: freno, y el job cancela por él.
        $this->provider(agente: self::SEALED_AGENT);
        $r = $this->reconcile();
        $this->assertSame([1, 'number_is_messaging', ['declared' => true, 'admitted' => false, 'reason' => 'observed_agent'], true],
            [$r['code'], $r['result'], $r['sharedWithMessaging'], $r['brakeOpened']]);
        $this->assertSame('voice_braked', $this->recheckDelJob($llamada));
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
    }

    /**
     * LAS NEGATIVAS: cada una quita UNA condición y deja todo EXACTAMENTE como antes de la enmienda: la conciliación
     * frena con `number_is_messaging` y la puerta dice `voice_number_is_whatsapp` (y `voice_braked`). La identidad real
     * (`sharedIdentity()`, de la caché) da el mismo motivo que la conciliación.
     *
     * @return array<string, array{0: string, 1: Closure}>
     */
    public static function negativas(): array
    {
        return [
            'declaración apagada' => ['not_declared', static fn (self $t) => config(['voice.number.shared_with_messaging' => false])],
            'otro protegido que no es el visible' => ['not_business_number', static fn (self $t) => config(['meta.whatsapp_display_phone' => self::VISIBLE_DISTINTO,
                'meta.protected_numbers' => [self::VISIBLE_DISTINTO, self::VOICE_FROM]])],
            'INBOUND_CAPABLE sin declarar' => ['inbound_capable_declared', static fn (self $t) => config(['voice.number.inbound_capable' => null])],
            'INBOUND_CAPABLE true' => ['inbound_capable_declared', static fn (self $t) => config(['voice.number.inbound_capable' => true])],
            'entrantes encendidas' => ['inbound_enabled', static fn (self $t) => config(['voice.inbound.enabled' => true])],
            'supports_inbound true' => ['observed_inbound', static fn (self $t) => $t->provider(campos: ['supports_inbound' => true])],
            'supports_inbound ausente' => ['observed_inbound', static fn (self $t) => $t->provider(campos: [])],
            'supports_inbound null' => ['observed_inbound', static fn (self $t) => $t->provider(campos: ['supports_inbound' => null])],
            'un agente ajeno en el número' => ['observed_agent', static fn (self $t) => $t->provider(agente: 'agent_fuera_de_laravel_07')],
            'el agente publicado en el número' => ['observed_agent', static fn (self $t) => $t->provider(agente: self::SEALED_AGENT)],
        ];
    }

    #[DataProvider('negativas')]
    public function test_sin_una_de_las_siete_todo_queda_como_hoy(string $motivo, Closure $quitar): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->provider();
        $quitar($this);

        $r = $this->reconcile();
        $declarada = config('voice.number.shared_with_messaging') === true;
        $this->assertSame([1, 'number_is_messaging', true, ['declared' => $declarada, 'admitted' => false, 'reason' => $motivo], true],
            [$r['code'], $r['result'], $r['collidesWithMessaging'], $r['sharedWithMessaging'], $r['brakeOpened']]);
        $freno = VoiceBrakeEntry::query()->where('kind', 'open')->sole();
        $this->assertSame(['agent_drift', 'number:'.self::PHONE_NUMBER_ID.':messaging', 'number_is_messaging'], [$freno->reason, $freno->open_key, $freno->evidence['trigger']]);
        $this->assertStringNotContainsString('6010000090', (string) json_encode($freno->evidence));

        $this->assertSame(['declared' => $declarada, 'admitted' => false, 'reason' => $motivo], VoiceNumberGuard::sharedIdentity(), 'la identidad real dice lo mismo');
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
        $this->assertSame(['voice_number_is_whatsapp', 'voice_braked'], $this->puerta($canario));
        $this->assertSame(0, VoiceCall::query()->count());

        $this->assertSame(['declared' => $declarada, 'admitted' => false, 'reason' => $motivo], $this->estado()['sharedWithMessaging']);
        $texto = $this->doctor();
        $this->assertStringContainsString('CHOCA con un número protegido de la mensajería del motor', $texto);
        $this->assertStringContainsString('ES UN NÚMERO PROTEGIDO DE LA MENSAJERÍA (freno)', $texto);
        $this->assertStringContainsString($declarada ? 'Compartido : declarado y NO admitido ('.$motivo.': ' : 'Compartido : no declarado', $texto);
    }

    /** Una observación de OTRO número (el importado no es `TWILIO_VOICE_FROM`): además `number_mismatch`, como hoy. */
    public function test_una_observacion_de_otro_numero_es_number_mismatch_como_hoy(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->provider(self::OTRO);

        $r = $this->reconcile();
        // Ronda 2: la conciliación y la identidad real miran la condición 2 sobre TWILIO_VOICE_FROM (que es el visible) y
        // llegan las dos a la 5: la observación no cuadra.
        $this->assertSame([1, 'number_mismatch', false, false, ['declared' => true, 'admitted' => false, 'reason' => 'observation_unverified'], true],
            [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['sharedWithMessaging'], $r['brakeOpened']]);
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observation_unverified'], VoiceNumberGuard::sharedIdentity());
        $this->assertSame('number:'.self::PHONE_NUMBER_ID.':mismatch', VoiceBrakeEntry::query()->where('kind', 'open')->sole()->open_key);
        $this->assertSame(['voice_number_is_whatsapp', 'number_mismatch', 'voice_braked'], $this->puerta($canario), 'la configuración sigue chocando');

        // Y el importado es el visible, pero TWILIO_VOICE_FROM dice otro: el choque observado manda, como hoy.
        config(['voice.twilio.from' => self::OTRO]);
        $this->provider(self::VOICE_FROM);
        $r = $this->reconcile();
        $this->assertSame(['number_is_messaging', false, true, 'not_business_number'], [$r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['sharedWithMessaging']['reason']]);
        $this->assertSame('not_business_number', VoiceNumberGuard::sharedIdentity()['reason']);
        $this->assertFalse(VoiceNumberGuard::collidesWithConfig());
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy(), 'el importado de la mensajería cuenta aunque TWILIO_VOICE_FROM diga otro');
    }

    /** Una observación caducada, de otro id o inexistente: `number_unverified` en la puerta y en el job, y el choque, como hoy. */
    public function test_sin_una_observacion_verificada_es_number_unverified_en_la_puerta_y_en_el_job(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => self::CANARY_PHONE]);
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
        // La fila pedida al mismo canario cuenta para la frecuencia y la concurrencia (1 a la vez): la puerta las nombra, aparte del número.
        $hoy = ['voice_number_is_whatsapp', 'number_unverified', 'frequency_exceeded', 'concurrency_exceeded'];

        // Inexistente.
        $this->assertNull(VoiceNumberGuard::lastObservation());
        $this->assertSame('observation_unverified', VoiceNumberGuard::sharedIdentity()['reason']);
        $this->assertSame($hoy, $this->puerta($canario));
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada));

        // Caducada: la que habría admitido, de hace 2 h.
        $this->provider();
        $this->assertSame('outbound_only', $this->reconcile()['result']);
        $this->assertTrue(VoiceNumberGuard::sharedIdentity()['admitted']);
        $this->travel(120)->minutes();
        $this->assertSame('observation_unverified', VoiceNumberGuard::sharedIdentity()['reason']);
        $this->assertSame($hoy, $this->puerta($canario));
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada));

        // De otro id: la conciliación vio el número importado de antes; el mando cambió ELEVENLABS_AGENT_PHONE_NUMBER_ID.
        $this->travel(-120)->minutes();
        $this->assertTrue(VoiceNumberGuard::sharedIdentity()['admitted']);
        config(['voice.elevenlabs.agent_phone_number_id' => 'phnum_nunca_observado_9999']);
        $this->assertSame('observation_unverified', VoiceNumberGuard::sharedIdentity()['reason']);
        $this->assertSame($hoy, $this->puerta($canario));
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada));
    }

    /**
     * RONDA 2 (decisión del lead): el escenario exacto del hallazgo. La conciliación vio el importado cuando
     * `TWILIO_VOICE_FROM` era OTRO número (un dedicado): cuadraba. El mando cambia `TWILIO_VOICE_FROM` al visible
     * (`config:cache`) y NO vuelve a conciliar: la observación, de menos de 2 h y del id configurado, sigue diciendo
     * «cuadra», pero era de otro número. Antes de la ronda 2 se admitía; ahora no (`observed_not_business_number`) y
     * la puerta dice `voice_number_is_whatsapp`, como antes de la enmienda, hasta que una conciliación vea el visible.
     */
    public function test_cambiar_el_origen_al_visible_sin_conciliar_no_admite_hasta_la_siguiente_observacion(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        config(['voice.twilio.from' => self::OTRO]);
        $this->provider(self::OTRO);
        $r = $this->reconcile();
        $this->assertSame([0, 'outbound_only', true, false, ['declared' => true, 'admitted' => false, 'reason' => 'not_business_number']],
            [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['sharedWithMessaging']]);
        $this->assertFalse((bool) Cache::get(self::KEY)['businessNumber'], 'la observación guarda que lo visto NO es el visible');

        // El cambio sin conciliar: la vigilancia del número sigue «verificada» (la de siempre, B8-h), la identidad no.
        config(['voice.twilio.from' => self::VOICE_FROM]);
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict());
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observed_not_business_number'], VoiceNumberGuard::sharedIdentity());
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
        $this->assertSame(['voice_number_is_whatsapp'], $this->puerta($canario), 'como antes de la enmienda');
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observed_not_business_number'], $this->estado()['sharedWithMessaging']);
        $this->assertStringContainsString('Compartido : declarado y NO admitido (observed_not_business_number: el número que vio la conciliación no es el visible', $this->doctor());

        // La siguiente conciliación ve el visible (reimportado): admitida, y la puerta ya no lo deniega por el número.
        $this->provider(self::VOICE_FROM);
        $r = $this->reconcile();
        $this->assertSame([0, 'outbound_only', ['declared' => true, 'admitted' => true, 'reason' => null]], [$r['code'], $r['result'], $r['sharedWithMessaging']]);
        $this->assertTrue(Cache::get(self::KEY)['businessNumber']);
        $this->assertFalse(VoiceNumberGuard::collidesForPolicy());
        $this->assertSame([], $this->puerta($canario));
    }

    /**
     * Ronda 2, la variante con OTRO PROTEGIDO de la mensajería que no es el visible: la conciliación ya frenaba
     * (`number_is_messaging`, `not_business_number`); al cambiar `TWILIO_VOICE_FROM` al visible sin conciliar, la
     * identidad sigue sin admitirse (antes de la ronda 2 se admitía: un protegido que no es el visible habría sido de voz).
     */
    public function test_cambiar_el_origen_al_visible_tras_observar_otro_protegido_no_admite(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        config(['meta.protected_numbers' => [self::VISIBLE_DISTINTO], 'voice.twilio.from' => self::VISIBLE_DISTINTO]);
        $this->provider(self::VISIBLE_DISTINTO);
        $r = $this->reconcile();
        $this->assertSame([1, 'number_is_messaging', true, 'not_business_number', true],
            [$r['code'], $r['result'], $r['matchesFrom'], $r['sharedWithMessaging']['reason'], $r['brakeOpened']]);

        config(['voice.twilio.from' => self::VOICE_FROM]);
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observed_not_business_number'], VoiceNumberGuard::sharedIdentity());
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
        $this->assertSame(['voice_number_is_whatsapp', 'voice_braked'], $this->puerta($canario));

        $this->provider(self::VOICE_FROM);
        $this->assertSame(['outbound_only', true], [($r = $this->reconcile())['result'], $r['sharedWithMessaging']['admitted']]);
        $this->assertFalse(VoiceNumberGuard::collidesForPolicy(), 'admitida con la observación del visible; el freno de antes sigue echado');
        $this->assertSame(['voice_braked'], $this->puerta($canario));
    }

    /** Una caché de antes del despliegue (sin los hechos del proveedor) sigue valiendo para la puerta, pero NO admite. */
    public function test_una_cache_de_antes_de_la_enmienda_no_admite(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $vieja = ['at' => '2026-11-09T14:30:00Z', 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => true];
        Cache::put(self::KEY, $vieja, 3600);

        $this->assertSame($vieja, VoiceNumberGuard::lastObservation(), 'la observación se sigue leyendo');
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict(), 'la vigilancia del número no cambia');
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observed_not_business_number'], VoiceNumberGuard::sharedIdentity());
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
        $this->assertSame(['voice_number_is_whatsapp'], $this->puerta($canario));

        // Sin alguno de los tres, o con una forma que no es un booleano, tampoco. La caché de la primera ronda (los dos
        // hechos, sin `businessNumber`) tampoco admite: hace falta una conciliación con el código nuevo.
        $en = ['inboundCapable' => false, 'agentAssigned' => false, 'businessNumber' => true];
        foreach ([['inboundCapable' => false], ['agentAssigned' => false], ['inboundCapable' => false, 'agentAssigned' => false],
            ['businessNumber' => 'true'] + $en, ['inboundCapable' => 'false'] + $en, ['agentAssigned' => 0] + $en, ['businessNumber' => false] + $en,
            ['businessNumber' => null] + $en] as $hechos) {
            Cache::put(self::KEY, $vieja + $hechos, 3600);
            $this->assertFalse(VoiceNumberGuard::sharedIdentity()['admitted'], (string) json_encode($hechos));
            $this->assertTrue(VoiceNumberGuard::collidesForPolicy(), (string) json_encode($hechos));
        }
        // Los tres, booleanos y en regla: se admite (lo escribe así la conciliación).
        Cache::put(self::KEY, $vieja + $en, 3600);
        $this->assertTrue(VoiceNumberGuard::sharedIdentity()['admitted']);
        $this->assertSame([], $this->puerta($canario));
    }

    /** Quien guarda una observación con los tres argumentos de siempre (las pruebas de antes) falla cerrado. */
    public function test_los_valores_por_defecto_de_la_observacion_fallan_cerrado(): void
    {
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, true);
        $this->assertSame(['inboundCapable' => null, 'agentAssigned' => null, 'businessNumber' => null],
            array_intersect_key(Cache::get(self::KEY), ['inboundCapable' => 1, 'agentAssigned' => 1, 'businessNumber' => 1]));
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'observed_not_business_number'], VoiceNumberGuard::sharedIdentity());
        // Los cinco argumentos de la primera ronda (sin el número observado): tampoco.
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, true, false, false);
        $this->assertSame('observed_not_business_number', VoiceNumberGuard::sharedIdentity()['reason']);
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, true, null, null, true);
        $this->assertSame('observed_inbound', VoiceNumberGuard::sharedIdentity()['reason']);
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, true, false, null, true);
        $this->assertSame('observed_agent', VoiceNumberGuard::sharedIdentity()['reason']);
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, true, false, false, true);
        $this->assertTrue(VoiceNumberGuard::sharedIdentity()['admitted']);
    }

    /** En seco (E10-9) no se escribe la caché: ni con la identidad admitida ni sin ella. */
    public function test_en_seco_no_escribe_la_cache(): void
    {
        $this->publishVoice();
        $this->provider();
        $seco = $this->reconcile(seco: true);
        $this->assertSame([0, 'outbound_only', ['declared' => true, 'admitted' => true, 'reason' => null], false],
            [$seco['code'], $seco['result'], $seco['sharedWithMessaging'], $seco['wouldOpenBrake']]);
        $this->assertNull(Cache::get(self::KEY), 'en seco no se escribe la caché');
        $this->assertSame('observation_unverified', VoiceNumberGuard::sharedIdentity()['reason'], 'y sin caché no se admite');

        $this->provider(campos: ['supports_inbound' => true]);
        $seco = $this->reconcile(seco: true);
        $this->assertSame([1, 'number_is_messaging', 'observed_inbound', true, false],
            [$seco['code'], $seco['result'], $seco['sharedWithMessaging']['reason'], $seco['wouldOpenBrake'], $seco['brakeOpened']]);
        $this->assertNull(Cache::get(self::KEY));
        $this->assertFalse(VoiceBrake::isOpen());
    }

    /**
     * La iniciación de entrantes no cambia: con la declaración y el número «Outbound Only», `number_outbound_only` es el
     * primer motivo (el de `policy_reason`), con las entrantes apagadas (la condición 4) y aun encendidas.
     */
    public function test_la_iniciacion_sigue_diciendo_number_outbound_only_el_primero(): void
    {
        $this->activeVoiceCanary();
        $this->publishVoice();
        foreach (['apagadas' => false, 'encendidas' => true] as $n => $entrantes) {
            config(['voice.inbound.enabled' => $entrantes]);
            $sid = 'CA000000000000000000000000a80000'.($entrantes ? '62' : '61');
            $cuerpo = (string) json_encode(['caller_id' => self::CANARY_PHONE, 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM,
                'call_sid' => $sid, 'conversation_id' => 'conv_h8fx_shared_'.$n]);
            $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', $cuerpo, $this->initiationHeaders())->assertOk()
                ->assertJsonPath('dynamic_variables.caller_authorized', 'no');
            $fila = VoiceCall::query()->where('provider_call_sid', $sid)->sole();
            $this->assertSame(['denied', false, 'number_outbound_only'], [$fila->status, $fila->caller_authorized, $fila->policy_reason], $n);
            $this->assertSame('number_outbound_only', VoiceCallEvent::query()->where('provider_event_key', $sid)->sole()->codes['reasons'][0], $n);
        }
    }

    /** Sin la enmienda declarada (el defecto), el estado lo dice y nada cambia respecto de antes. */
    public function test_por_defecto_no_esta_declarada_y_el_estado_lo_dice(): void
    {
        config(['voice.number.shared_with_messaging' => false, 'meta.whatsapp_display_phone' => null]);
        $this->assertSame(['declared' => false, 'admitted' => false, 'reason' => 'not_declared'], VoiceNumberGuard::sharedIdentity());
        $numero = $this->estado();
        $this->assertSame(['configured', 'collidesWithMessaging', 'importedNumberIdConfigured', 'inboundCapable', 'observed', 'check', 'checkReason', 'sharedWithMessaging'], array_keys($numero));
        $this->assertSame([false, ['declared' => false, 'admitted' => false, 'reason' => 'not_declared']], [$numero['collidesWithMessaging'], $numero['sharedWithMessaging']]);
        $this->assertStringContainsString('Compartido : no declarado (VOICE_NUMBER_SHARED_WITH_MESSAGING)', $this->doctor());

        // Declarada sin que el número sea el visible (un número dedicado): no se admite, y no hay choque que apagar.
        config(['voice.number.shared_with_messaging' => true]);
        $this->assertSame(['declared' => true, 'admitted' => false, 'reason' => 'not_business_number'], VoiceNumberGuard::sharedIdentity());
        $this->assertFalse(VoiceNumberGuard::collidesForPolicy());
    }
}
