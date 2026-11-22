<?php

namespace Tests\Feature\Outreach\AI9;

use App\Models\Admin;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Run\BudgetReservations;
use App\Services\Outreach\Run\Classifier\ReplyClassifierHealth;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachMonitor;
use App\Services\Outreach\Run\OutreachReadModels;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use Tests\Feature\Outreach\AI9\Support\AI9World;
use Tests\Feature\Outreach\AI9\Support\FakeReplyTransport;
use Tests\TestCase;

/**
 * AI9 · C4 de security: UN FALLO SOSTENIDO DEL PROVEEDOR SE VE. El
 * clasificador falla cerrado (las respuestas quedan como hoy), pero si la
 * clave, la cuota o el límite de peticiones fallan una y otra vez sus frenos
 * desaparecen sin que nadie lo note. Lo que se demuestra, con la pasada real
 * de W9, el monitor real y el registrador de incidentes real: la racha cuenta
 * las llamadas SEGUIDAS sin salida usable, con su última clase; una salida
 * que se lee la vuelve a 0; lo que no llama no la toca; el estado la enseña;
 * el monitor abre UN incidente (sin echar ningún freno) a partir del umbral y
 * le suma mientras sigue; nada de eso lleva el texto ni a la persona; y si la
 * caché falla, la clasificación sigue igual.
 */
class ReplyClassifierHealthTest extends TestCase
{
    use AI9World;
    use RefreshDatabase;

    private const NOW = '2026-11-17 15:00:00';

    private const TEXT = 'No me interesa, gracias';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un transporte que contesta, en orden, lo que se le da (y después «ninguna»). */
    private static function inOrder(array ...$replies): FakeReplyTransport
    {
        $cola = $replies;

        return new FakeReplyTransport(static function () use (&$cola): array {
            return array_shift($cola) ?? FakeReplyTransport::says('none', 99);
        });
    }

    /** Una respuesta de cada persona a su plantilla y la pasada de W9. @param  array<string, mixed>  $extra @return array<string, mixed> */
    private function passWith(FakeReplyTransport $transport, int $people, array $extra = []): array
    {
        $this->classifierOn($transport, $extra);
        foreach ($this->sentTemplates($people) as $e) {
            $this->inbound($e['lead'], self::TEXT, 1);
        }

        return (new WhatsappTemplateOutcomes)->run(now());
    }

    /** @return array<string, mixed> */
    private function providerFailures(): array
    {
        return (new OutreachReadModels)->status(new OutreachAccess(Admin::query()->firstOrFail()), now()->toDateTimeImmutable())['config']['classifierSetup']['providerFailures'];
    }

    public function test_la_racha_cuenta_las_llamadas_seguidas_sin_salida_usable_con_su_ultima_clase_y_el_estado_la_ensena(): void
    {
        $fuera = ['error' => null, 'output' => ['label' => 'interested', 'confidence' => 99], 'usage' => null, 'latencyMs' => 3];
        $r = $this->passWith(self::inOrder(FakeReplyTransport::fails('auth'), FakeReplyTransport::fails('rate_limited'), FakeReplyTransport::fails('se_lo_invento'), $fuera, FakeReplyTransport::fails('quota')), 5);

        $this->assertSame(['replied' => 5], $r['recorded'], 'las cinco, como hoy');
        $this->assertSame(1, $r['classifier']['discarded.provider_unknown']);
        $this->assertSame(['consecutive' => 5, 'lastClass' => 'quota', 'lastAt' => RelativeTime::format(now())], (new ReplyClassifierHealth)->read());
        $this->assertSame(['consecutive' => 5, 'lastClass' => 'quota', 'lastAt' => RelativeTime::format(now()), 'alertAfter' => 5], $this->providerFailures());

        // Lo que se guarda: tres claves de códigos y cifras; ni el texto, ni la persona, ni el trabajo.
        $guardado = Cache::get(ReplyClassifierHealth::CACHE_KEY);
        $this->assertSame(['consecutive', 'lastClass', 'lastAt'], array_keys($guardado));
        $todo = (string) json_encode($guardado);
        foreach ([self::TEXT, '3001234501', (string) DB::table('outreach_jobs')->value('uuid'), (string) DB::table('outreach_enrollments')->value('subject_key')] as $dato) {
            $this->assertStringNotContainsString($dato, $todo);
        }
    }

    public function test_una_salida_que_se_lee_la_vuelve_a_cero_aunque_se_descarte_y_conserva_la_ultima_clase(): void
    {
        $this->passWith(self::inOrder(FakeReplyTransport::fails('auth'), FakeReplyTransport::fails('timeout'), FakeReplyTransport::says('opted_out', 10)), 3);

        $estado = (new ReplyClassifierHealth)->read();
        $this->assertSame([0, 'timeout'], [$estado['consecutive'], $estado['lastClass']], 'el proveedor contestó (bajo el umbral, pero contestó): la racha se corta');
        $this->assertNotNull($estado['lastAt']);
    }

    public function test_lo_que_no_llega_a_llamar_no_toca_la_racha(): void
    {
        $salud = new ReplyClassifierHealth;
        $salud->failed('auth', now());
        $salud->failed('auth', now());
        $fake = new FakeReplyTransport(FakeReplyTransport::says('not_interested', 99));
        // El cupo del día ya está gastado: `budget_exhausted`, sin llamada.
        (new BudgetReservations)->reserve('2026-11-17', 'live', [BudgetReservations::parcel(BudgetReservations::SCOPE_CLASSIFIER, 1, 100, 1, 1_000_000)], now());

        $r = $this->passWith($fake, 1, ['outreach.classifier.daily_cap' => 1]);

        $this->assertSame(1, $r['classifier']['skipped.budget_exhausted']);
        $this->assertSame([], $fake->calls);
        $this->assertSame(2, $salud->read()['consecutive']);
    }

    public function test_el_monitor_abre_un_incidente_sin_freno_al_llegar_al_umbral_y_le_suma_mientras_sigue(): void
    {
        $this->passWith(new FakeReplyTransport(FakeReplyTransport::fails('auth')), 3, ['outreach.monitor.enabled' => true, 'outreach.monitor.classifier_failures' => 3]);
        $monitor = new OutreachMonitor;

        $r = $monitor->run(now());

        $this->assertSame([], $r['opened'], 'ningún freno: el clasificador ya falla cerrado');
        $this->assertSame(0, DB::table('outreach_brakes')->count());
        $this->assertSame(['flagged' => 1, 'consecutive' => 3, 'lastClass' => 'auth'], $r['checks']['classifier']);
        $this->assertSame(1, json_decode((string) DB::table('outreach_runs')->where('kind', 'monitor')->value('counts'), true)['checks']['classifier']);
        $incidente = DB::table('incidents')->where('source', OutreachStop::INCIDENT_SOURCE)->where('kind', OutreachMonitor::CLASSIFIER_INCIDENT_KIND)->sole();
        $this->assertSame(['medium', 'open', 1], [$incidente->severity, $incidente->status, (int) $incidente->occurrences]);
        $evidencia = json_decode((string) $incidente->evidence, true);
        $this->assertSame(['classifier_provider_failing', 3, 'auth', 3, self::MODEL], [$evidencia['reason'], $evidencia['consecutive_failures'], $evidencia['last_failure_class'], $evidencia['threshold'], $evidencia['model']]);
        $todo = json_encode(DB::table('incidents')->get()).json_encode(DB::table('incident_events')->get());
        foreach ([self::TEXT, 'interesa', '3001234501', (string) DB::table('outreach_jobs')->value('uuid')] as $dato) {
            $this->assertStringNotContainsString($dato, $todo, 'el texto y la persona nunca entran en el incidente');
        }

        // Diez minutos después, sigue: la MISMA clase de incidente suma una ocurrencia; tampoco hay freno.
        Carbon::setTestNow(now()->addMinutes(10));
        $otra = $monitor->run(now());

        $this->assertSame([], $otra['opened']);
        $this->assertSame(1, DB::table('incidents')->where('kind', OutreachMonitor::CLASSIFIER_INCIDENT_KIND)->count());
        $this->assertSame(2, (int) DB::table('incidents')->where('kind', OutreachMonitor::CLASSIFIER_INCIDENT_KIND)->value('occurrences'));
        $this->assertSame(0, DB::table('outreach_brakes')->count());
    }

    public function test_por_debajo_del_umbral_o_sin_clasificar_el_monitor_no_avisa(): void
    {
        $this->classifierOn(new FakeReplyTransport(FakeReplyTransport::says('none', 99)), ['outreach.monitor.classifier_failures' => 3]);
        $this->whatsappLive(['outreach.monitor.enabled' => true]);
        $salud = new ReplyClassifierHealth;
        $salud->failed('quota', now());
        $salud->failed('quota', now());
        $monitor = new OutreachMonitor;

        $this->assertSame(['flagged' => 0, 'consecutive' => 2, 'lastClass' => 'quota'], $monitor->run(now())['checks']['classifier'], 'dos de tres: todavía no');

        $salud->failed('quota', now());
        foreach ([
            'apagado' => ['outreach.classifier.enabled' => false],
            'sin cupo' => ['outreach.classifier.enabled' => true, 'outreach.classifier.daily_cap' => 0],
            'en sombra' => ['outreach.classifier.daily_cap' => 10, 'outreach.mode' => 'shadow'],
        ] as $caso => $config) {
            config($config);
            $this->assertSame(0, $monitor->run(now())['checks']['classifier']['flagged'], "{$caso}: no está clasificando, no avisa");
        }
        $this->assertSame(0, DB::table('incidents')->where('kind', OutreachMonitor::CLASSIFIER_INCIDENT_KIND)->count());

        config(['outreach.mode' => 'live']);
        $this->assertSame(1, $monitor->run(now())['checks']['classifier']['flagged'], 'clasificando y en el umbral: avisa');
        $this->assertSame(1, DB::table('incidents')->where('kind', OutreachMonitor::CLASSIFIER_INCIDENT_KIND)->count());
    }

    public function test_si_la_cache_falla_la_clasificacion_sigue_igual_y_queda_en_el_registro(): void
    {
        $fake = new FakeReplyTransport(FakeReplyTransport::says('not_interested', 95));
        $this->classifierOn($fake);
        $s = $this->sentTemplate();
        $this->inbound($s['member']['lead'], self::TEXT);
        config(['cache.default' => 'no_existe']);
        Log::spy();

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['replied' => 1, 'not_interested' => 1], $r['recorded'], 'el freno entra igual');
        $this->assertSame(['calls' => 1, 'costMicros' => 140, 'recorded.recorded' => 1], $r['classifier']);
        Log::shouldHaveReceived('warning')->with('outreach.classifier.health_failed', ['error_class' => InvalidArgumentException::class])->once();
        $this->assertSame(['consecutive' => 0, 'lastClass' => null, 'lastAt' => null, 'alertAfter' => 5], $this->providerFailures(), 'el estado no se cae: dice 0');
        Log::shouldHaveReceived('warning')->with('outreach.classifier.health_failed', Mockery::any())->twice();
    }
}
