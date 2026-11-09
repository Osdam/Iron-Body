<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\TelephonyCarrier;
use App\Services\Voice\VoiceAdapters;
use DateTimeInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E12-5 (B8-e; revisión automática de `bff58d8`, MEDIO, «fail-open state
 * drift»): GANA EL CORTE y además FALLA CERRADO. Antes, si el colgado inmediato
 * de una saliente cortada que el proveedor inició igual no surtía efecto
 * (Twilio caído, un error, el proceso que muere), la llamada seguía hasta su
 * máximo (≤ 300 s) sin freno, y la conciliación, que corre cada 10 minutos,
 * la veía ya terminada y la daba por CONOCIDA, en silencio. Ahora:
 *  - el colgado que no se confirma echa el freno EN EL ACTO;
 *  - la conciliación solo la reconoce con su último colgado confirmado
 *    (`hung_up` o `not_found`); si no, desconocida y freno, sin duplicarlo;
 *  - si Twilio la enseña viva, la conciliación vuelve a colgarla FUERA de su
 *    transacción y frena si falla (rearmando el freno ya soltado);
 *  - el job que muere a mitad del colgado echa el freno desde `failed()`;
 *  - si el job marca primero (`hung_up` por la vía de siempre), nada cambia.
 * Con el transportista real (Twilio sobre `Http::fake`), salvo el adaptador
 * que lanza (un doble del transportista).
 */
class PlaceOutboundVoiceCallHangupFailClosedTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    private const SIM = 'http://127.0.0.1:8095';

    private Admin $pablo;

    /** @var list<int> el nivel de transacción en que se pidió a Twilio cada colgado (1 = el de RefreshDatabase: fuera de toda transacción de la aplicación) */
    private array $niveles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // En UTC: con una hora de prueba en otra zona, Carbon leería las fechas de la base en esa zona (§B8-d, 5).
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        $this->voiceWorld([
            'voice.twilio.account_sid' => self::ACCOUNT,
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 31).'1',
            'voice.twilio.api_key_secret' => 'secreto-de-la-key-de-prueba',
            'voice.twilio.base_url' => self::SIM,
            'voice.limits.daily_budget_usd' => '1000',
        ]);
        $this->pablo = $this->person(Admin::ROLE_SUPER_ADMIN, 'Pablo Corta');
    }

    /**
     * Twilio simulado. Cada colgado responde `$colgar` (un estado HTTP; 0 = sin
     * conexión) y apunta su nivel de transacción; la lista de la subcuenta
     * enseña `$lista` (SID → [estado, precio]).
     *
     * @param  array<string, array{0: string, 1: ?string}>  $lista
     */
    private function twilio(int $colgar, array $lista = []): void
    {
        $pagina = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0]['page'];
        $modelo = $pagina['calls'][0];
        $pagina['calls'] = [];
        foreach ($lista as $sid => [$estado, $precio]) {
            $pagina['calls'][] = array_merge($modelo, ['sid' => $sid, 'status' => $estado, 'price' => $precio, 'duration' => $estado === 'completed' ? '300' : null]);
        }
        $pagina['end'] = count($pagina['calls']);
        $this->niveles = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $calls = self::SIM.'/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls';
        Http::fake(function (Request $r) use ($colgar, $pagina, $calls) {
            if ($r->method() === 'GET' && str_starts_with($r->url(), $calls.'.json')) {
                return Http::response($pagina);
            }
            if ($r->method() === 'POST' && preg_match('#^'.preg_quote($calls, '#').'/CA[0-9a-f]{32}\.json$#D', $r->url()) === 1 && $r['Status'] === 'completed') {
                $this->niveles[] = DB::transactionLevel();

                return match ($colgar) {
                    0 => Create::rejectionFor(new ConnectException('sin red', new Psr7Request('POST', $r->url()))),
                    200 => Http::response(['status' => 'completed'], 200),
                    400 => Http::response(['code' => 21220, 'message' => 'Call is not in-progress'], 400),
                    default => Http::response(['code' => 20500], $colgar),
                };
            }

            return null;   // lo demás, petición perdida (`preventStrayRequests`)
        });
    }

    private function record(VoiceCall $llamada, OutboundCallResult $resultado): string
    {
        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, $resultado);
    }

    /** La persona corta la saliente mientras el job espera al proveedor, y este la inicia igual con `$sid` (el job la cuelga al volver). */
    private function cortadaIniciada(string $sid, string $remoto): VoiceCall
    {
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => $remoto]);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);
        $this->assertSame('canceled', $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_b8e_'.substr($sid, -4), $sid, 200)));

        return VoiceCall::query()->findOrFail($llamada->id);
    }

    /**
     * Lo que deja un job que murió entre guardar la respuesta del proveedor y
     * colgar: la fila cortada y el SID en su `outbound_started` (lo que escribe
     * `record()`), sin ningún colgado ni freno.
     */
    private function cortadaSinColgar(string $sid, string $remoto): VoiceCall
    {
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => $remoto]);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);
        $conversacion = 'conv_h8fx_b8e_'.substr($sid, -4);
        VoiceEvents::record('outbound_started', (int) $llamada->id, VoiceCallEvent::PROVIDER_ELEVENLABS, $conversacion, ['outcome' => 'started',
            'codes' => ['reason' => null, 'http' => 200, 'conversationId' => $conversacion, 'callSid' => $sid, 'afterCancel' => true]]);

        return VoiceCall::query()->findOrFail($llamada->id);
    }

    /** @return array<string, mixed> */
    private function reconcile(): array
    {
        return app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48));
    }

    private static function systemHangup(VoiceCall $llamada): VoiceCallEvent
    {
        return VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->where('actor_type', VoiceCallEvent::ACTOR_SYSTEM)->sole();
    }

    private static function incidents(): int
    {
        return (int) Incident::query()->where('source', 'voice')->where('kind', 'voice_brake')->sum('occurrences');
    }

    public function test_el_colgado_que_falla_echa_el_freno_en_el_acto_y_la_conciliacion_no_la_reconoce(): void
    {
        $sid = 'CA000000000000000000000000a8e60001';
        $this->twilio(400, [$sid => ['completed', '-0.03770']]);
        $llamada = $this->cortadaIniciada($sid, '+573000000061');

        // El freno EN EL ACTO, antes de cualquier conciliación (antes de B8-e, ninguno hasta ella).
        $this->assertTrue(VoiceBrake::isOpen(), 'el colgado no se confirmó: freno en el acto');

        // El colgado, una vez y FUERA de la transacción de record().
        $this->assertSame([1], $this->niveles);
        $corte = self::systemHangup($llamada);
        $this->assertSame('failed', $corte->outcome);
        $this->assertSame(['carrierStatus' => null, 'http' => 400, 'errorCode' => 21220, 'afterCancel' => true, 'callSid' => $sid, 'carrierOutcome' => 'rejected'], $corte->codes);

        // Una apertura, con la clave de la conciliación, la causa en la evidencia y su incidente.
        $apertura = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['open', 'unknown_provider_call', 'unknown:'.$sid, (int) $llamada->id, (int) $corte->id],
            [$apertura->kind, $apertura->reason, $apertura->open_key, $apertura->call_id, $apertura->event_id]);
        $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'outbound_job', 'outcome' => 'failed', 'http' => 400], $apertura->evidence);
        $this->assertSame(1, self::incidents());

        // Twilio ya la da por terminada (pudo durar su máximo): desconocida, el freno no se duplica, y su precio en su fila.
        $r = $this->reconcile();
        $this->assertSame([0, 1], [$r['known'], $r['unknown']]);
        $this->assertSame([1, 1], [VoiceBrakeEntry::query()->count(), self::incidents()]);
        $this->assertSame(1, VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('provider_event_key', $sid)->count());
        $this->assertSame(0, VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'reconciled')->count(), 'no se da por conocida');
        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['canceled', 37700], [$fila->status, $fila->carrier_cost_micros]);
        $this->assertSame(37700, VoiceCostGuard::committedMicros(VoiceCostGuard::budgetDay(now())), 'lo que salió de verdad compromete gasto');
        $this->assertSame([1], $this->niveles, 'terminada: no se vuelve a colgar');

        // Otra pasada: lo mismo, sin duplicar nada; y el comando sale con 1 (una desconocida).
        $this->assertSame([0, 1], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame([1, 1, 1], [VoiceBrakeEntry::query()->count(), VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'cost_recorded')->count(), self::incidents()]);
        $this->assertSame(1, Artisan::call('voice:reconcile', ['--json' => true]));
    }

    public function test_la_conciliacion_no_da_por_conocida_una_cortada_ya_terminada_sin_colgado_confirmado(): void
    {
        // El hallazgo tal cual: la conciliación corre cada 10 minutos, más que el máximo de una llamada (300 s), así que
        // la ve YA terminada. Una, sin ningún colgado (el proceso murió antes); otra, con su colgado fallido y sin freno.
        $sinColgar = 'CA000000000000000000000000a8e61001';
        $fallido = 'CA000000000000000000000000a8e61002';
        $a = $this->cortadaSinColgar($sinColgar, '+573000001001');
        $b = $this->cortadaSinColgar($fallido, '+573000001002');
        VoiceEvents::record('hangup_requested', (int) $b->id, VoiceCallEvent::PROVIDER_LARAVEL, null, ['actor_type' => VoiceCallEvent::ACTOR_SYSTEM, 'outcome' => 'failed',
            'codes' => ['carrierStatus' => null, 'http' => 500, 'errorCode' => null, 'afterCancel' => true, 'callSid' => $fallido, 'carrierOutcome' => 'error']]);
        $this->twilio(200, [$sinColgar => ['completed', '-0.03770'], $fallido => ['completed', '-0.03770']]);

        $r = $this->reconcile();
        $this->assertSame([0, 2], [$r['known'], $r['unknown']], 'ninguna se da por conocida (antes de B8-e: las dos, en silencio)');
        $this->assertTrue(VoiceBrake::isOpen());
        $this->assertSame([], $this->niveles, 'terminadas: no se cuelgan');
        foreach ([[$a, $sinColgar, null], [$b, $fallido, 'failed']] as [$llamada, $sid, $colgado]) {
            $apertura = VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$sid)->sole();
            $desconocida = VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->where('provider_event_key', $sid)->sole();
            $this->assertSame(['unknown_provider_call', (int) $llamada->id, (int) $desconocida->id], [$apertura->reason, $apertura->call_id, $apertura->event_id]);
            $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'reconcile', 'carrierStatus' => 'completed', 'outcome' => $colgado], $apertura->evidence);
            $this->assertSame(37700, VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros, 'su precio, en su fila');
        }
        $this->assertSame([2, 2], [VoiceBrakeEntry::query()->count(), self::incidents()]);

        // Otra pasada: nada nuevo.
        $this->reconcile();
        $this->assertSame([2, 2], [VoiceBrakeEntry::query()->count(), VoiceCallEvent::query()->where('kind', 'cost_recorded')->count()]);
    }

    public function test_cada_resultado_del_colgado_frena_o_no_segun_confirme_el_corte(): void
    {
        // [estado HTTP del colgado (0 = sin conexión), outcome del evento, lo que dijo el transportista, ¿freno?]
        $casos = [
            [500, 'ambiguous', 'error', true],
            [0, 'ambiguous', 'unreachable', true],
            [400, 'failed', 'rejected', true],
            [401, 'failed', 'auth_failed', true],
            [403, 'failed', 'auth_failed', true],
            [404, 'not_found', 'not_found', false],
            [200, 'hung_up', 'requested', false],
        ];
        $frenos = 0;
        foreach ($casos as $i => [$colgar, $outcome, $transportista, $frena]) {
            $sid = sprintf('CA000000000000000000000000a8e6%04d', 100 + $i);
            $this->twilio($colgar);
            $llamada = $this->cortadaIniciada($sid, sprintf('+5730000001%02d', $i));
            $apertura = VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$sid)->first();
            $this->assertSame($frena, $apertura !== null, "colgado {$colgar}: ¿freno en el acto?");
            $corte = self::systemHangup($llamada);
            $this->assertSame([$outcome, $transportista, $sid], [$corte->outcome, $corte->codes['carrierOutcome'], $corte->codes['callSid']], "colgado {$colgar}");
            $this->assertSame([1], $this->niveles, "colgado {$colgar}: una vez, fuera de la transacción");
            if ($frena) {
                $frenos++;
                $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'outbound_job', 'outcome' => $outcome, 'http' => $colgar === 0 ? null : $colgar], $apertura->evidence);
            }
        }
        $this->assertSame([5, 5], [VoiceBrakeEntry::query()->count(), $frenos]);

        // Sin la credencial completa no hay con qué colgar (sin red): `failed`, y freno.
        config(['voice.twilio.api_key_secret' => '']);
        $this->twilio(200);
        $llamada = $this->cortadaIniciada('CA000000000000000000000000a8e60199', '+573000000199');
        $this->assertSame([], $this->niveles, 'sin red');
        $this->assertSame(['failed', 'not_configured'], [self::systemHangup($llamada)->outcome, self::systemHangup($llamada)->codes['carrierOutcome']]);
        $this->assertSame(1, VoiceBrakeEntry::query()->where('open_key', 'unknown:CA000000000000000000000000a8e60199')->count());
    }

    public function test_el_adaptador_que_lanza_no_se_traga_echa_el_freno_y_no_filtra_el_mensaje(): void
    {
        $sid = 'CA000000000000000000000000a8e60201';
        $this->twilio(200);
        $llamada = $this->cortadaSinColgar($sid, '+573000000201');
        $lanza = new class implements TelephonyCarrier
        {
            public function hangup(string $callSid): CarrierResult
            {
                throw new RuntimeException('fallo interno con sk_secreto_que_no_debe_salir');
            }

            public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
            {
                throw new RuntimeException('no se usa');
            }
        };

        $this->assertSame('ambiguous', VoiceHangup::startedAfterCancel($llamada, $sid, $lanza), 'no se sabe si llegó: ambiguo, sin relanzar');
        $corte = self::systemHangup($llamada);
        $this->assertSame(['ambiguous', null, null, RuntimeException::class], [$corte->outcome, $corte->codes['carrierOutcome'], $corte->codes['http'], $corte->codes['error_class']]);
        $this->assertStringNotContainsString('sk_secreto', (string) json_encode($corte->codes));
        $apertura = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['unknown:'.$sid, ['trigger' => 'hangup_failed', 'where' => 'outbound_job', 'outcome' => 'ambiguous', 'http' => null]], [$apertura->open_key, $apertura->evidence]);
        $this->assertStringNotContainsString('sk_secreto', (string) json_encode($apertura->evidence));

        // La petición perdida de una prueba (solo existe con `preventStrayRequests`) sí se relanza, como en el adaptador: que se vea.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $otra = $this->cortadaSinColgar('CA000000000000000000000000a8e60202', '+573000000202');
        try {
            VoiceHangup::startedAfterCancel($otra, 'CA000000000000000000000000a8e60202', new TwilioVoiceCarrier);
            $this->fail('la petición perdida tenía que verse');
        } catch (StrayRequestException) {
            $this->assertSame(0, VoiceCallEvent::query()->where('call_id', $otra->id)->where('kind', 'hangup_requested')->where('actor_type', 'system')->count());
        }
    }

    public function test_el_colgado_confirmado_o_la_llamada_que_ya_no_existe_sin_freno_y_la_conciliacion_la_reconoce(): void
    {
        $colgada = 'CA000000000000000000000000a8e60301';
        $inexistente = 'CA000000000000000000000000a8e60302';
        $this->twilio(200);
        $a = $this->cortadaIniciada($colgada, '+573000000301');
        $this->twilio(404);
        $b = $this->cortadaIniciada($inexistente, '+573000000302');
        $this->assertSame(['hung_up', 'not_found'], [self::systemHangup($a)->outcome, self::systemHangup($b)->outcome]);
        $this->assertFalse(VoiceBrake::isOpen());

        $this->twilio(200, [$colgada => ['completed', '-0.03770'], $inexistente => ['canceled', '-0.00000']]);
        $r = $this->reconcile();
        $this->assertSame([2, 0], [$r['known'], $r['unknown']]);
        $this->assertSame([], $this->niveles, 'terminadas: nada que colgar');
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->count());
        foreach ([$a, $b] as $llamada) {
            $this->assertSame(1, VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'reconciled')->count());
        }
        $this->assertSame(37700, VoiceCall::query()->findOrFail($a->id)->carrier_cost_micros);
    }

    public function test_la_conciliacion_que_la_ve_viva_vuelve_a_colgarla_fuera_de_su_transaccion_y_frena_si_falla(): void
    {
        $sid = 'CA000000000000000000000000a8e60401';
        $llamada = $this->cortadaSinColgar($sid, '+573000000401');   // el job murió antes de colgar: ni colgado ni freno
        $this->assertFalse(VoiceBrake::isOpen());

        // Viva en Twilio y el colgado falla (500): un intento, FUERA de la transacción de la llamada, y freno.
        $this->twilio(500, [$sid => ['in-progress', null]]);
        $r = $this->reconcile();
        $this->assertSame([0, 1], [$r['known'], $r['unknown']]);
        $this->assertSame([1], $this->niveles);
        $corte = self::systemHangup($llamada);
        $this->assertSame(['ambiguous', 'reconcile', $sid], [$corte->outcome, $corte->codes['where'], $corte->codes['callSid']]);
        $apertura = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['unknown_provider_call', 'unknown:'.$sid, (int) $llamada->id, (int) $corte->id], [$apertura->reason, $apertura->open_key, $apertura->call_id, $apertura->event_id]);
        $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'reconcile', 'outcome' => 'ambiguous', 'http' => 500], $apertura->evidence);

        // Otra pasada, sigue viva y vuelve a fallar: otro intento; el freno no se duplica.
        $this->reconcile();
        $this->assertSame([[1, 1], 1, 1], [$this->niveles, VoiceBrakeEntry::query()->count(), self::incidents()]);

        // Una persona lo suelta; después sigue viva y el colgado vuelve a fallar: evidencia nueva → se rearma (r2).
        VoiceBrake::release($this->pablo, (string) $apertura->uuid, 'Revisada en la consola de Twilio', (string) Str::uuid());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->travel(10)->seconds();
        $this->reconcile();
        $this->assertTrue(VoiceBrake::isOpen());
        $this->assertSame(['unknown:'.$sid, 'unknown:'.$sid.':r2'], VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderBy('id')->pluck('open_key')->all());
        $this->assertSame((string) $apertura->uuid, VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$sid.':r2')->sole()->evidence['rearmOf']);
        $this->assertSame(2, self::incidents());

        // Termina sin un colgado confirmado: desconocida, ninguna apertura más, sin colgar, y su precio en su fila.
        $this->twilio(200, [$sid => ['completed', '-0.03770']]);
        $this->assertSame([0, 1], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame([[], 2, 37700], [$this->niveles, VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->count(), VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros]);
    }

    public function test_el_colgado_fallido_del_job_rearma_en_el_acto_el_freno_ya_soltado_de_esa_llamada(): void
    {
        // La conciliación vio la llamada ANTES de que el job guardara la respuesta del proveedor (ninguna fila ni evento
        // con su SID): desconocida y freno `unknown:<sid>`, que una persona suelta. Después vuelve el job, la fila ya
        // estaba cortada y el colgado inmediato falla: evidencia nueva, posterior a la suelta → se rearma en el acto.
        $sid = 'CA000000000000000000000000a8e61101';
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000001101']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);
        $this->twilio(500, [$sid => ['in-progress', null]]);
        $this->assertSame([0, 1, []], [($r = $this->reconcile())['known'], $r['unknown'], $this->niveles], 'sin su evento todavía: desconocida, y nada que colgar');
        $primera = VoiceBrakeEntry::query()->sole();
        $this->assertSame('unknown:'.$sid, $primera->open_key);
        VoiceBrake::release($this->pablo, (string) $primera->uuid, 'Revisada en la consola de Twilio', (string) Str::uuid());
        $this->travel(10)->seconds();

        $this->assertSame('canceled', $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_b8e_1101', $sid, 200)));
        $this->assertTrue(VoiceBrake::isOpen(), 'rearmado en el acto, sin esperar a la conciliación');
        $this->assertSame(['unknown:'.$sid, 'unknown:'.$sid.':r2'], VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderBy('id')->pluck('open_key')->all());
        $segunda = VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$sid.':r2')->sole();
        $this->assertSame([(string) $primera->uuid, 'outbound_job', 'ambiguous', 'hangup_failed'],
            [$segunda->evidence['rearmOf'], $segunda->evidence['where'], $segunda->evidence['outcome'], $segunda->evidence['trigger']]);
    }

    public function test_la_conciliacion_que_la_ve_viva_y_logra_colgarla_la_reconoce_sin_freno_nuevo(): void
    {
        // El colgado del job falló (freno en el acto); la conciliación la ve viva y esta vez cuelga.
        $sid = 'CA000000000000000000000000a8e60501';
        $this->twilio(500);
        $llamada = $this->cortadaIniciada($sid, '+573000000501');
        $this->assertSame(1, VoiceBrakeEntry::query()->count());

        $this->twilio(200, [$sid => ['in-progress', null]]);
        $r = $this->reconcile();
        $this->assertSame([1, 0, [1]], [$r['known'], $r['unknown'], $this->niveles]);
        $this->assertSame(['ambiguous', 'hung_up'], VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')
            ->where('actor_type', VoiceCallEvent::ACTOR_SYSTEM)->orderBy('id')->pluck('outcome')->all(), 'manda el último');
        $this->assertSame(1, VoiceBrakeEntry::query()->count(), 'ni una apertura más; la del job sigue hasta que la suelten');

        // Ya terminada, conocida: su `reconciled` y su precio en la fila cortada; ninguna desconocida.
        $this->twilio(200, [$sid => ['completed', '-0.03770']]);
        $this->assertSame([1, 0], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame([1, 37700, 0], [
            VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'reconciled')->count(),
            VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros,
            VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->count(),
        ]);

        // El colgado del job se confirmó pero Twilio aún la enseña viva: se cuelga otra vez y, confirmado, sin freno.
        $otro = 'CA000000000000000000000000a8e60502';
        $this->twilio(200);
        $segunda = $this->cortadaIniciada($otro, '+573000000502');
        $this->twilio(200, [$otro => ['ringing', null]]);
        $this->assertSame([1, 0, [1]], [($r = $this->reconcile())['known'], $r['unknown'], $this->niveles]);
        $this->assertSame(0, VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$otro)->count());
        $this->assertSame(['hung_up', 'hung_up'], VoiceCallEvent::query()->where('call_id', $segunda->id)->where('kind', 'hangup_requested')
            ->where('actor_type', VoiceCallEvent::ACTOR_SYSTEM)->orderBy('id')->pluck('outcome')->all());
    }

    public function test_si_el_job_marca_primero_nada_de_esto_cambia(): void
    {
        $sid = 'CA000000000000000000000000a8e60601';
        $this->twilio(200, [$sid => ['completed', '-0.03770']]);
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000601']);
        $leida = VoiceCall::query()->findOrFail($llamada->id);   // lo que leyó el CRM: `requested`

        $this->assertSame('dialing', $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_b8e_0601', $sid, 200)));
        $this->assertSame([], $this->niveles, 'sin corte, el job no cuelga nada');
        $corte = VoiceHangup::hangup($leida, VoiceAdapters::carrier(), $this->pablo);
        $this->assertSame(['hung_up', 'requested', 'hung_up'], [$corte['result'], $corte['carrier'], $corte['status']]);
        $this->assertSame(['requested'], VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->pluck('outcome')->all(), 'la vía de siempre: el resultado del transportista');
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame([1, 0], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame(['hung_up', 37700], [VoiceCall::query()->findOrFail($llamada->id)->status, VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros]);
        $this->assertFalse(VoiceBrake::isOpen());

        // Y si Twilio no deja cortarla por la vía de siempre: `error`, la fila sigue viva, sin freno y sin reintentos de la conciliación.
        $viva = 'CA000000000000000000000000a8e60602';
        $this->twilio(500, [$viva => ['in-progress', null]]);
        $otra = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000602']);
        $leida = VoiceCall::query()->findOrFail($otra->id);
        $this->assertSame('dialing', $this->record($otra, new OutboundCallResult('started', null, 'conv_h8fx_b8e_0602', $viva, 200)));
        $corte = VoiceHangup::hangup($leida, VoiceAdapters::carrier(), $this->pablo);
        $this->assertSame(['error', 'error', 'dialing'], [$corte['result'], $corte['carrier'], $corte['status']]);
        $this->assertSame([1, 0], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame([1], $this->niveles, 'solo el corte de la persona: la conciliación no cuelga una llamada con su fila');
        $this->assertFalse(VoiceBrake::isOpen());
    }

    public function test_el_job_que_muere_con_una_cortada_sin_colgar_echa_el_freno_desde_failed(): void
    {
        $sid = 'CA000000000000000000000000a8e60701';
        $llamada = $this->cortadaSinColgar($sid, '+573000000701');
        (new PlaceOutboundVoiceCall((int) $llamada->id))->failed(new RuntimeException('se agotó el plazo'));

        $apertura = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['unknown_provider_call', 'unknown:'.$sid, (int) $llamada->id, null], [$apertura->reason, $apertura->open_key, $apertura->call_id, $apertura->event_id]);
        $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'outbound_job', 'outcome' => 'job_failed', 'http' => null], $apertura->evidence);
        $this->assertSame([1, 'canceled'], [self::incidents(), VoiceCall::query()->findOrFail($llamada->id)->status], 'la fila no cambia');
        (new PlaceOutboundVoiceCall((int) $llamada->id))->failed(null);
        $this->assertSame(1, VoiceBrakeEntry::query()->count(), 'idempotente');

        // Con su colgado confirmado, el job que muere después no frena.
        $this->twilio(200);
        $colgada = $this->cortadaIniciada('CA000000000000000000000000a8e60702', '+573000000702');
        (new PlaceOutboundVoiceCall((int) $colgada->id))->failed(new RuntimeException('después de colgar'));
        // Una pedida que muere queda ambigua, sin freno (lo de siempre); una cortada a la que el proveedor nunca respondió, tampoco.
        $pedida = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000703']);
        (new PlaceOutboundVoiceCall((int) $pedida->id))->failed(new RuntimeException('se agotó el plazo'));
        $sinRespuesta = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000704']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($sinRespuesta->id), VoiceAdapters::carrier(), $this->pablo);
        (new PlaceOutboundVoiceCall((int) $sinRespuesta->id))->failed(new RuntimeException('se agotó el plazo'));

        $this->assertSame(1, VoiceBrakeEntry::query()->count());
        $this->assertSame('ambiguous', VoiceCall::query()->findOrFail($pedida->id)->status);
    }

    public function test_en_seco_no_se_cuelga_y_la_viva_cuenta_como_si_el_colgado_fallara(): void
    {
        $sid = 'CA000000000000000000000000a8e60801';
        $llamada = $this->cortadaSinColgar($sid, '+573000000801');
        $this->twilio(500, [$sid => ['in-progress', null]]);
        $foto = fn (): array => [VoiceCallEvent::query()->count(), VoiceBrakeEntry::query()->count(), self::incidents()];
        $antes = $foto();

        $seco = $this->dryRun();
        $this->assertSame([], $this->niveles, 'en seco no se cuelga: colgar no es una lectura');
        $this->assertSame($antes, $foto(), 'ni eventos, ni freno, ni incidentes');
        $this->assertSame([0, 1, true], [$seco['known'], $seco['unknown'], $seco['wouldOpenBrake']]);
        $this->assertSame([['reason' => 'unknown_provider_call', 'cause' => 'hangup_failed']], $seco['would']['brakes']);
        $this->assertSame(['hangup_requested' => 1, 'unknown_provider_call' => 1], $seco['would']['events']);

        // De verdad, con el colgado que falla: lo mismo.
        $r = $this->reconcile();
        $this->assertSame([[0, 1], [1]], [[$r['known'], $r['unknown']], $this->niveles]);
        $this->assertSame([1, 1, 1], [
            VoiceBrakeEntry::query()->count(),
            VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->where('actor_type', VoiceCallEvent::ACTOR_SYSTEM)->count(),
            VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->count(),
        ]);

        // Soltado y todavía viva: en seco anuncia el rearme, y de verdad se rearma (r2).
        VoiceBrake::release($this->pablo, (string) VoiceBrakeEntry::query()->sole()->uuid, 'Revisada en la consola de Twilio', (string) Str::uuid());
        $this->travel(10)->seconds();
        $this->twilio(500, [$sid => ['in-progress', null]]);
        $seco = $this->dryRun();
        $this->assertSame([true, [['reason' => 'unknown_provider_call', 'cause' => 'hangup_failed']], []], [$seco['wouldOpenBrake'], $seco['would']['brakes'], $this->niveles]);
        $this->assertSame(['hangup_requested' => 1], $seco['would']['events'], 'la desconocida ya estaba apuntada');
        $this->reconcile();
        $this->assertSame(['unknown:'.$sid, 'unknown:'.$sid.':r2'], VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderBy('id')->pluck('open_key')->all());

        // Viva con su último colgado CONFIRMADO: en seco cuenta como si el de ahora fallara (sin colgar no se sabe); de
        // verdad se cuelga, se confirma y es conocida, sin freno. La única diferencia entre las dos, en el sentido seguro.
        $otro = 'CA000000000000000000000000a8e60802';
        $this->twilio(200);
        $this->cortadaIniciada($otro, '+573000000802');
        $this->twilio(200, [$otro => ['in-progress', null]]);
        $seco = $this->dryRun();
        $this->assertSame([0, 1, true, []], [$seco['known'], $seco['unknown'], $seco['wouldOpenBrake'], $this->niveles]);
        $r = $this->reconcile();
        $this->assertSame([1, 0, [1]], [$r['known'], $r['unknown'], $this->niveles]);
        $this->assertSame(0, VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$otro)->count());
    }

    /** @return array<string, mixed> la pasada en seco con lo que habría hecho */
    private function dryRun(): array
    {
        $conciliacion = app(VoiceReconciler::class);

        return $conciliacion->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48), true) + $conciliacion->dryRunReport();
    }

    public function test_lo_que_dice_el_transportista_se_clasifica_en_un_conjunto_cerrado(): void
    {
        $esperado = [
            'requested' => 'hung_up', 'not_found' => 'not_found', 'rejected' => 'failed', 'auth_failed' => 'failed',
            'unreachable' => 'ambiguous', 'error' => 'ambiguous', 'not_configured' => 'failed', 'invalid_request' => 'failed',
        ];
        $this->assertSame(CarrierResult::OUTCOMES, array_keys($esperado), 'cada resultado del transportista, una vez');
        foreach ($esperado as $transportista => $resultado) {
            $this->assertSame($resultado, VoiceHangup::afterCancelResult($transportista), $transportista);
        }
        $this->assertSame('failed', VoiceHangup::afterCancelResult('uno_que_no_existe_hoy'), 'lo que no se conoce, en el sentido seguro');
        $this->assertSame(['hung_up', 'not_found'], VoiceHangup::AFTER_CANCEL_CONFIRMED);
        $this->assertSame('unknown_provider_call', VoiceBrake::TRIGGERS['hangup_failed']);
        $this->assertTrue(VoiceBrake::opensFor('hangup_failed'));
        $this->assertSame('unknown:CA000000000000000000000000a8e60901', VoiceHangup::brakeKey('CA000000000000000000000000a8e60901'));
    }
}
