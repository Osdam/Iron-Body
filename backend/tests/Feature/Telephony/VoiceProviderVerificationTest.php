<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\Voice\VoiceProviderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E4-6 (B8-g): EL LECTOR DE LA VERIFICACIÓN. La puerta de salientes exige
 * `ELEVENLABS_VERIFIED` (D8-12: una verificación `ok` de menos de 30 días), y
 * hasta B8-g `VoiceProviderState::verifiedRecently()` devolvía siempre `false`:
 * toda saliente era `elevenlabs_unverified` aunque el Studio afirmara
 * `ELEVENLABS_VERIFIED`. Ahora `ultron-studio:integrations-snapshot
 * --verify-voice`, al guardar su evidencia, deja una marca en la caché
 * (`voice:elevenlabs:verified_at`) que solo lee la telefonía, con la MISMA regla
 * que el Studio: la última verificación manda y caduca a los 30 días
 * (estricto). Falla cerrado: sin marca, caducada, ilegible o del futuro,
 * `UNVERIFIED`.
 *
 * Contra `Http::fake` (un GET al agente de verificación) y sin red. El reloj,
 * congelado en UTC (Carbon::setTestNow): lunes 9 de noviembre de 2026, 10:00 de
 * Bogotá, dentro del horario legal de salientes.
 */
class VoiceProviderVerificationTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const PLACE = 'telephony.call_place';

    private const AGENTE = 'agent_verificacion_0001';

    private const CLAVE = 'xi-centinela-VERIFY-0123456789abcdef';

    private const VERIFICAR = 'https://api.elevenlabs.io/v1/convai/agents/'.self::AGENTE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->voiceWorld([
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => self::CLAVE, 'voice.elevenlabs.agent_id' => self::AGENTE,
            'voice.elevenlabs.region' => 'default',
        ]);
        Queue::fake();
        $this->elevenlabs(200);
        // B8-g: el número que llama, vigilado (una conciliación reciente que cuadra): aquí se prueba la verificación de ElevenLabs.
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
    }

    /** ElevenLabs responde así al GET de la verificación (`verifyCredentials`). */
    private function elevenlabs(int $estado): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::VERIFICAR => Http::response($estado === 200
            ? ['conversation_config' => ['tts' => ['agent_output_audio_format' => 'ulaw_8000'], 'asr' => ['user_input_audio_format' => 'ulaw_8000']]]
            : ['detail' => 'x'], $estado)]);
    }

    /** @return array{0: int, 1: array<string, mixed>|null} el código de salida y el bloque `voice` del JSON */
    private function verifyVoice(): array
    {
        $codigo = Artisan::call('ultron-studio:integrations-snapshot', ['--verify-voice' => true, '--json' => true]);

        return [$codigo, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['voice']];
    }

    /** @return list<string> los motivos de la puerta para un canario de voz activo, con todo abierto salvo la constante */
    private function gateReasons(string $canario): array
    {
        config([
            'voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5',
        ]);
        $r = $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]],
            $this->adminWithPermissions([self::PLACE]))->assertStatus(409);

        return $r->json('reasons');
    }

    /** La regresión de E4-6: lo que verifica el Studio ya no lo ignora la puerta de salientes. */
    public function test_la_verificacion_del_studio_quita_elevenlabs_unverified_de_la_puerta_de_salientes(): void
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        $this->assertSame(VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderState::current());
        $this->assertSame(['calls_disabled_in_code', 'elevenlabs_unverified'], $this->gateReasons($canario), 'sin la verificación, falla cerrado');

        [$codigo, $voz] = $this->verifyVoice();
        $this->assertSame([0, true, 'ok', VoiceProviderState::RECORD_WRITTEN], [$codigo, $voz['verified'], $voz['snapshotOutcome'], $voz['telephonyMark']]);
        $this->assertSame('2026-11-09T15:00:00Z', Cache::get(VoiceProviderState::VERIFIED_CACHE_KEY));
        $this->assertSame(VoiceProviderStatus::STATE_VERIFIED, VoiceProviderState::current());
        $this->assertSame(VoiceProviderStatus::STATE_VERIFIED, StudioIntegrations::voiceState(), 'el Studio y la telefonía dicen lo mismo');

        // Solo queda la constante del código (el commit `voice(calls-open)`): la verificación ya no bloquea.
        $this->assertSame(['calls_disabled_in_code'], $this->gateReasons($canario));
        $this->assertSame(0, VoiceCall::query()->count());
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    /** Caduca a los 30 días, estricto, igual que el Studio. */
    public function test_caduca_a_los_30_dias_igual_que_el_studio(): void
    {
        $this->assertSame(0, $this->verifyVoice()[0]);
        $this->travelTo(Carbon::parse('2026-12-09 14:59:59', 'UTC'));
        $this->assertSame([VoiceProviderStatus::STATE_VERIFIED, VoiceProviderStatus::STATE_VERIFIED], [VoiceProviderState::current(), StudioIntegrations::voiceState()]);
        $this->assertSame(['state' => 'valid', 'verifiedAt' => '2026-11-09T15:00:00Z', 'expiresAt' => '2026-12-09T15:00:00Z'], VoiceProviderState::verification());

        $this->travelTo(Carbon::parse('2026-12-09 15:00:00', 'UTC'));
        $this->assertSame([VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderStatus::STATE_UNVERIFIED], [VoiceProviderState::current(), StudioIntegrations::voiceState()]);
        // La caché puede haberla purgado ya (su vida es la misma): caducada o ausente, nunca válida.
        $this->assertContains(VoiceProviderState::verification()['state'], [VoiceProviderState::MARK_EXPIRED, VoiceProviderState::MARK_MISSING]);
        $this->assertFalse(VoiceProviderState::verifiedRecently(Carbon::parse('2026-12-09 15:00:00', 'UTC')));
    }

    /** Manda la ÚLTIMA verificación, como en el Studio: una que falla borra la marca. */
    public function test_una_verificacion_que_falla_borra_la_marca_como_en_el_studio(): void
    {
        $this->assertSame(0, $this->verifyVoice()[0]);
        $this->assertTrue(VoiceProviderState::verifiedRecently());

        $this->travel(1)->hours();
        $this->elevenlabs(401);
        [$codigo, $voz] = $this->verifyVoice();
        $this->assertSame([1, 'auth_failed', VoiceProviderState::RECORD_CLEARED], [$codigo, $voz['snapshotOutcome'], $voz['telephonyMark']]);
        $this->assertNull(Cache::get(VoiceProviderState::VERIFIED_CACHE_KEY));
        $this->assertSame([VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderStatus::STATE_UNVERIFIED], [VoiceProviderState::current(), StudioIntegrations::voiceState()]);

        // Otra buena la vuelve a escribir; sin conexión, la borra otra vez.
        $this->elevenlabs(200);
        $this->assertSame(0, $this->verifyVoice()[0]);
        $this->assertTrue(VoiceProviderState::verifiedRecently());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::VERIFICAR => static fn () => throw new ConnectionException('sin conexión')]);
        [$codigo, $voz] = $this->verifyVoice();
        $this->assertSame([1, 'unreachable', VoiceProviderState::RECORD_CLEARED], [$codigo, $voz['snapshotOutcome'], $voz['telephonyMark']]);
        $this->assertFalse(VoiceProviderState::verifiedRecently());
    }

    /** Si la caché no acepta la marca, la telefonía sigue sin verificar: el comando lo dice y sale con 1 (aunque el Studio guardó su evidencia). */
    public function test_si_la_cache_no_acepta_la_marca_el_comando_lo_dice_y_sale_con_1(): void
    {
        config(['cache.stores.b8g_nula' => ['driver' => 'null']]);
        Cache::setDefaultDriver('b8g_nula');   // NullStore: put() devuelve false y get() nada
        [$codigo, $voz] = $this->verifyVoice();
        $this->assertSame([1, true, 'ok', VoiceProviderState::RECORD_NOT_WRITTEN], [$codigo, $voz['verified'], $voz['snapshotOutcome'], $voz['telephonyMark']]);
        $this->assertSame(VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderState::current());
        $this->assertSame(VoiceProviderStatus::STATE_VERIFIED, StudioIntegrations::voiceState(), 'el Studio sí guardó su evidencia');

        $this->assertSame(1, Artisan::call('ultron-studio:integrations-snapshot', ['--verify-voice' => true]));
        $this->assertStringContainsString('la caché no aceptó la marca de la telefonía', Artisan::output());
    }

    /** Sin la voz en `UNVERIFIED` el comando no llama a nada y no toca la marca; con la bandera apagada la marca no cuenta. */
    public function test_sin_la_voz_en_unverified_no_se_verifica_ni_se_toca_la_marca_y_la_marca_no_cuenta(): void
    {
        $this->assertSame(0, $this->verifyVoice()[0]);
        $this->elevenlabs(200);
        config(['voice.elevenlabs.enabled' => false]);
        [$codigo, $voz] = $this->verifyVoice();
        $this->assertSame([1, 'state_disabled'], [$codigo, $voz['reason']]);
        Http::assertNothingSent();
        $this->assertSame('2026-11-09T15:00:00Z', Cache::get(VoiceProviderState::VERIFIED_CACHE_KEY), 'la marca no se toca');
        $this->assertSame(VoiceProviderStatus::STATE_DISABLED, VoiceProviderState::current(), 'con la bandera apagada, la marca no da VERIFIED');

        config(['voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => null]);
        $this->assertSame(VoiceProviderStatus::STATE_NOT_CONFIGURED, VoiceProviderState::current());
        config(['voice.elevenlabs.api_key' => self::CLAVE]);
        $this->assertSame(VoiceProviderStatus::STATE_VERIFIED, VoiceProviderState::current());
    }

    /** Falla cerrado ante una marca que no es la suya: ilegible, de otra forma o del futuro (más allá de 5 minutos). */
    public function test_una_marca_ilegible_o_del_futuro_no_verifica(): void
    {
        foreach (['ayer', '2026-11-09 15:00:00', '2026-11-09T15:00:00+00:00', '2026-11-09T15:00:00.000Z', '2026-13-40T99:00:00Z', 1762700400, ['at' => 'x'], true] as $marca) {
            Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, $marca, 3600);
            $this->assertSame(['state' => 'invalid', 'verifiedAt' => null, 'expiresAt' => null], VoiceProviderState::verification(), (string) json_encode($marca));
            $this->assertSame(VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderState::current());
        }
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, '2026-11-09T15:06:00Z', 3600);
        $this->assertSame('invalid', VoiceProviderState::verification()['state'], 'seis minutos en el futuro: no');
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, '2026-11-09T15:04:00Z', 3600);
        $this->assertSame('valid', VoiceProviderState::verification()['state'], 'cuatro minutos en el futuro: holgura de relojes');

        // El borde de los 30 días, estricto como el Studio, con la marca aún en la caché.
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, '2026-10-10T15:00:01Z', 3600);
        $this->assertSame([VoiceProviderStatus::STATE_VERIFIED, 'valid'], [VoiceProviderState::current(), VoiceProviderState::verification()['state']], 'un segundo antes de los 30 días');
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, '2026-10-10T15:00:00Z', 3600);
        $this->assertSame([VoiceProviderStatus::STATE_UNVERIFIED, 'expired'], [VoiceProviderState::current(), VoiceProviderState::verification()['state']], 'justo a los 30 días ya no vale');

        // Una evidencia ya caducada no escribe nada, y borra lo que hubiera.
        $this->assertSame(VoiceProviderState::RECORD_CLEARED, VoiceProviderState::recordStudioVerification(true, Carbon::parse('2026-10-10 15:00:00', 'UTC')));
        $this->assertNull(Cache::get(VoiceProviderState::VERIFIED_CACHE_KEY));
        $this->assertSame(VoiceProviderState::RECORD_WRITTEN, VoiceProviderState::recordStudioVerification(true, Carbon::parse('2026-10-10 15:00:01', 'UTC')));
        $this->assertSame('2026-10-10T15:00:01Z', Cache::get(VoiceProviderState::VERIFIED_CACHE_KEY), 'en UTC y al segundo');
        $this->assertSame('2026-11-09T15:00:01Z', VoiceProviderState::verification()['expiresAt']);
    }

    /** El doctor (`--telefonia`) y el estado del CRM enseñan la marca con su fecha, sin secretos. */
    public function test_el_doctor_y_el_estado_ensenan_la_verificacion_con_su_fecha(): void
    {
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $this->assertStringContainsString('Verificado : sin marca: las salientes dicen elevenlabs_unverified', Artisan::output());

        $this->assertSame(0, $this->verifyVoice()[0]);
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $texto = Artisan::output();
        $this->assertStringContainsString('Proveedor  : ELEVENLABS_VERIFIED', $texto);
        $this->assertStringContainsString('Verificado : 2026-11-09T15:00:00Z (vale hasta 2026-12-09T15:00:00Z)', $texto);
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true, '--json' => true]));
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['telefonia'];
        $this->assertSame(['state' => 'valid', 'verifiedAt' => '2026-11-09T15:00:00Z', 'expiresAt' => '2026-12-09T15:00:00Z'], $json['providerVerification']);

        $estado = $this->getJson('/api/admin/voice/status', $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertOk();
        $this->assertSame([VoiceProviderStatus::STATE_VERIFIED, 'valid'], [$estado->json('provider'), $estado->json('providerVerification.state')]);

        $this->travelTo(Carbon::parse('2026-12-10 15:00:00', 'UTC'));
        Cache::put(VoiceProviderState::VERIFIED_CACHE_KEY, '2026-11-09T15:00:00Z', 3600);   // la caché aún no la purgó
        Artisan::call('voice:doctor', ['--telefonia' => true]);
        $this->assertStringContainsString('Verificado : 2026-11-09T15:00:00Z · CADUCADA el 2026-12-09T15:00:00Z', Artisan::output());
        foreach ([self::CLAVE, self::WEBHOOK_SECRET, self::TOOL_SECRET, self::INITIATION_SECRET] as $secreto) {
            $this->assertStringNotContainsString($secreto, $texto.json_encode($json).$estado->getContent());
        }
    }
}
