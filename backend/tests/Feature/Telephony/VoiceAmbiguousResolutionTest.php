<?php

namespace Tests\Feature\Telephony;

use App\Console\Commands\Voice\VoiceResolveAmbiguousCommand;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceAmbiguousResolution;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\TelephonyCarrier;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LA SALIDA DE UNA `ambiguous` QUE NO SALIÓ (Enmienda del 22-11-2026, E19-3;
 * H-1 del informe `informe-be-voz.md`). Sobre las clases reales: la
 * conciliación con un operador falso (sus páginas), la orden de consola y el
 * webhook post-llamada FIRMADO. Sin red.
 *
 * EL ESQUEMA ADMITE LA TRANSICIÓN desde la migración E19-3
 * (`2026_11_22_100002`, que rehace `vc_status_forward` de DB8 con
 * `ambiguous → failed_to_start`): la salida, de punta a punta, en los dos
 * motores (antes era una simulación local que no se confirmó; su evidencia,
 * `ops/evidencia-h8/2026-11-22/cierre-voz2/e19-3-simulacion.txt`). Cada duda
 * deja la fila `ambiguous` con su motivo (falla cerrado).
 */
class VoiceAmbiguousResolutionTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/elevenlabs/post-call';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-23 15:00:00', 'UTC'));
        $this->voiceWorld(['voice.limits.daily_budget_usd' => '1000']);
    }

    public function test_el_plazo_de_gracia_cubre_de_sobra_timbre_duracion_maxima_y_post_llamada(): void
    {
        $timbre = 60;        // el máximo de VOICE_OUTBOUND_RINGING_SECONDS (config/voice.php: 10–60)
        $duracion = 600;     // el máximo de VOICE_PER_CALL_MAX_SECONDS (el recorte, D8-15)
        $postLlamada = 120;  // el retraso de la post-llamada y de la lista de Twilio, generoso
        $this->assertGreaterThanOrEqual(4 * ($timbre + $duracion + $postLlamada), VoiceAmbiguousResolution::GRACE_MINUTES * 60);
        $this->assertSame(60, (int) config('voice.outbound.ringing_timeout_seconds') <= 60 ? 60 : -1, 'el timbre configurable no pasa de 60 s');
        $this->assertSame(VoiceCall::STATUS_FAILED_TO_START, VoiceAmbiguousResolution::FINAL, 'D8-13: «no llegó a iniciarse»');
    }

    /**
     * La salida de punta a punta con el esquema de E19-3 (la simulación del autor, ahora de verdad): en seco, lo que
     * haría sin escribir; la de consola sobre una; la automática sobre otra, que libera su reserva y deja de contar como
     * viva; y si después llega su post-llamada, la anomalía y el freno, sin resucitarla.
     */
    public function test_con_una_pasada_completa_y_limpia_la_fila_vieja_pasa_a_failed_to_start_y_libera_su_reserva(): void
    {
        $this->assertTrue(VoiceAmbiguousResolution::schemaAllows(), 'E19-3: el grafo admite ambiguous → failed_to_start');
        $a = $this->ambiguousCall('+573000000086');
        $b = $this->ambiguousCall('+573000000087');
        $dia = (string) $a->budget_day;
        $reservado = VoiceCostGuard::committedMicros($dia);
        $this->assertGreaterThan(0, $reservado);
        $this->travel(VoiceAmbiguousResolution::GRACE_MINUTES + 1)->minutes();

        // En seco: lo que haría, sin escribir nada.
        $conciliacion = app(VoiceReconciler::class);
        $seco = $conciliacion->run($this->carrier([[]]), new NullVoiceAgentDeployer, now()->subHours(48), true);
        $informe = $conciliacion->dryRunReport();
        $this->assertSame([['reconciled' => 2], 2], [$informe['would']['events'], $informe['would']['transitions']]);
        $this->assertSame(['resolved' => 2, 'held' => []], $seco['ambiguous']);
        $this->assertSame(2, VoiceCall::query()->where('status', VoiceCall::STATUS_AMBIGUOUS)->count(), 'en seco no cambia nada');
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'reconciled')->count());

        // La de consola, sobre b.
        $this->assertSame(0, Artisan::call('voice:resolve-ambiguous', ['call' => $b->uuid, '--confirm' => true, '--reason' => 'Comprobado en Twilio: ninguna llamada a esa hora']), Artisan::output());
        $mb = VoiceCallEvent::query()->where('call_id', $b->id)->where('kind', 'reconciled')->sole();
        $this->assertSame(['ambiguous_resolved', 'system', 'Consola (voice:resolve-ambiguous)', 'console'], [$mb->outcome, $mb->actor_type, $mb->actor_name, $mb->codes['by']]);
        $this->assertSame(VoiceCall::STATUS_FAILED_TO_START, VoiceCall::query()->findOrFail($b->id)->status);

        // La automática, sobre a.
        $resumen = app(VoiceReconciler::class)->run($this->carrier([[]]), new NullVoiceAgentDeployer, now()->subHours(48));
        $this->assertSame(['resolved' => 1, 'held' => []], $resumen['ambiguous']);
        $fa = VoiceCall::query()->findOrFail($a->id);
        $this->assertSame([VoiceCall::STATUS_FAILED_TO_START, null, null], [$fa->status, $fa->provider_call_sid, $fa->provider_conversation_id]);
        $this->assertNotNull($fa->ended_at);
        $ev = VoiceCallEvent::query()->where('call_id', $a->id)->where('kind', 'reconciled')->sole();
        $this->assertSame('ambiguous_resolved', $ev->outcome);
        $this->assertSame(['listed', 0, 1], [$ev->codes['pass']['carrier'], $ev->codes['pass']['unknown'], $ev->codes['pass']['pages']]);
        $this->assertSame(60, $ev->codes['window']['graceMinutes']);
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia), 'la reserva se libera (RELEASED_STATUSES)');
        $this->assertSame(0, VoiceCall::query()->whereIn('status', VoiceCall::LIVE)->count(), 'ya no bloquea la concurrencia');
        $this->assertSame(['resolved' => 0, 'held' => []], $this->pass()['ambiguous'], 'otra pasada no encuentra nada');

        // Y si después llega su post-llamada: anomalía y freno, sin resucitarla.
        $this->signed($this->postCallBody($fa, 'conv_h8fx_0960'))->assertOk()->assertJson(['status' => 'failed_to_start', 'anomaly' => 'resolved_call_reappeared']);
        $this->assertSame(VoiceCall::STATUS_FAILED_TO_START, VoiceCall::query()->findOrFail($a->id)->status);
        $this->assertSame(['unknown_provider_call'], VoiceBrakeEntry::query()->pluck('reason')->all());
    }

    public function test_cada_duda_la_deja_ambiguous_con_su_motivo(): void
    {
        $n = 0;
        $vieja = function () use (&$n): VoiceCall {
            $fila = $this->ambiguousCall('+5730000001'.str_pad((string) ++$n, 2, '0', STR_PAD_LEFT));   // una viva por número (vc_one_live_outbound)
            $this->travel(VoiceAmbiguousResolution::GRACE_MINUTES + 1)->minutes();

            return $fila;
        };
        $casos = [
            'el operador sin configurar' => [new NullTelephonyCarrier, VoiceAmbiguousResolution::HELD_PASS_INCOMPLETE],
            'el operador falla' => [$this->carrier([CarrierCallsPage::OUTCOME_UNREACHABLE]), VoiceAmbiguousResolution::HELD_PASS_INCOMPLETE],
            'una página truncada' => [$this->carrier([[[], 'truncated']]), VoiceAmbiguousResolution::HELD_PASS_INCOMPLETE],
            'cortada por el tope de páginas' => [$this->carrier(array_fill(0, VoiceReconciler::MAX_PAGES + 1, [])), VoiceAmbiguousResolution::HELD_PASS_INCOMPLETE],
            'una llamada desconocida en la ventana' => [$this->carrier([[self::carrierCall(7)]]), VoiceAmbiguousResolution::HELD_UNKNOWN_CALLS],
        ];
        foreach ($casos as $nombre => [$transportista, $motivo]) {
            $fila = $vieja();
            $resumen = app(VoiceReconciler::class)->run($transportista, new NullVoiceAgentDeployer, now()->subHours(48));
            $this->assertSame([$motivo => $n], $resumen['ambiguous']['held'], "{$nombre}: esta y las de los casos anteriores, por el mismo motivo");
            $this->assertSame(0, $resumen['ambiguous']['resolved'], $nombre);
            $this->assertSame(VoiceCall::STATUS_AMBIGUOUS, VoiceCall::query()->findOrFail($fila->id)->status, $nombre);
        }
    }

    public function test_la_fila_joven_la_de_ventana_sin_cubrir_y_la_que_trae_ids_del_proveedor_se_quedan(): void
    {
        // Joven: un minuto antes del plazo.
        $joven = $this->ambiguousCall('+573000000081');
        $this->travel(VoiceAmbiguousResolution::GRACE_MINUTES - 1)->minutes();
        $this->assertSame([VoiceAmbiguousResolution::HELD_WITHIN_GRACE => 1], $this->pass()['ambiguous']['held']);

        // La lista empieza después de pedir la llamada (menos del margen de los relojes antes).
        $this->travel(2)->minutes();
        $desde = Carbon::parse((string) VoiceCall::query()->findOrFail($joven->id)->created_at)->subMinutes(VoiceAmbiguousResolution::CLOCK_SKEW_MINUTES - 1);
        $resumen = app(VoiceReconciler::class)->run($this->carrier([[]]), new NullVoiceAgentDeployer, $desde);
        $this->assertSame([VoiceAmbiguousResolution::HELD_WINDOW => 1], $resumen['ambiguous']['held']);

        // Un evento suyo trae el SID que devolvió el proveedor: pudo salir.
        VoiceEvents::record('outbound_ambiguous', (int) $joven->id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'amb:prueba-'.$joven->uuid, [
            'outcome' => 'missing_ids', 'codes' => ['conversationId' => null, 'callSid' => self::sid(5)],
        ]);
        $this->assertSame([VoiceAmbiguousResolution::HELD_PROVIDER_IDS => 1], $this->pass()['ambiguous']['held']);
        $this->assertSame(VoiceCall::STATUS_AMBIGUOUS, VoiceCall::query()->findOrFail($joven->id)->status);
    }

    public function test_la_orden_de_consola_se_niega_sin_confirmar_sin_motivo_joven_o_no_ambigua_y_cierra_la_vieja(): void
    {
        $fila = $this->ambiguousCall();
        $motivo = 'Comprobado en la consola de Twilio: ninguna llamada a esa hora';
        $orden = static fn (array $o): int => Artisan::call('voice:resolve-ambiguous', $o);

        $this->assertSame(1, $orden(['call' => $fila->uuid, '--reason' => $motivo]), 'sin --confirm');
        foreach ([null, 'corto', str_repeat('a', 201), 'Llamé al 300 123 4567 y no sonó', 'Lo dijo ana@ejemplo.co por correo', "Comprobado\x07 sin llamadas"] as $malo) {
            $this->assertSame(1, $orden(['call' => $fila->uuid, '--confirm' => true] + ($malo === null ? [] : ['--reason' => $malo])), 'motivo sin forma: '.var_export($malo, true));
        }
        $this->assertSame(1, $orden(['call' => 'no-es-un-uuid', '--confirm' => true, '--reason' => $motivo]));
        $this->assertSame(1, $orden(['call' => $fila->uuid, '--confirm' => true, '--reason' => $motivo]), 'más joven que el plazo de gracia');
        $this->assertStringContainsString('plazo de gracia', Artisan::output());

        $this->assertSame(VoiceCall::STATUS_AMBIGUOUS, VoiceCall::query()->findOrFail($fila->id)->status);
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'reconciled')->count(), 'ningún evento mientras se niega');

        // Pasado el plazo, con --confirm y un motivo con forma: la cierra (E19-3), con su evento, una sola vez.
        $this->travel(VoiceAmbiguousResolution::GRACE_MINUTES + 1)->minutes();
        $codigo = $orden(['call' => $fila->uuid, '--confirm' => true, '--reason' => $motivo]);
        $salida = Artisan::output();   // fetch() vacía el búfer: se lee una vez
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('ambiguous → failed_to_start', $salida);
        $cerrada = VoiceCall::query()->findOrFail($fila->id);
        $this->assertSame(VoiceCall::STATUS_FAILED_TO_START, $cerrada->status);
        $this->assertNotNull($cerrada->ended_at);
        $evento = VoiceCallEvent::query()->where('call_id', $fila->id)->where('kind', 'reconciled')->sole();
        $this->assertSame(['ambiguous_resolved', 'manual:'.$fila->uuid, $motivo, 'ambiguous', 'failed_to_start'],
            [$evento->outcome, $evento->provider_event_key, $evento->codes['reason'], $evento->codes['before'], $evento->codes['after']]);
        $this->assertSame(1, $orden(['call' => $fila->uuid, '--confirm' => true, '--reason' => $motivo]), 'otra vez: ya no es ambiguous');
        $this->assertStringContainsString('No es una saliente ambiguous', Artisan::output());
        $this->assertSame(1, VoiceCallEvent::query()->where('kind', 'reconciled')->count());

        $otra = $this->voiceCall('outbound', 'completed', ['provider_call_sid' => self::sid(3), 'remote' => '+573000000082']);
        $this->assertSame(1, $orden(['call' => $otra->uuid, '--confirm' => true, '--reason' => $motivo]));
        $this->assertStringContainsString('No es una saliente ambiguous', Artisan::output());
        $this->assertSame(['voice:resolve-ambiguous'], array_values(array_filter(array_keys(Artisan::all()), static fn (string $c): bool => $c === 'voice:resolve-ambiguous')));
    }

    public function test_el_motivo_de_la_consola_no_admite_telefonos_correos_ni_control(): void
    {
        $this->assertSame('Sin llamadas en Twilio entre las 10:00 y las 11:30', VoiceResolveAmbiguousCommand::reason('  Sin llamadas en Twilio entre las 10:00 y las 11:30  '));
        foreach (['3001234567 no contestó', '+57 300 123 4567', 'escribir a soporte@x.co', "tab\u{200B}oculto en el texto"] as $malo) {
            $this->assertNull(VoiceResolveAmbiguousCommand::reason($malo), $malo);
        }
    }

    public function test_una_post_llamada_de_una_fila_cerrada_asi_no_la_resucita_y_queda_como_anomalia(): void
    {
        $fila = $this->resolvedAsUnstarted('+573000000083');
        $cuerpo = $this->postCallBody($fila, 'conv_h8fx_0951');
        $r = $this->signed($cuerpo)->assertOk()->assertJson(['ok' => true, 'status' => 'failed_to_start', 'anomaly' => 'resolved_call_reappeared']);
        $this->assertSame(0, $r->json('violations'));

        $despues = VoiceCall::query()->findOrFail($fila->id);
        $this->assertSame([VoiceCall::STATUS_FAILED_TO_START, null], [$despues->status, $despues->provider_conversation_id], 'no se resucita ni se le atan ids');
        $this->assertSame(['reconciled', 'post_call_transcription', 'reconciled', 'guard_verdict'], $this->events($fila), 'la salida, la post-llamada (como en una fila final), la anomalía y la guarda');
        $this->assertTrue(VoiceCallEvent::query()->where('call_id', $fila->id)->where('outcome', 'resolved_call_reappeared')->exists());
        $freno = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['unknown_provider_call', (int) $fila->id], [$freno->reason, (int) $freno->call_id], 'el freno, como una llamada que Laravel no controlaba (DF-E5)');
        $this->assertSame('reappeared:'.$fila->uuid, $freno->open_key);

        // La misma post-llamada otra vez: duplicada, nada nuevo.
        $this->signed($cuerpo)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, VoiceBrakeEntry::query()->count());

        // Un fallo de iniciación de otra fila cerrada así: la misma anomalía.
        $otra = $this->resolvedAsUnstarted('+573000000084');
        $fallo = (string) json_encode(['type' => 'call_initiation_failure', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => 'conv_h8fx_0952', 'user_id' => $otra->call_ref, 'failure_reason' => 'no-answer', 'metadata' => ['type' => 'twilio'],
        ]]);
        $this->signed($fallo)->assertOk()->assertJson(['status' => 'failed_to_start', 'anomaly' => 'resolved_call_reappeared']);
        $this->assertSame(VoiceCall::STATUS_FAILED_TO_START, VoiceCall::query()->findOrFail($otra->id)->status);
        $this->assertSame(2, VoiceBrakeEntry::query()->count());
    }

    public function test_una_rechazada_de_siempre_no_es_una_anomalia(): void
    {
        $rechazada = $this->voiceCall('outbound', 'failed_to_start', ['remote' => '+573000000085']);
        $this->assertFalse(VoiceAmbiguousResolution::wasResolved(VoiceCall::query()->findOrFail($rechazada->id)));
        $r = $this->signed($this->postCallBody($rechazada, 'conv_h8fx_0953'))->assertOk();
        $this->assertArrayNotHasKey('anomaly', $r->json());
        $this->assertSame(0, VoiceBrakeEntry::query()->count(), 'el camino de siempre de una fila final: sin freno');
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    private function ambiguousCall(string $remoto = '+573000000080'): VoiceCall
    {
        return $this->voiceCall('outbound', 'ambiguous', ['remote' => $remoto]);
    }

    /**
     * Una saliente cerrada como «no llegó a iniciarse», tal como la deja la salida de E19-3: de `ambiguous` a
     * `failed_to_start` (la transición real que admite la migración 2026_11_22_100002) y su `reconciled`
     * (`ambiguous_resolved`).
     */
    private function resolvedAsUnstarted(string $remoto): VoiceCall
    {
        $fila = $this->ambiguousCall($remoto);
        $this->assertTrue(VoiceCall::transition((int) $fila->id, [VoiceCall::STATUS_AMBIGUOUS], VoiceAmbiguousResolution::FINAL, VoiceAmbiguousResolution::transitionValues()));
        VoiceEvents::record('reconciled', (int) $fila->id, VoiceCallEvent::PROVIDER_LARAVEL, 'unstarted:'.$fila->uuid, [
            'outcome' => VoiceAmbiguousResolution::OUTCOME, 'codes' => ['before' => 'ambiguous', 'after' => 'failed_to_start'],
        ]);
        $fila = VoiceCall::query()->findOrFail($fila->id);
        $this->assertTrue(VoiceAmbiguousResolution::wasResolved($fila));

        return $fila;
    }

    private function postCallBody(VoiceCall $fila, string $conversacion): string
    {
        return (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => $conversacion, 'user_id' => $fila->call_ref, 'status' => 'done',
            'transcript' => [['role' => 'agent', 'message' => 'Hola, te habla el asistente virtual de Iron Body, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.']],
            'metadata' => ['call_duration_secs' => 20],
        ]], JSON_UNESCAPED_UNICODE);
    }

    private function signed(string $cuerpo): TestResponse
    {
        return $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)]);
    }

    /** @return array<string, mixed> una pasada con una lista completa y vacía */
    private function pass(): array
    {
        return app(VoiceReconciler::class)->run($this->carrier([[]]), new NullVoiceAgentDeployer, now()->subHours(48));
    }

    /**
     * Un operador falso: cada elemento es una página (una lista de llamadas, la lista y `truncated`, o un resultado
     * de error); entre páginas, una continuación. Nunca cuelga.
     *
     * @param  list<list<CarrierCall>|array{0: list<CarrierCall>, 1: 'truncated'}|string>  $paginas
     */
    private function carrier(array $paginas): TelephonyCarrier
    {
        return new class($paginas) implements TelephonyCarrier
        {
            /** @param  list<mixed>  $paginas */
            public function __construct(private readonly array $paginas) {}

            public function hangup(string $callSid): CarrierResult
            {
                throw new LogicException('la conciliación no cuelga');
            }

            public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
            {
                $n = $pageToken === null ? 0 : (int) explode(':', $pageToken)[0];
                $p = $this->paginas[$n];
                if (is_string($p)) {
                    return new CarrierCallsPage($p, [], null, false, 503);
                }
                $truncada = ($p[1] ?? null) === 'truncated';
                $llamadas = $truncada ? $p[0] : $p;
                $sigue = ! $truncada && isset($this->paginas[$n + 1]) ? ($n + 1).':pagina' : null;

                return new CarrierCallsPage(CarrierCallsPage::OUTCOME_LISTED, $llamadas, $sigue, $truncada, 200);
            }
        };
    }

    /** @return list<string> los tipos de los eventos de la fila, en orden */
    private function events(VoiceCall $fila): array
    {
        return VoiceCallEvent::query()->where('call_id', $fila->id)->orderBy('id')->pluck('kind')->all();
    }

    private static function sid(int $n): string
    {
        return 'CA'.str_pad(dechex(0xA9B0 + $n), 32, '0', STR_PAD_LEFT);
    }

    private static function carrierCall(int $n): CarrierCall
    {
        return new CarrierCall(self::sid($n), null, 'completed', 'outbound-api', '2026-11-23T14:10:00Z', '2026-11-23T14:12:00Z', 120, '-0.07540', 'USD', '0001', '0090');
    }
}
