<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * SEC8-c B-1 (B8-g): LA IDENTIDAD DEL NÚMERO QUE LLAMA. La guarda del número de
 * la mensajería del motor (D8-5, protección 6) comparaba solo la configuración
 * (`TWILIO_VOICE_FROM`), pero la llamada sale con el número IMPORTADO en
 * ElevenLabs (el del `agent_phone_number_id`). Ahora `voice:reconcile
 * --numbers` lee también el `phone_number` del número importado (solo en
 * memoria) y:
 *  - si no es `TWILIO_VOICE_FROM`: `number_mismatch` y freno;
 *  - si es uno de los protegidos de la mensajería: `number_is_messaging` y
 *    freno, y la puerta de salientes lo deniega por su nombre
 *    (`voice_number_is_whatsapp`) aunque `TWILIO_VOICE_FROM` diga otro;
 *  - deja su VEREDICTO en la caché (nunca el número), que leen la puerta y el doctor.
 * Sin red (`Http::fake`).
 */
class VoiceNumberIdentityTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const SIM = 'http://127.0.0.1:8096';

    /** Otro número de ficción: el que de verdad está importado en ElevenLabs cuando no es el declarado. */
    private const OTRO = '+576010000097';

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 de Bogotá, en UTC (Carbon::setTestNow): dentro del horario legal de salientes.
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->voiceWorld([
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'xi-centinela-IDENT-0123456789', 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID, 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.region' => 'default',
            'meta.whatsapp_display_phone' => null, 'meta.protected_numbers' => [],
        ]);
    }

    /** El número importado llama con `$llama` (null = la respuesta no lo trae) y apunta al agente publicado. */
    private function provider(?string $llama): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::SIM.'/v1/convai/phone-numbers/'.self::PHONE_NUMBER_ID => Http::response(['phone_number_id' => self::PHONE_NUMBER_ID, 'provider' => 'twilio',
                'assigned_agent' => ['agent_id' => self::SEALED_AGENT, 'agent_name' => 'x']] + ($llama === null ? [] : ['phone_number' => $llama]), 200),
            self::SIM.'/v1/convai/agents/*' => Http::response(['detail' => 'x'], 404),
        ]);
    }

    /** @return array<string, mixed> */
    private function reconcile(bool $seco = false): array
    {
        $codigo = Artisan::call('voice:reconcile', ['--numbers' => true, '--json' => true] + ($seco ? ['--dry-run' => true] : []));

        return ['code' => $codigo] + json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['number'];
    }

    public function test_el_numero_importado_que_es_el_declarado_no_frena_y_deja_su_veredicto_sin_el_numero(): void
    {
        $this->publishVoice();
        $this->provider(self::VOICE_FROM);
        $r = $this->reconcile();
        $this->assertSame([0, 'ok', true, false, false], [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['brakeOpened']]);
        $o = VoiceNumberGuard::lastObservation();
        $this->assertSame(['at' => '2026-11-09T15:00:00Z', 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => false], $o);
        $this->assertStringNotContainsString('6010000090', (string) json_encode(Cache::get(VoiceNumberGuard::OBSERVED_CACHE_KEY)), 'la caché no guarda el número');
        $this->assertFalse(VoiceBrake::isOpen());

        Artisan::call('voice:doctor', ['--telefonia' => true]);
        $texto = Artisan::output();
        $this->assertStringContainsString('Importado  : es el de TWILIO_VOICE_FROM y no es de la mensajería · 2026-11-09T15:00:00Z', $texto);
        $this->assertStringNotContainsString('6010000090', $texto);
    }

    public function test_un_numero_importado_que_no_es_el_declarado_frena(): void
    {
        $this->publishVoice();
        $this->provider(self::OTRO);

        $seco = $this->reconcile(seco: true);
        $this->assertSame([1, 'number_mismatch', false, true], [$seco['code'], $seco['result'], $seco['matchesFrom'], $seco['wouldOpenBrake']]);
        $this->assertNull(VoiceNumberGuard::lastObservation(), 'en seco no se escribe la caché');
        $this->assertFalse(VoiceBrake::isOpen());

        $r = $this->reconcile();
        $this->assertSame([1, 'number_mismatch', false, false, true], [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['brakeOpened']]);
        $freno = VoiceBrakeEntry::query()->where('kind', 'open')->sole();
        $this->assertSame(['agent_drift', 'number:'.self::PHONE_NUMBER_ID.':mismatch', ['trigger' => 'number_mismatch', 'where' => 'reconcile', 'part' => 'canary', 'seq' => 1]],
            [$freno->reason, $freno->open_key, $freno->evidence]);
        $this->assertStringNotContainsString('6010000097', (string) json_encode($freno->evidence));
        Artisan::call('voice:doctor', ['--telefonia' => true]);
        $this->assertStringContainsString('Importado  : NO es el de TWILIO_VOICE_FROM (freno)', Artisan::output());
    }

    /**
     * La regresión de B-1: el número importado es el de la mensajería del
     * motor y `TWILIO_VOICE_FROM` dice otro (limpio). Antes nadie lo veía: la
     * política solo comparaba la configuración.
     */
    public function test_el_numero_importado_de_la_mensajeria_frena_y_la_puerta_lo_deniega_por_su_nombre(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        config(['meta.whatsapp_display_phone' => self::OTRO, 'voice.outbound.enabled' => true, 'voice.twilio.enabled' => true,
            'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5']);
        $this->assertFalse(VoiceNumberGuard::collidesWithConfig(), 'la configuración está limpia');
        $this->provider(self::OTRO);

        $r = $this->reconcile();
        $this->assertSame([1, 'number_is_messaging', false, true, true], [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging'], $r['brakeOpened']]);
        $this->assertSame(['agent_drift', 'number:'.self::PHONE_NUMBER_ID.':messaging', 'number_is_messaging'],
            [($freno = VoiceBrakeEntry::query()->where('kind', 'open')->sole())->reason, $freno->open_key, $freno->evidence['trigger']]);
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());

        $motivos = $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]],
            $this->adminWithPermissions(['telephony.call_place']))->assertStatus(409)->json('reasons');
        $this->assertContains('voice_number_is_whatsapp', $motivos, 'la puerta lo dice por su nombre, no solo por el freno');
        $this->assertContains('voice_braked', $motivos);

        // También con un número de la lista de protegidos.
        config(['meta.whatsapp_display_phone' => null, 'meta.protected_numbers' => ['+576010000098', self::OTRO]]);
        Cache::forget(VoiceNumberGuard::OBSERVED_CACHE_KEY);
        $this->assertFalse(VoiceNumberGuard::collidesForPolicy(), 'sin observación, solo la configuración');
        $this->assertSame('number_is_messaging', $this->reconcile()['result']);
        $this->assertTrue(VoiceNumberGuard::collidesForPolicy());
    }

    /** @return list<string> los motivos de la puerta para un canario de voz activo, con las banderas y el presupuesto abiertos */
    private function motivosDeLaPuerta(string $canario): array
    {
        config(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5']);

        return $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]],
            $this->adminWithPermissions(['telephony.call_place']))->assertStatus(409)->json('reasons');
    }

    /**
     * Decisión del lead (B8-g): la puerta de salientes FALLA CERRADA sin una
     * conciliación del número de menos de 2 h que cuadre (`number_unverified`),
     * como `elevenlabs_unverified` sin la marca de E4-6: ninguna saliente sin
     * vigilar el número que de verdad llama. Con la verificación de ElevenLabs
     * escrita, lo único que queda con una fresca que cuadra es la constante.
     */
    public function test_la_puerta_falla_cerrada_sin_una_conciliacion_reciente_del_numero(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());

        // Sin conciliación del número: cerrado.
        $this->assertSame(['calls_disabled_in_code', 'number_unverified'], $this->motivosDeLaPuerta($canario));

        // Una fresca que cuadra: pasa (solo queda la constante del código).
        $this->provider(self::VOICE_FROM);
        $this->assertSame('ok', $this->reconcile()['result']);
        $this->assertSame('verified', VoiceNumberGuard::policyCheck());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));

        // A 1 h 59 min sigue valiendo; a 2 h 1 min ya no.
        $this->travelTo(Carbon::parse('2026-11-09 16:59:00', 'UTC'));
        VoiceProviderState::recordStudioVerification(true, now());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));
        $this->travelTo(Carbon::parse('2026-11-09 17:01:00', 'UTC'));
        $this->assertSame(['calls_disabled_in_code', 'number_unverified'], $this->motivosDeLaPuerta($canario));

        // Una fresca que NO cuadra: el importado no es TWILIO_VOICE_FROM → `number_mismatch` (y el freno de la conciliación).
        $this->provider(self::OTRO);
        $this->assertSame('number_mismatch', $this->reconcile()['result']);
        $this->assertSame(['calls_disabled_in_code', 'number_mismatch', 'voice_braked'], $this->motivosDeLaPuerta($canario));
    }

    /** Decisión del lead: una fresca en la que el importado es de la mensajería → `voice_number_is_whatsapp` por su nombre. */
    public function test_una_conciliacion_fresca_con_el_numero_de_la_mensajeria_lo_deniega_por_su_nombre(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        config(['meta.whatsapp_display_phone' => self::VOICE_FROM]);   // el declarado choca: el importado ES el de la mensajería
        $this->provider(self::VOICE_FROM);
        $this->assertSame('number_is_messaging', $this->reconcile()['result']);
        $this->assertSame(['calls_disabled_in_code', 'voice_number_is_whatsapp', 'voice_braked'], $this->motivosDeLaPuerta($canario));
    }

    /** Lo que responde la recomprobación del job justo antes de marcar (DF-G9, pasos 1–5; la constante cerrada no deja pasar de ella). */
    private function recheckDelJob(VoiceCall $llamada): ?string
    {
        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))
            ->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), VoiceCall::query()->findOrFail($llamada->id));
    }

    /**
     * B8-h (REV8-c M-1): la observación solo vigila el número que llama HOY. La
     * conciliación guarda el `phone_number_id` que observó; el job marca con el
     * de la configuración. Si el mando cambia `ELEVENLABS_AGENT_PHONE_NUMBER_ID`
     * (solo `config:cache`: la caché sigue con la observación de antes), esa
     * observación ya no vale: la puerta real y el job dicen `number_unverified`,
     * y el estado y el doctor, el motivo. Con el mismo id, pasa. Es la
     * reproducción del revisor (`RevStaleNumberObservationTest`), que antes
     * daba `verified` y solo `calls_disabled_in_code`.
     */
    public function test_una_observacion_de_otro_id_de_numero_no_vigila_el_que_llama(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->provider(self::VOICE_FROM);
        $this->assertSame('ok', $this->reconcile()['result']);
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));

        // El mando cambia el id (reimportación, o un id equivocado): la caché guarda la observación del anterior.
        config(['voice.elevenlabs.agent_phone_number_id' => 'phnum_nunca_observado_9999']);
        $this->assertSame(['check' => 'unverified', 'reason' => 'other_number_id'], VoiceNumberGuard::policyVerdict());
        $this->assertSame(['calls_disabled_in_code', 'number_unverified'], $this->motivosDeLaPuerta($canario), 'la puerta real');
        $numero = $this->getJson('/api/admin/voice/status', $this->adminWithPermissions(['telephony.view']))->assertOk()->json('number');
        // REV8-c (segunda mirada) H-5: el estado no expone el identificador del proveedor; dice si la observación es del número configurado.
        $this->assertSame(['unverified', 'other_number_id', false], [$numero['check'], $numero['checkReason'], $numero['observed']['matchesConfigured']]);
        $this->assertArrayNotHasKey('phoneNumberId', $numero['observed']);
        Artisan::call('voice:doctor', ['--telefonia' => true]);
        $this->assertStringContainsString('la puerta dice number_unverified (la conciliación es de otro ELEVENLABS_AGENT_PHONE_NUMBER_ID que el configurado', Artisan::output());

        // Comparación exacta: otra grafía del mismo id no es el mismo id; sin id configurado, nada vigila.
        foreach ([strtoupper(self::PHONE_NUMBER_ID), self::PHONE_NUMBER_ID.'0', '', '   ', null] as $otro) {
            config(['voice.elevenlabs.agent_phone_number_id' => $otro]);
            $this->assertSame(['check' => 'unverified', 'reason' => 'other_number_id'], VoiceNumberGuard::policyVerdict(), var_export($otro, true));
        }

        // El mismo id (los espacios a los lados no cuentan, como en la conciliación): vigilado otra vez; la puerta pasa.
        config(['voice.elevenlabs.agent_phone_number_id' => ' '.self::PHONE_NUMBER_ID.' ']);
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict());
        config(['voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID]);
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));

        // El job, con una saliente ya pedida (después de la puerta: la fila cuenta para la frecuencia y la concurrencia).
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => self::CANARY_PHONE]);
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
        $this->assertNull($this->recheckDelJob($llamada), 'con la observación del número configurado, el job pasa');
        config(['voice.elevenlabs.agent_phone_number_id' => 'phnum_nunca_observado_9999']);
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada), 'con otro id, el job cancela antes del freno y de la deriva');
        config(['voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID]);
        $this->assertNull($this->recheckDelJob($llamada));

        // Una observación sin id (escrita fuera de la conciliación) no vigila nada.
        Cache::put(VoiceNumberGuard::OBSERVED_CACHE_KEY, ['at' => '2026-11-09T15:00:00Z', 'phoneNumberId' => '', 'matchesFrom' => true, 'collides' => false], 3600);
        $this->assertSame(['check' => 'unverified', 'reason' => 'other_number_id'], VoiceNumberGuard::policyVerdict());
        $this->assertSame('number_unverified', $this->recheckDelJob($llamada));
    }

    /** B8-h: cada motivo de `policyVerdict()`, en su orden; el id se mira antes que la edad (de otro número, la edad no importa). */
    public function test_el_motivo_de_la_vigilancia_del_numero_es_un_conjunto_cerrado(): void
    {
        $this->assertSame(['no_observation', 'unreadable', 'other_number_id', 'future', 'stale', 'uncompared', 'not_voice_from'], VoiceNumberGuard::CHECK_REASONS);
        $poner = static fn (string $at, string $id, ?bool $cuadra): bool => Cache::put(VoiceNumberGuard::OBSERVED_CACHE_KEY,
            ['at' => $at, 'phoneNumberId' => $id, 'matchesFrom' => $cuadra, 'collides' => false], 3600);
        $casos = [
            'no_observation' => static fn () => Cache::forget(VoiceNumberGuard::OBSERVED_CACHE_KEY),
            'unreadable' => static fn () => $poner('2026-13-40T99:00:00Z', self::PHONE_NUMBER_ID, true),
            'other_number_id' => static fn () => $poner('2026-11-09T12:00:00Z', 'phnum_otro_0002', true),
            'future' => static fn () => $poner('2026-11-09T15:06:00Z', self::PHONE_NUMBER_ID, true),
            'stale' => static fn () => $poner('2026-11-09T13:00:00Z', self::PHONE_NUMBER_ID, true),
            'uncompared' => static fn () => $poner('2026-11-09T14:00:00Z', self::PHONE_NUMBER_ID, null),
            'not_voice_from' => static fn () => $poner('2026-11-09T14:00:00Z', self::PHONE_NUMBER_ID, false),
        ];
        foreach ($casos as $motivo => $preparar) {
            $preparar();
            $this->assertSame(['check' => $motivo === 'not_voice_from' ? 'mismatch' : 'unverified', 'reason' => $motivo], VoiceNumberGuard::policyVerdict(), $motivo);
            $this->assertSame($motivo === 'not_voice_from' ? 'mismatch' : 'unverified', VoiceNumberGuard::policyCheck(), $motivo);
        }
        $poner('2026-11-09T14:00:00Z', self::PHONE_NUMBER_ID, true);
        $this->assertSame(['check' => 'verified', 'reason' => null], VoiceNumberGuard::policyVerdict());
    }

    /** `policyCheck()` falla cerrado ante una observación que no es suya: sin comparar, ilegible o del futuro. */
    public function test_la_vigilancia_del_numero_falla_cerrada_ante_una_observacion_rara(): void
    {
        $this->assertSame('unverified', VoiceNumberGuard::policyCheck(), 'sin observación');
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, null, false);
        $this->assertSame('unverified', VoiceNumberGuard::policyCheck(), 'fresca pero sin poder comparar');
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        $this->assertSame('verified', VoiceNumberGuard::policyCheck());
        foreach (['xxxxxxxxxxxxxxxxxxxx', '2026-13-40T99:00:00Z', '2026-11-09 15:00:00', '2026-11-09T15:06:00Z'] as $at) {
            Cache::put(VoiceNumberGuard::OBSERVED_CACHE_KEY, ['at' => $at, 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => false], 3600);
            $this->assertSame('unverified', VoiceNumberGuard::policyCheck(), $at);
        }
        Cache::put(VoiceNumberGuard::OBSERVED_CACHE_KEY, ['at' => '2026-11-09T15:04:00Z', 'phoneNumberId' => self::PHONE_NUMBER_ID, 'matchesFrom' => true, 'collides' => false], 3600);
        $this->assertSame('verified', VoiceNumberGuard::policyCheck(), 'cuatro minutos en el futuro: holgura de relojes');

        // Y la marca de E4-6, con una basura de 20 caracteres, tampoco lanza: falla cerrado.
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, 'xxxxxxxxxxxxxxxxxxxx', 3600);
        $this->assertSame('invalid', VoiceProviderState::verification()['state']);
    }

    public function test_sin_el_numero_en_la_respuesta_no_se_compara_y_manda_la_asignacion(): void
    {
        $this->publishVoice();
        $this->provider(null);
        $r = $this->reconcile();
        $this->assertSame([0, 'ok', null, false], [$r['code'], $r['result'], $r['matchesFrom'], $r['collidesWithMessaging']]);
        $this->assertSame(['matchesFrom' => null, 'collides' => false], array_intersect_key((array) VoiceNumberGuard::lastObservation(), ['matchesFrom' => 1, 'collides' => 1]));
        Artisan::call('voice:doctor', ['--telefonia' => true]);
        $this->assertStringContainsString('Importado  : sin comparar', Artisan::output());

        // Sin TWILIO_VOICE_FROM tampoco se compara (la puerta ya dice `no_voice_number`).
        config(['voice.twilio.from' => null]);
        $this->provider(self::OTRO);
        $this->assertSame(['ok', null], [($r = $this->reconcile())['result'], $r['matchesFrom']]);
    }
}
