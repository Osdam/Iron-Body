<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceCallStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * «EL MOTOR DE LLAMADAS UTILIZA EXCLUSIVAMENTE EL DESTINO CANARIO AUTORIZADO»
 * (decisión del propietario del 22-11-2026, `MATRIZ_CIERRE_V2.md` §9; punto 4
 * de `plans/informes-cierre/diseno-arq-telefonia.md`), con base de datos y los
 * canarios creados por la API del Studio (cuatro ojos), no con el fixture puro:
 *  (a) la puerta de salientes, con un canario `pending`, `authorized` (sin
 *      activar o desactivado), `revoked`, de canal `whatsapp` o un uuid que no
 *      existe, niega con su código (409 `not_an_active_voice_canary` o 404
 *      `target_not_found`), sin fila de llamada, sin reserva y sin job;
 *  (b) el job, con el canario desactivado o revocado ENTRE la puerta y el
 *      marcado, cancela con `not_an_active_voice_canary` sin marcar y libera
 *      la reserva.
 * Todo lo demás está en regla (banderas, proveedor verificado, número vigilado
 * y dedicado, cupo y presupuesto): el canario es lo único que se niega. Vale
 * con la constante cerrada y abierta (el motivo de la constante, aparte). Sin red.
 */
class OutboundCanaryTargetTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private Admin $ana;

    private Admin $beto;

    protected function setUp(): void
    {
        parent::setUp();
        // Un miércoles laborable, 10:00 de Bogotá, en UTC: dentro de la ventana legal.
        $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        $this->voiceWorld([
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'xi-centinela-CANARY-0123456789', 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.region' => 'default', 'meta.whatsapp_display_phone' => null, 'meta.protected_numbers' => [],
            'voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5',
        ]);
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->numeroVigilado();
        $this->ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Registra');
        $this->beto = $this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Autoriza');
    }

    /** El número que llama, vigilado ahora (una conciliación fresca que cuadra), y la deriva del agente sellado ya comprobada. */
    private function numeroVigilado(): void
    {
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
    }

    /** @return array{http: int, code: ?string, reasons: list<string>} la respuesta de la puerta, con los motivos SIN el de la constante */
    private function puerta(string $uuid): array
    {
        $r = $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $uuid]],
            $this->adminWithPermissions(['telephony.call_place']));
        $motivos = $r->json('reasons') ?? [];
        if ($r->status() === 409) {
            $this->assertSame(! VoiceCallPolicy::CALLS_ENABLED_IN_CODE, in_array('calls_disabled_in_code', $motivos, true), 'la constante, por su nombre, solo si está cerrada');
        }

        return ['http' => $r->status(), 'code' => $r->json('code'), 'reasons' => array_values(array_diff($motivos, ['calls_disabled_in_code']))];
    }

    private function desactivar(string $uuid): void
    {
        $this->integrations('POST', "canaries/{$uuid}/deactivate", $this->ana, ['reason' => 'Fin de la prueba de hoy'])->assertOk();
    }

    private function revocar(string $uuid): void
    {
        $this->integrations('POST', "canaries/{$uuid}/revoke", $this->ana, ['reason' => 'Ya no se usa este móvil'])->assertOk();
    }

    /** (a) Cada destino que no es un canario de voz activo, por la API del Studio. */
    public function test_la_puerta_niega_todo_destino_que_no_sea_un_canario_de_voz_activo_sin_fila_ni_reserva(): void
    {
        $pendiente = (string) $this->registerCanary($this->ana, '+573000000041', 'voice', 'Pendiente')->assertCreated()->json('data.number.uuid');
        $autorizado = (string) $this->registerCanary($this->ana, '+573000000042', 'voice', 'Autorizado')->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$autorizado}/authorize", $this->beto)->assertOk();
        $desactivado = $this->activeVoiceCanary('+573000000043');
        $this->desactivar($desactivado);
        $revocado = $this->activeVoiceCanary('+573000000044');
        $this->revocar($revocado);
        $deMensajeria = $this->activeCanary($this->ana, $this->beto, '+573000000045', 'whatsapp', 'Móvil de la mensajería');

        $estados = StudioCanaryNumber::query()->pluck('status', 'uuid')->all();
        $this->assertSame(['pending', 'authorized', 'authorized', 'revoked', 'active'], [$estados[$pendiente], $estados[$autorizado], $estados[$desactivado], $estados[$revocado], $estados[$deMensajeria]]);

        $dia = VoiceCostGuard::budgetDay(now());
        foreach (['pendiente' => $pendiente, 'autorizado sin activar' => $autorizado, 'desactivado' => $desactivado, 'revocado' => $revocado] as $caso => $uuid) {
            $this->assertSame(['http' => 409, 'code' => 'outbound_denied', 'reasons' => ['not_an_active_voice_canary']], $this->puerta($uuid), $caso);
        }
        foreach (['canal whatsapp' => $deMensajeria, 'uuid inexistente' => (string) Str::uuid()] as $caso => $uuid) {
            $this->assertSame(['http' => 404, 'code' => 'target_not_found', 'reasons' => []], $this->puerta($uuid), $caso);
        }
        $this->assertSame(0, VoiceCall::query()->count(), 'ninguna fila de llamada');
        $this->assertSame(0, VoiceCallEvent::query()->count());
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia), 'ninguna reserva');
        Queue::assertNothingPushed();

        // El control: el mismo mundo con un canario de voz activo solo tiene la constante (abierta, la acepta).
        $activo = $this->activeVoiceCanary('+573000000046');
        $this->assertSame(VoiceCallPolicy::CALLS_ENABLED_IN_CODE ? [202, []] : [409, []], array_values(array_intersect_key($this->puerta($activo), ['http' => 1, 'reasons' => 1])));
        Http::assertNothingSent();
    }

    /**
     * (b) El canario deja de estar activo ENTRE la puerta y el marcado (desactivado o revocado con la saliente en la
     * cola): el job, con las reglas de la puerta, cancela con `not_an_active_voice_canary`; además, sin canario activo
     * no hay número que marcar (`request()` = null). Sin marcar, sin evento y con la reserva liberada.
     */
    public function test_el_job_cancela_sin_marcar_si_el_canario_se_desactiva_o_se_revoca_tras_la_puerta(): void
    {
        $recheck = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode');
        $peticion = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'request');
        $cancelar = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'cancel');
        $dia = VoiceCostGuard::budgetDay(now());

        foreach (['desactivado' => fn (string $u) => $this->desactivar($u), 'revocado' => fn (string $u) => $this->revocar($u)] as $caso => $quitar) {
            $canario = $this->activeVoiceCanary($caso === 'desactivado' ? '+573000000051' : '+573000000052');
            $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => $caso === 'desactivado' ? '+573000000051' : '+573000000052']);
            $job = new PlaceOutboundVoiceCall((int) $llamada->id);
            $this->assertNull($recheck->invoke($job, VoiceCall::query()->findOrFail($llamada->id)), "{$caso}: con el canario activo, pasaría");
            $this->assertInstanceOf(OutboundCallRequest::class, $peticion->invoke($job, VoiceCall::query()->findOrFail($llamada->id)), "{$caso}: y tendría a quién marcar");
            $this->assertSame((int) $llamada->reserved_micros, VoiceCostGuard::committedMicros($dia), "{$caso}: pedida, su reserva cuenta");

            $quitar($canario);
            $fila = VoiceCall::query()->findOrFail($llamada->id);
            $this->assertSame('not_an_active_voice_canary', $recheck->invoke($job, $fila), $caso);
            $this->assertNull($peticion->invoke($job, $fila), "{$caso}: sin canario activo no hay número que marcar");
            $this->assertSame('canceled', $cancelar->invoke($job, $fila, 'not_an_active_voice_canary'));

            $fila = VoiceCall::query()->findOrFail($llamada->id);
            $this->assertSame(['canceled', 'not_an_active_voice_canary'], [$fila->status, $fila->policy_reason], $caso);
            $this->assertNotNull($fila->ended_at);
            $this->assertSame(0, VoiceCallEvent::query()->where('call_id', $llamada->id)->count(), "{$caso}: sin evento (fixture §job: events = [])");
            $this->assertSame(0, VoiceCostGuard::committedMicros($dia), "{$caso}: cancelada, la reserva liberada");
        }
        Http::assertNothingSent();
    }

    /**
     * (b) con el job DE VERDAD (`run()`), sin marcar nunca: con la constante cerrada cancela por ella antes de todo;
     * abierta (la copia de la apertura), por el canario desactivado tras la puerta. El iniciador nunca se llama.
     */
    public function test_el_job_real_nunca_marca_a_un_canario_desactivado_tras_la_puerta(): void
    {
        $canario = $this->activeVoiceCanary('+573000000053');
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => '+573000000053']);
        $this->desactivar($canario);
        $this->numeroVigilado();
        $iniciador = new class implements VoiceCallStarter
        {
            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                throw new RuntimeException('no tenía que marcar');
            }
        };

        $this->assertSame('canceled', (new PlaceOutboundVoiceCall((int) $llamada->id))->run($iniciador));
        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['canceled', VoiceCallPolicy::CALLS_ENABLED_IN_CODE ? 'not_an_active_voice_canary' : 'calls_disabled_in_code'], [$fila->status, $fila->policy_reason]);
        $this->assertSame(0, VoiceCostGuard::committedMicros(VoiceCostGuard::budgetDay(now())));
        Http::assertNothingSent();
    }
}
