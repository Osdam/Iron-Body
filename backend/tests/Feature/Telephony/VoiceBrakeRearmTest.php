<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAgentDeployer;
use App\Services\Voice\VoiceNumberObserver;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E10-6 (SEC8-b B-2): el freno SE REARMA por la misma causa si la evidencia
 * cambió DESPUÉS de soltarlo. `open_key` es UNIQUE para siempre y tres causas
 * repiten clave (la deriva del número, `number:<id>:<agente>`; la del agente,
 * `drift:<agente>`; el techo de gasto, `cost_cap:<día>`): antes, una vez
 * soltada, la misma causa ya no volvía a echarlo (ni freno ni incidente).
 *
 *  - Deriva: una observación NUEVA, hecha después de soltar, con el mismo
 *    desacuerdo, lo reabre; la que se hizo antes de soltar (la misma
 *    observación, o el veredicto que el job tiene en su caché) no.
 *  - Techo de gasto: lo comprometido del día MAYOR que lo registrado en la
 *    apertura soltada lo reabre; igual, no.
 * Cada reapertura es una fila nueva (`<clave>:r<n>`) con su incidente, y
 * vuelve a ser idempotente mientras siga echada.
 */
class VoiceBrakeRearmTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ROGUE = 'agent_ajeno_b2_0001';

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        // 10:00 de Bogotá, EN UTC: con una hora de prueba en otra zona, Carbon lee las fechas de la base en esa
        // zona (5 h desplazadas) hasta el primer travel(), y la comparación con la suelta no tocaría el borde.
        $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'UTC'));
        $this->voiceWorld();
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Suelta');
    }

    private function release(VoiceBrakeEntry $apertura): void
    {
        VoiceBrake::release($this->root, (string) $apertura->uuid, 'Causa corregida en la consola', (string) Str::uuid());
    }

    /** Quien lee el número: apunta a `$agente`; `$during` corre MIENTRAS se observa (la observación ya empezó). */
    private static function observer(string $agente, ?Closure $during = null): VoiceNumberObserver
    {
        return new class($agente, $during) implements VoiceNumberObserver
        {
            public function __construct(private readonly string $agente, private readonly ?Closure $during) {}

            public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
            {
                if ($this->during !== null) {
                    ($this->during)();
                }

                return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_OBSERVED, $phoneNumberId, $this->agente, 200, null);
            }
        };
    }

    /** Quien lee el agente: devuelve `$proyeccion`. */
    private static function deployer(array $proyeccion): VoiceAgentDeployer
    {
        return new class($proyeccion) implements VoiceAgentDeployer
        {
            public function __construct(private readonly array $proyeccion) {}

            public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
            {
                throw new \LogicException('la conciliación solo lee');
            }

            public function describeAgent(string $agentId): VoiceAgentObservation
            {
                return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_OBSERVED, $agentId, null, null, $this->proyeccion, 200, null);
            }

            public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
            {
                throw new \LogicException('la conciliación solo lee');
            }
        };
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return VoiceBrakeEntry::query()->where('kind', 'open')->orderBy('id')->pluck('open_key')->all();
    }

    /** Los avisos de IRON GUARD: un incidente por motivo mientras sigue abierto, con una ocurrencia por apertura. */
    private static function incidents(): int
    {
        return (int) Incident::query()->where('source', 'voice')->where('kind', 'voice_brake')->sum('occurrences');
    }

    public function test_deriva_del_numero_la_misma_observacion_no_la_reabre_y_una_nueva_si(): void
    {
        $this->publishVoice();
        $base = 'number:'.self::PHONE_NUMBER_ID.':'.self::ROGUE;
        $conciliacion = new VoiceReconciler;

        $this->assertTrue($conciliacion->numbers(self::observer(self::ROGUE))['brakeOpened']);
        $primera = VoiceBrakeEntry::query()->where('open_key', $base)->sole();

        // LA MISMA OBSERVACIÓN: se hizo antes de soltar (la suelta llega con la lectura en vuelo).
        $r = $conciliacion->numbers(self::observer(self::ROGUE, fn () => $this->release($primera)));
        $this->assertSame(['drift', false], [$r['result'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen(), 'soltado y sin reabrir');
        $this->assertSame([$base], self::keys());

        // UNA OBSERVACIÓN NUEVA, posterior a la suelta, con el mismo desacuerdo: se rearma.
        $this->travel(5)->minutes();
        $r = $conciliacion->numbers(self::observer(self::ROGUE));
        $this->assertSame(['drift', true], [$r['result'], $r['brakeOpened']]);
        $this->assertTrue(VoiceBrake::isOpen());
        $this->assertSame([$base, $base.':r2'], self::keys());
        $segunda = VoiceBrakeEntry::query()->where('open_key', $base.':r2')->sole();
        $this->assertSame('agent_drift', $segunda->reason);
        $this->assertSame(['trigger' => 'number_drift', 'where' => 'reconcile', 'part' => 'canary', 'seq' => 1,
            'observedAgentId' => self::ROGUE, 'expectedAgentId' => self::SEALED_AGENT, 'rearmOf' => $primera->uuid], $segunda->evidence);
        $this->assertSame(2, self::incidents(), 'cada apertura avisa');

        // Echado otra vez: idempotente.
        $this->travel(5)->minutes();
        $this->assertFalse($conciliacion->numbers(self::observer(self::ROGUE))['brakeOpened']);
        $this->assertSame([$base, $base.':r2'], self::keys());

        // Y se vuelve a soltar y a rearmar con cada observación nueva: r3, r4 (la generación sigue a la última).
        $this->release($segunda);
        $this->travel(5)->minutes();
        $this->assertTrue($conciliacion->numbers(self::observer(self::ROGUE))['brakeOpened']);
        $this->release(VoiceBrakeEntry::query()->where('open_key', $base.':r3')->sole());
        $this->travel(5)->minutes();
        $this->assertTrue($conciliacion->numbers(self::observer(self::ROGUE))['brakeOpened']);
        $this->assertSame([$base, $base.':r2', $base.':r3', $base.':r4'], self::keys());
        $this->assertSame(4, self::incidents());
    }

    public function test_deriva_del_agente_la_conciliacion_la_reabre_y_el_veredicto_de_la_cache_del_job_no(): void
    {
        config(['voice.limits.daily_budget_usd' => '1000']);   // el techo de gasto no entra en esta prueba
        $this->publishVoice(agent: ['projection_hash' => VoiceCanonicalJson::hash(['prompt' => 'compilado'])]);
        $deriva = self::deployer(['prompt' => 'cambiado en el panel']);
        $base = 'drift:'.self::SEALED_AGENT;
        $conciliacion = new VoiceReconciler;

        $this->assertSame('drift', $conciliacion->run(new NullTelephonyCarrier, $deriva, now()->subHours(48))['drift']['canary']);
        $this->assertTrue(Cache::get(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT), 'el veredicto queda en la caché del job');
        $primera = VoiceBrakeEntry::query()->where('open_key', $base)->sole();
        $this->release($primera);

        // El job, con el veredicto de ANTES de soltar en su caché: cancela por deriva, pero no es una observación nueva.
        config(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true]);
        // B8-g: el número que llama, vigilado (el job lo vuelve a mirar antes que la deriva); aquí se prueba la deriva.
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        $llamada = $this->voiceCall('outbound', 'requested');
        $motivo = (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), VoiceCall::query()->findOrFail($llamada->id));
        $this->assertSame('agent_drift', $motivo);
        $this->assertSame([$base], self::keys());
        $this->assertFalse(VoiceBrake::isOpen());

        // La conciliación siguiente observa otra vez el mismo desacuerdo: se rearma.
        $this->travel(5)->minutes();
        $this->assertSame('drift', $conciliacion->run(new NullTelephonyCarrier, $deriva, now()->subHours(48))['drift']['canary']);
        $this->assertSame([$base, $base.':r2'], self::keys());
        $this->assertSame(['where' => 'reconcile', 'seq' => 1, 'part' => 'canary', 'rearmOf' => $primera->uuid],
            VoiceBrakeEntry::query()->where('open_key', $base.':r2')->sole()->evidence);
        $this->assertTrue(VoiceBrake::isOpen());

        // Sin deriva no se abre nada, aunque haya aperturas soltadas.
        $this->release(VoiceBrakeEntry::query()->where('open_key', $base.':r2')->sole());
        $this->travel(5)->minutes();
        $this->assertSame('ok', $conciliacion->run(new NullTelephonyCarrier, self::deployer(['prompt' => 'compilado']), now()->subHours(48))['drift']['canary']);
        $this->assertSame([$base, $base.':r2'], self::keys());
    }

    public function test_techo_de_gasto_el_mismo_gasto_no_lo_reabre_y_uno_mayor_si(): void
    {
        config(['voice.limits.daily_budget_usd' => '0.5']);
        $this->voiceCall('outbound', 'completed', ['remote' => '+573000000041']);   // 610 500 µUS$ comprometidos
        $dia = '2026-11-04';
        $base = 'cost_cap:'.$dia;
        $conciliacion = new VoiceReconciler;
        $pasada = fn (): array => $conciliacion->run(new NullTelephonyCarrier, new NullVoiceAgentDeployer, now()->subHours(48));

        $this->assertTrue($pasada()['costCap']);
        $primera = VoiceBrakeEntry::query()->where('open_key', $base)->sole();
        $this->assertSame(['day' => $dia, 'committedMicros' => 610500, 'budgetMicros' => 500000], $primera->evidence);
        $this->release($primera);

        // EL MISMO GASTO (lo registrado en la apertura soltada): no se reabre. El resumen
        // sigue diciendo que se pasa del presupuesto (`costCap` es el estado, no la apertura).
        $this->travel(5)->minutes();
        $this->assertTrue($pasada()['costCap']);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame([$base], self::keys());

        // UN GASTO MAYOR después de soltar: se rearma, con lo comprometido de ahora.
        $this->voiceCall('inbound', 'active', ['remote' => '+573000000042']);
        $this->assertTrue($pasada()['costCap']);
        $this->assertSame([$base, $base.':r2'], self::keys());
        $this->assertSame(['day' => $dia, 'committedMicros' => 1221000, 'budgetMicros' => 500000, 'rearmOf' => $primera->uuid],
            VoiceBrakeEntry::query()->where('open_key', $base.':r2')->sole()->evidence);
        $this->assertTrue(VoiceBrake::isOpen());
        $this->assertSame(2, self::incidents());

        // Echado: idempotente aunque siga subiendo.
        $this->voiceCall('inbound', 'active', ['remote' => '+573000000043']);
        $this->assertTrue($pasada()['costCap']);
        $this->assertSame([$base, $base.':r2'], self::keys());
    }

    public function test_las_causas_de_una_sola_vez_no_se_rearman_y_el_rearme_pregunta_a_la_evidencia(): void
    {
        $apertura = VoiceBrake::open('unknown_provider_call', 'unknown:CA000000000000000000000000a8b20001', ['where' => 'reconcile']);
        $this->release($apertura);
        $this->assertNull(VoiceBrake::open('unknown_provider_call', 'unknown:CA000000000000000000000000a8b20001', ['where' => 'reconcile']), 'open() sigue siendo idempotente por su clave para siempre');

        $vistas = [];
        $cambio = static function (VoiceBrakeEntry $abierta, VoiceBrakeEntry $suelta) use (&$vistas): bool {
            $vistas[] = [$abierta->open_key, $suelta->brake_id === $abierta->id];

            return true;
        };
        $primera = VoiceBrake::openOrRearm('agent_drift', 'drift:agent_x', ['where' => 'prueba'], $cambio);
        $this->assertNotNull($primera, 'la primera vez abre, sin preguntar');
        $this->assertNull(VoiceBrake::openOrRearm('agent_drift', 'drift:agent_x', ['where' => 'prueba'], $cambio), 'echada: idempotente, sin preguntar');
        $this->assertSame([], $vistas);
        $this->release($primera);
        $this->assertNull(VoiceBrake::openOrRearm('agent_drift', 'drift:agent_x', ['where' => 'prueba'], static fn (): bool => false), 'soltada y la evidencia no cambió');
        $this->assertNotNull(VoiceBrake::openOrRearm('agent_drift', 'drift:agent_x', ['where' => 'prueba'], $cambio));
        $this->assertSame([['drift:agent_x', true]], $vistas, 'pregunta con la apertura soltada y su suelta');
        $this->assertSame(['unknown:CA000000000000000000000000a8b20001', 'drift:agent_x', 'drift:agent_x:r2'], self::keys());

        // Una clave larga (ids del proveedor de hasta 128) se rearma con su huella: siempre cabe en `open_key`.
        $larga = 'number:'.str_repeat('p', 60).':'.str_repeat('a', 60);
        $this->release(VoiceBrake::openOrRearm('agent_drift', $larga, [], $cambio));
        $rearme = VoiceBrake::openOrRearm('agent_drift', $larga, [], $cambio);
        $this->assertSame('rearm:'.hash('sha256', $larga).':r2', $rearme?->open_key);
    }
}
