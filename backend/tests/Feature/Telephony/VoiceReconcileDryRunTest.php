<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use App\Services\Voice\VoiceAgentDeployer;
use App\Services\Voice\VoiceNumberObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E10-9 (DOC8-2, R8-27): `voice:reconcile --dry-run`. El runbook usa la
 * conciliación como diagnóstico ANTES de soltar el freno, y la de verdad
 * escribe (eventos, precios, transiciones) y puede echarlo otra vez. En seco:
 * lee lo mismo (también los GET a los proveedores), informa lo que haría
 * (`dryRun`, `wouldOpenBrake`, y en `would` las aperturas, los incidentes, los
 * eventos por tipo, las transiciones y los precios) y NO ESCRIBE NADA: ni
 * filas, ni eventos, ni freno, ni incidentes, ni la caché que lee el job.
 */
class VoiceReconcileDryRunTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld([
            'voice.twilio.account_sid' => self::ACCOUNT,
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 31).'1',
            'voice.twilio.api_key_secret' => 'secreto-de-la-key-de-prueba',
            'voice.twilio.base_url' => 'http://127.0.0.1:8095',
            'voice.limits.daily_budget_usd' => '1000',
        ]);
    }

    /** La página de la subcuenta del fixture, con su llamada desconocida y sus filas (como `VoiceReconcilerTest`). */
    private function fixturePage(): void
    {
        $c = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0];
        foreach ($c['given']['voiceCallsByCallSid'] as $sid => $estado) {
            $remoto = '+5730000'.sprintf('%05d', hexdec(substr($sid, -4)));
            if ($estado === 'denied') {
                $this->voiceCall('inbound', 'denied', ['provider_call_sid' => $sid, 'remote' => self::STRANGER_PHONE]);
            } elseif ($estado === 'ambiguous') {
                $fila = $this->voiceCall('outbound', 'ambiguous', ['remote' => $remoto]);
                VoiceEvents::record('outbound_ambiguous', (int) $fila->id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'amb:'.$fila->uuid, ['outcome' => 'server_error', 'codes' => ['callSid' => $sid, 'conversationId' => null]]);
            } else {
                $this->voiceCall('outbound', $estado, ['provider_call_sid' => $sid, 'remote' => $remoto]);
            }
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8095/*' => Http::response($c['page'])]);
    }

    /** @return array<string, mixed> todo lo que la conciliación podría escribir */
    private static function snapshot(): array
    {
        return [
            'calls' => DB::table('voice_calls')->orderBy('id')->get()->map(static fn ($f): array => (array) $f)->all(),
            'events' => DB::table('voice_call_events')->orderBy('id')->get()->map(static fn ($f): array => (array) $f)->all(),
            'brakes' => DB::table('voice_brakes')->orderBy('id')->get()->map(static fn ($f): array => (array) $f)->all(),
            'incidents' => DB::table('incidents')->orderBy('id')->get()->map(static fn ($f): array => (array) $f)->all(),
        ];
    }

    /** @return array<string, int> */
    private static function eventCounts(): array
    {
        $n = VoiceCallEvent::query()->select('kind', DB::raw('count(*) AS n'))->groupBy('kind')->pluck('n', 'kind')->map(static fn ($v): int => (int) $v)->all();
        ksort($n);

        return $n;
    }

    public function test_en_seco_una_desconocida_se_informa_y_la_base_no_cambia_y_despues_pasa_lo_anunciado(): void
    {
        $this->fixturePage();
        $this->voiceCall('outbound', 'requested', ['remote' => '+573000000090']);   // perderá su job
        $this->travel(16)->minutes();
        config(['voice.limits.daily_budget_usd' => '0.5']);                          // y el techo del día, superado
        $antes = self::snapshot();
        $eventosAntes = self::eventCounts();

        $codigo = Artisan::call('voice:reconcile', ['--dry-run' => true, '--json' => true]);
        $seco = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($antes, self::snapshot(), 'en seco no cambia NADA: filas, eventos, freno ni incidentes');
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(1, $codigo, 'el mismo código de salida: hay una desconocida y el techo se pasa');
        $this->assertSame([true, true], [$seco['dryRun'], $seco['wouldOpenBrake']]);
        $this->assertSame(['listed', 7, 1, 1, 1, true], [$seco['carrier'], $seco['known'], $seco['legs'], $seco['unknown'], $seco['staleRequested'], $seco['costCap']]);
        $this->assertSame([['reason' => 'unknown_provider_call', 'cause' => 'unknown_provider_call'], ['reason' => 'cost_cap', 'cause' => 'cost_cap']], $seco['would']['brakes']);
        $this->assertSame(2, $seco['would']['incidents']);
        $this->assertSame(1, $seco['would']['events']['unknown_provider_call']);
        Http::assertSent(static fn ($r) => $r->method() === 'GET' && str_starts_with($r->url(), 'http://127.0.0.1:8095/'));

        // De verdad: hace exactamente lo que anunció.
        $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48));
        $this->assertSame(array_diff_key($seco, ['dryRun' => 1, 'wouldOpenBrake' => 1, 'would' => 1]), $resumen, 'el mismo resumen');
        $nuevos = [];
        foreach (self::eventCounts() as $tipo => $n) {
            if ($n - ($eventosAntes[$tipo] ?? 0) > 0) {
                $nuevos[$tipo] = $n - ($eventosAntes[$tipo] ?? 0);
            }
        }
        $this->assertSame($seco['would']['events'], $nuevos, 'los mismos eventos, por tipo');
        $this->assertSame(['unknown_provider_call', 'cost_cap'], VoiceBrakeEntry::query()->orderBy('id')->pluck('reason')->all(), 'las mismas aperturas');
        $this->assertSame(2, (int) Incident::query()->where('source', 'voice')->sum('occurrences'), 'y sus incidentes');
        $cambiadas = 0;
        foreach (DB::table('voice_calls')->orderBy('id')->get() as $i => $f) {
            $cambiadas += $f->status !== $antes['calls'][$i]['status'] ? 1 : 0;
        }
        $this->assertSame($seco['would']['transitions'], $cambiadas, 'las mismas transiciones');
        $this->assertSame($seco['would']['costs'], ($nuevos['cost_recorded'] ?? 0), 'los mismos precios');

        // Una segunda pasada en seco ya no anuncia nada nuevo (y sigue sin escribir).
        $despues = self::snapshot();
        Artisan::call('voice:reconcile', ['--dry-run' => true, '--json' => true]);
        $otra = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([false, [], 0, 0, 0], [$otra['wouldOpenBrake'], $otra['would']['events'], $otra['would']['transitions'], $otra['would']['costs'], $otra['would']['incidents']]);
        $this->assertSame($despues, self::snapshot());
    }

    /**
     * Una saliente en `dialing` (reserva 610 500) que Twilio ya da por `busy` con 12 000 µUS$: de verdad deja de
     * reservar (sin conversación: solo lo real) y su precio pasa a la fila. En seco tiene que verlo igual.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} en seco, su informe y de verdad
     */
    private function busyPass(string $presupuesto): array
    {
        $sid = 'CA000000000000000000000000a8d90001';
        $this->voiceCall('outbound', 'dialing', ['provider_call_sid' => $sid, 'remote' => '+573000000091']);
        $this->travel(16)->minutes();   // en `dialing` desde hace más de 10 min: atascada, hasta que la pasada la cierra
        config(['voice.limits.daily_budget_usd' => $presupuesto]);
        // La forma de una llamada de la página del fixture, ocupada y con su precio.
        $pagina = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0]['page'];
        $pagina['calls'] = [array_merge($pagina['calls'][0], ['sid' => $sid, 'status' => 'busy', 'duration' => '0', 'price' => '-0.01200', 'to' => '+573000000091', 'to_formatted' => '+573000000091'])];
        $pagina['end'] = 1;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8095/*' => Http::response($pagina)]);
        $this->assertSame(610500, VoiceCostGuard::committedMicros(VoiceCostGuard::budgetDay(now())));

        $conciliacion = app(VoiceReconciler::class);
        $seco = $conciliacion->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48), true);
        $plan = $conciliacion->dryRunReport();
        $this->assertSame(['cost_recorded' => 1, 'reconciled' => 1], $plan['would']['events']);
        $this->assertSame([1, 1], [$plan['would']['transitions'], $plan['would']['costs']]);
        $this->assertSame(['dialing', null], [VoiceCall::query()->where('provider_call_sid', $sid)->value('status'), VoiceCall::query()->where('provider_call_sid', $sid)->value('carrier_cost_micros')]);

        $this->assertSame(1, VoiceCall::query()->where('status', 'dialing')->where('status_changed_at', '<', now()->subMinutes(10))->count(), 'atascada antes de la pasada');
        $real = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48));
        $this->assertSame($seco, $real, 'en seco, el mismo resumen (también las atascadas: 0, la pasada la cierra)');
        $this->assertSame(0, $real['stuckDialing']);
        $this->assertSame(12000, VoiceCostGuard::committedMicros(VoiceCostGuard::budgetDay(now())));

        return [$seco, $plan, $real];
    }

    public function test_en_seco_el_techo_cuenta_el_estado_que_dejaria_la_pasada(): void
    {
        // 0,50 US$: con la reserva (610 500) se pasaría; con lo que deja la pasada (12 000), no.
        [$seco, $plan] = $this->busyPass('0.5');
        $this->assertSame([1, false, false, 0], [$seco['known'], $seco['costCap'], $plan['wouldOpenBrake'], $seco['stuckDialing']]);
        $this->assertFalse(VoiceBrake::isOpen());
    }

    public function test_en_seco_el_techo_cuenta_el_precio_que_sumaria_la_pasada(): void
    {
        // 0,01 US$ (10 000): sin el precio que suma la pasada, 0; con él, 12 000: se pasa y echaría el freno.
        [$seco, $plan] = $this->busyPass('0.01');
        $this->assertSame([true, true], [$seco['costCap'], $plan['wouldOpenBrake']]);
        $this->assertSame([['reason' => 'cost_cap', 'cause' => 'cost_cap']], $plan['would']['brakes']);
        $this->assertSame(['cost_cap'], VoiceBrakeEntry::query()->pluck('reason')->all(), 'y de verdad la abre');
    }

    public function test_en_seco_la_deriva_y_su_rearme_se_anuncian_sin_tocar_la_cache_ni_el_freno(): void
    {
        $pub = $this->publishVoice(agent: ['projection_hash' => VoiceCanonicalJson::hash(['prompt' => 'compilado'])]);
        $this->publishVoice(part: 'rest', agent: ['row' => $pub['agentRowId']]);   // promovida: las dos partes, el mismo agente
        $deriva = new class implements VoiceAgentDeployer
        {
            public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
            {
                throw new \LogicException('solo lee');
            }

            public function describeAgent(string $agentId): VoiceAgentObservation
            {
                return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_OBSERVED, $agentId, null, null, ['prompt' => 'cambiado'], 200, null);
            }

            public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
            {
                throw new \LogicException('solo lee');
            }
        };
        $numero = new class implements VoiceNumberObserver
        {
            public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
            {
                return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_OBSERVED, $phoneNumberId, 'agent_ajeno_dry_0001', 200, null);
            }
        };
        $clave = OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT;

        $c = app(VoiceReconciler::class);
        $this->assertSame('drift', $c->run(new NullTelephonyCarrier, $deriva, now()->subHours(48), true)['drift']['canary']);
        $n = $c->numbers($numero, true);
        $this->assertSame(['drift', false, true], [$n['result'], $n['brakeOpened'], $n['wouldOpenBrake']]);
        $plan = $c->dryRunReport();
        // Las dos partes derivan con el MISMO agente: una apertura, como hace open() de verdad.
        $this->assertSame([['reason' => 'agent_drift', 'cause' => 'agent_drift'], ['reason' => 'agent_drift', 'cause' => 'number_drift']], $plan['would']['brakes']);
        $this->assertFalse(Cache::has($clave), 'en seco no deja el veredicto en la caché del job');
        $this->assertSame(0, VoiceBrakeEntry::query()->count());

        // De verdad abre las dos; soltadas, en seco anuncia el REARME de las dos (observación nueva) sin escribirlo.
        $this->assertSame(['canary' => 'drift', 'rest' => 'drift'], $c->run(new NullTelephonyCarrier, $deriva, now()->subHours(48))['drift']);
        $c->numbers($numero);
        $this->assertSame(['drift:'.self::SEALED_AGENT, 'number:'.self::PHONE_NUMBER_ID.':agent_ajeno_dry_0001'], VoiceBrakeEntry::query()->orderBy('id')->pluck('open_key')->all());
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Suelta');
        foreach (VoiceBrakeEntry::query()->where('kind', 'open')->get() as $a) {
            VoiceBrake::release($root, (string) $a->uuid, 'Revisado en la consola', (string) Str::uuid());
        }
        $this->travel(5)->minutes();
        $antes = self::snapshot();
        $c->run(new NullTelephonyCarrier, $deriva, now()->subHours(48), true);
        $c->numbers($numero, true);
        $this->assertSame(['agent_drift', 'number_drift'], array_column($c->dryRunReport()['would']['brakes'], 'cause'));
        $this->assertSame($antes, self::snapshot());
        $this->assertFalse(VoiceBrake::isOpen());
    }
}
