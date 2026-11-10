<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E12-5 (pendiente de la fase 4a; B8-g): `voice:hangup` y el corte del CRM
 * sobre una saliente YA CORTADA. Si la persona cortó una `requested` mientras
 * el job esperaba al proveedor y este la inició igual, la fila queda `canceled`
 * y el SID de la llamada viva solo está en su evento (`afterCancel`). Hasta
 * B8-g, cortarla otra vez decía `not_live` sin red: si el colgado inmediato del
 * job había fallado, la llamada seguía hasta su máximo y nadie podía colgarla a
 * mano. Ahora:
 *  - por su uuid, o con `--all` si se cortó hace menos de una hora, se repite
 *    el colgado (`where: manual`), con la persona si viene del CRM;
 *  - si se confirma (`hung_up` o 404), la conciliación la reconoce;
 *  - si tampoco se confirma, freno (o rearme del ya soltado) y el comando sale con 1;
 *  - sin SID, o con el colgado ya confirmado, no hay nada que colgar (`not_live`, sin red).
 * Con el transportista real (Twilio sobre `Http::fake`).
 */
class VoiceHangupCanceledTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    private const SIM = 'http://127.0.0.1:8095';

    private const PLACE = 'telephony.call_place';

    private Admin $pablo;

    /** @var list<array{0: string, 1: int}> los colgados pedidos a Twilio: SID y nivel de transacción (1 = fuera de toda transacción de la aplicación) */
    private array $colgados = [];

    protected function setUp(): void
    {
        parent::setUp();
        // En UTC (Carbon::setTestNow): con una hora de prueba en otra zona, Carbon leería las fechas de la base en esa zona.
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
     * Twilio simulado: cada colgado responde `$colgar` (un estado HTTP; 0 = sin
     * conexión); la lista de la subcuenta enseña `$lista` (SID → [estado, precio]).
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
        $this->colgados = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $calls = self::SIM.'/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls';
        Http::fake(function (Request $r) use ($colgar, $pagina, $calls) {
            if ($r->method() === 'GET' && str_starts_with($r->url(), $calls.'.json')) {
                return Http::response($pagina);
            }
            if ($r->method() === 'POST' && preg_match('#^'.preg_quote($calls, '#').'/(CA[0-9a-f]{32})\.json$#D', $r->url(), $m) === 1 && $r['Status'] === 'completed') {
                $this->colgados[] = [$m[1], DB::transactionLevel()];

                return match ($colgar) {
                    0 => Create::rejectionFor(new ConnectException('sin red', new Psr7Request('POST', $r->url()))),
                    200 => Http::response(['status' => 'completed'], 200),
                    400 => Http::response(['code' => 21220, 'message' => 'Call is not in-progress'], 400),
                    default => Http::response(['code' => 20404], $colgar),
                };
            }

            return null;   // lo demás, petición perdida (`preventStrayRequests`)
        });
    }

    /** La persona corta la saliente mientras el job espera al proveedor, y este la inicia igual con `$sid` (el job la cuelga al volver). */
    private function cortadaIniciada(string $sid, string $remoto): VoiceCall
    {
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => $remoto]);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);
        $estado = (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record'))
            ->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, new OutboundCallResult('started', null, 'conv_h8fx_b8g_'.substr($sid, -4), $sid, 200));
        $this->assertSame('canceled', $estado);

        return VoiceCall::query()->findOrFail($llamada->id);
    }

    /** @return list<array{outcome: string, actor: string, where: ?string}> los colgados después del corte de esa llamada, en orden */
    private static function colgadosDe(VoiceCall $llamada): array
    {
        return VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->orderBy('id')->get()
            ->filter(static fn (VoiceCallEvent $e): bool => is_array($e->codes) && ($e->codes['afterCancel'] ?? false) === true)
            ->map(static fn (VoiceCallEvent $e): array => ['outcome' => (string) $e->outcome, 'actor' => (string) $e->actor_type, 'where' => $e->codes['where'] ?? null])
            ->values()->all();
    }

    /** La regresión: el colgado del job falló (21220) y `voice:hangup <uuid>` decía `not_live` sin red. Ahora la vuelve a colgar. */
    public function test_voice_hangup_vuelve_a_colgar_una_cortada_cuyo_colgado_fallo_y_la_conciliacion_la_reconoce(): void
    {
        $sid = 'CA000000000000000000000000b8a60001';
        $this->twilio(400);
        $llamada = $this->cortadaIniciada($sid, '+573000000081');
        $this->assertSame([[$sid, 1]], $this->colgados, 'el job la colgó al volver y Twilio dijo que no');
        $this->assertTrue(VoiceBrake::isOpen(), 'el colgado que no se confirma ya frena (B8-e)');

        $this->twilio(200);
        $this->assertSame(0, Artisan::call('voice:hangup', ['call' => $llamada->uuid]));
        $salida = Artisan::output();
        $this->assertStringContainsString($llamada->uuid.' · outbound → canceled (hung_up)', $salida);
        $this->assertStringNotContainsString('573000000081', $salida);
        $this->assertSame([[$sid, 1]], $this->colgados, 'un POST a Twilio, fuera de toda transacción de la aplicación');
        $this->assertSame([['outcome' => 'failed', 'actor' => 'system', 'where' => null], ['outcome' => 'hung_up', 'actor' => 'system', 'where' => 'manual']], self::colgadosDe($llamada));
        $this->assertSame('hung_up', VoiceHangup::lastAfterCancelResult((int) $llamada->id, $sid));
        $this->assertSame('canceled', VoiceCall::query()->findOrFail($llamada->id)->status, 'un final no cambia (DB8)');

        // Con su último colgado confirmado, la conciliación la reconoce: ni desconocida ni otro freno.
        $this->twilio(200, [$sid => ['completed', '-0.03770']]);
        $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subDays(2));
        $this->assertSame([1, 0], [$resumen['known'], $resumen['unknown']]);
        $this->assertSame(1, VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->count());
    }

    /** `--all`: las cortadas recientes sin colgado confirmado se vuelven a colgar; ni las confirmadas, ni las que nunca se iniciaron, ni las viejas. */
    public function test_voice_hangup_all_incluye_solo_las_cortadas_que_aun_pueden_estar_vivas(): void
    {
        $vieja = 'CA000000000000000000000000b8a60009';
        $this->travelTo(Carbon::parse('2026-11-09 13:50:00', 'UTC'));   // 70 minutos antes: fuera de la ventana de una hora
        $this->twilio(500);
        $this->cortadaIniciada($vieja, '+573000000089');
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));

        $fallida = 'CA000000000000000000000000b8a60002';
        $this->twilio(0);
        $sinConfirmar = $this->cortadaIniciada($fallida, '+573000000082');
        $confirmada = 'CA000000000000000000000000b8a60003';
        $this->twilio(200);
        $colgada = $this->cortadaIniciada($confirmada, '+573000000083');
        $soloCortada = $this->voiceCall('outbound', 'canceled', ['remote' => '+573000000084']);
        $viva = $this->voiceCall('outbound', 'dialing', ['remote' => '+573000000085', 'provider_call_sid' => 'CA000000000000000000000000b8a60004']);

        $this->assertSame([(int) $sinConfirmar->id], VoiceHangup::unconfirmedAfterCancel()->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $this->twilio(200);
        $this->assertSame(0, Artisan::call('voice:hangup', ['--all' => true, '--confirm' => true]));
        $salida = Artisan::output();
        $this->assertEqualsCanonicalizing(['CA000000000000000000000000b8a60004', $fallida], array_column($this->colgados, 0), 'la viva y la cortada sin confirmar; nada más');
        $this->assertSame([1, 1], array_column($this->colgados, 1));
        $this->assertStringContainsString($sinConfirmar->uuid.' · outbound → canceled (hung_up)', $salida);
        $this->assertStringContainsString($viva->uuid.' · outbound → hung_up (hung_up)', $salida);
        foreach ([$colgada, $soloCortada] as $fuera) {
            $this->assertStringNotContainsString($fuera->uuid, $salida);
        }
        $this->assertSame('hung_up', VoiceHangup::lastAfterCancelResult((int) $sinConfirmar->id, $fallida));
        $this->assertSame([], VoiceHangup::unconfirmedAfterCancel()->all(), 'ya no queda ninguna por colgar');
    }

    /** Si el colgado a mano tampoco se confirma: freno (rearma el ya soltado) y el comando sale con 1. */
    public function test_si_el_colgado_a_mano_tampoco_se_confirma_frena_y_sale_con_1(): void
    {
        $sid = 'CA000000000000000000000000b8a60005';
        $this->twilio(400);
        $llamada = $this->cortadaIniciada($sid, '+573000000086');
        $apertura = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->sole();
        VoiceBrake::release($this->pablo, (string) $apertura->uuid, 'Revisada en la consola de Twilio', (string) Str::uuid());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->travel(10)->seconds();

        $this->twilio(400);
        $this->assertSame(1, Artisan::call('voice:hangup', ['call' => $llamada->uuid]));
        $this->assertStringContainsString('→ canceled (failed)', Artisan::output());
        $this->assertTrue(VoiceBrake::isOpen(), 'evidencia nueva después de soltarlo: se rearma');
        $this->assertSame(['unknown:'.$sid, 'unknown:'.$sid.':r2'], VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderBy('id')->pluck('open_key')->all());
        $this->assertSame(['trigger' => 'hangup_failed', 'where' => 'manual', 'outcome' => 'failed', 'http' => 400, 'rearmOf' => (string) $apertura->uuid],
            VoiceBrakeEntry::query()->where('open_key', 'unknown:'.$sid.':r2')->sole()->evidence);
    }

    /** Desde el CRM: la persona queda en el evento, y su colgado confirmado lo reconoce la conciliación. */
    public function test_el_corte_del_crm_sobre_una_cortada_la_cuelga_con_su_persona(): void
    {
        $sid = 'CA000000000000000000000000b8a60006';
        $this->twilio(0);
        $llamada = $this->cortadaIniciada($sid, '+573000000087');
        $this->assertSame('ambiguous', VoiceHangup::lastAfterCancelResult((int) $llamada->id, $sid));

        $this->twilio(200);
        $r = $this->postJson("/api/admin/voice/calls/{$llamada->uuid}/hangup", [], $this->adminWithPermissions([self::PLACE]))->assertOk();
        $this->assertSame([true, 'hung_up', 'requested', 'canceled'], [$r->json('ok'), $r->json('result'), $r->json('carrier'), $r->json('status')]);
        $this->assertSame([$sid], array_column($this->colgados, 0));
        $evento = VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['admin', 'hung_up', 'manual'], [$evento->actor_type, $evento->outcome, $evento->codes['where']]);
        $this->assertNotNull($evento->actor_admin_id);
        $this->assertSame('hung_up', VoiceHangup::lastAfterCancelResult((int) $llamada->id, $sid), 'el colgado de una persona también cuenta');
        $this->assertStringNotContainsString('573000000087', (string) $r->getContent());
    }

    /** Sin SID del proveedor, o con el colgado ya confirmado (o 404 al repetirlo), no hay nada que colgar. */
    public function test_una_cortada_sin_llamada_viva_no_tiene_nada_que_colgar(): void
    {
        $this->twilio(200);
        $soloCortada = $this->voiceCall('outbound', 'canceled', ['remote' => '+573000000091']);
        $this->assertSame(0, Artisan::call('voice:hangup', ['call' => $soloCortada->uuid]));
        $this->assertStringContainsString('→ canceled (not_live)', Artisan::output());

        $colgada = $this->cortadaIniciada('CA000000000000000000000000b8a60007', '+573000000092');
        $this->colgados = [];
        $this->assertSame(['result' => 'not_live', 'carrier' => null, 'status' => 'canceled'], VoiceHangup::hangup($colgada, VoiceAdapters::carrier(), $this->pablo));
        $this->assertSame([], $this->colgados, 'ya confirmado: sin red');

        // Con el colgado del job fallido, Twilio dice al repetirlo que ya no existe: confirmado, sin freno nuevo.
        $this->twilio(400);
        $sid = 'CA000000000000000000000000b8a60008';
        $ida = $this->cortadaIniciada($sid, '+573000000093');
        $this->twilio(404);
        $this->assertSame(0, Artisan::call('voice:hangup', ['call' => $ida->uuid]));
        $this->assertStringContainsString('→ canceled (not_live)', Artisan::output());
        $this->assertSame('not_found', VoiceHangup::lastAfterCancelResult((int) $ida->id, $sid));
        $this->assertSame(1, VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->count(), 'solo la apertura del colgado del job');
    }
}
