<?php

namespace Tests\Feature\Outreach\W9;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Run\Channels\AssistedChannel;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\OutreachChannel;
use App\Services\Outreach\Run\Channels\SimulatedChannel;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\Outreach\Run\SubjectSuppressions;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * SEC · SC-1 de la revisión de seguridad del cierre: UNA BAJA POR WHATSAPP NO
 * SUPRIMÍA LA VOZ (ni al revés). `SubjectSuppressions` exigía la misma
 * finalidad Y el mismo medio del trabajo, y el canal de voz no mira las
 * señales de WhatsApp: quien contestaba «No me contacten más» a una plantilla
 * comercial recibía la llamada de otra estrategia comercial. Ahora la baja que
 * la persona dice con sus palabras (`inbound_message`, `voice_call`) y la
 * palabra de baja que la ejecución dejó en el libro (`opt_out_word`) suprimen
 * TODOS los medios de esa finalidad, y ninguna otra finalidad.
 *
 * De punta a punta, con la ejecución real (planificador, despachador, canal,
 * puerta de Marketing, outbox, Graph FALSO, entrante por el camino de siempre
 * y normalizador): la persona P contesta la baja; dos semanas después (fuera
 * de las reglas de 7 días de N1) una estrategia comercial de VOZ la niega en
 * la escalera (N2 `global_suppression`) y el canal de voz —y con él la puerta
 * del H8— no se intenta nunca; la persona Q, igual en todo salvo la baja, sí
 * llega al canal (que se para en la constante cerrada del H9: nada se pide).
 *
 * LAS DOS CONSTANTES SIGUEN CERRADAS: la puerta abierta de la escalera es el
 * doble de las pruebas; el canal de voz lee la constante de verdad.
 */
class OptOutAcrossMediaTest extends TestCase
{
    use RefreshDatabase;
    use V9World;
    use W9World;

    /** Martes 3 de noviembre de 2026, 10:00 de Bogotá: sale la plantilla comercial de WhatsApp y P contesta. */
    private const T0 = '2026-11-03 15:00:00';

    /** Martes 17 de noviembre, 10:00 de Bogotá: dos semanas después, la estrategia comercial de VOZ. */
    private const T1 = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::T0, 'UTC'));
        config(['commercial.events_enabled' => true]);
        // La `APP_KEY` del fixture de la voz ANTES de crear filas firmadas o con huella.
        $this->v9VoiceConfig();
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sc1_una_baja_por_whatsapp_niega_la_estrategia_comercial_de_voz_y_no_llega_a_la_puerta(): void
    {
        $t0 = $this->whatsappOptOutAtT0();
        [$p, $q] = [$t0['p'], $t0['q']];

        // T1 · La estrategia comercial de VOZ, con todo lo de la voz en regla, cupo y presupuesto.
        $this->voiceStrategyAtT1($t0['whatsapp']);
        $voz = $this->v9VoiceStrategy('PUBLISHED');
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(2, DB::table('outreach_enrollments')->where('strategy_id', $voz['strategy'])->count(), 'la puerta inscribe a las dos (la supresión es de la escalera)');
        $plan = $planner->planDue(now());

        // P: N2 `global_suppression` (N1 no tiene nada que negar: consentimiento de voz a cuatro ojos, RNE, sin «no contactar», dos semanas sin contacto).
        $negada = DB::table('outreach_decisions')->where('subject_key', $p['key'])->where('kind', 'deny')->orderByDesc('id')->first();
        $this->assertNotNull($negada, json_encode($plan));
        $this->assertSame('N2', $negada->level);
        $this->assertSame(['global_suppression'], json_decode((string) $negada->reasons, true));
        $this->assertSame('global_suppression', DB::table('outreach_enrollments')->where('strategy_id', $voz['strategy'])->where('subject_key', $p['key'])->value('exit_reason'));
        $this->assertSame(0, DB::table('outreach_jobs')->where('strategy_id', $voz['strategy'])->where('subject_key', $p['key'])->count(), 'P no tiene trabajo de voz');
        // Q (el control, igual en todo salvo la baja): planificada.
        $this->assertSame(1, DB::table('outreach_jobs')->where('strategy_id', $voz['strategy'])->where('subject_key', $q['key'])->where('status', 'planned')->count(), 'Q sí: '.json_encode($plan));

        // El despacho: solo Q llega al canal de voz, que se para en la constante cerrada del H9 (la puerta del H8 no recibe nada).
        Carbon::setTestNow(now()->addMinutes(15));
        $espia = $this->voiceSpy();
        $this->dispatcherWith($espia)->dispatch(now(), null, true);
        $this->assertSame([$q['key']], $espia->subjects, 'el canal de voz (y su puerta) nunca se intenta para P');
        $trabajoQ = DB::table('outreach_jobs')->where('strategy_id', $voz['strategy'])->where('subject_key', $q['key'])->sole();
        $this->assertSame('failed_to_start', $trabajoQ->status);
        $this->assertSame('sends_disabled_in_code', DB::table('outreach_outcomes')->where('job_id', $trabajoQ->id)->value('detail_code'));
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
        Http::assertSentCount(2);
    }

    public function test_sc1_el_despachador_niega_un_trabajo_de_voz_ya_planificado_de_quien_pidio_la_baja(): void
    {
        $t0 = $this->whatsappOptOutAtT0();
        $this->voiceStrategyAtT1($t0['whatsapp']);
        $voz = $this->v9VoiceStrategy('PUBLISHED');
        // Un trabajo de voz de P ya reclamado (la última red: la escalera otra vez en el despacho, TOCTOU).
        $p = $this->v9VoiceJob($voz, $t0['p'], 'claimed');
        $espia = $this->voiceSpy();

        $r = $this->dispatcherWith($espia)->process((int) $p['job']->id, now());

        // Suprimida: el trabajo se cancela y la inscripción SALE (no espera), como manda el despachador para `global_suppression`.
        $this->assertSame('canceled', $r);
        $trabajo = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame(['canceled', 'global_suppression'], [$trabajo->status, $trabajo->cancel_reason]);
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva vuelve');
        $decision = DB::table('outreach_decisions')->where('job_id', $p['job']->id)->where('kind', 'cancel')->sole();
        $this->assertSame(['N2', ['global_suppression']], [$decision->level, json_decode((string) $decision->reasons, true)]);
        $this->assertSame('global_suppression', DB::table('outreach_enrollments')->where('id', $p['enrollment']->id)->value('exit_reason'));
        $this->assertSame(['blocked', 'global_suppression'], [
            DB::table('outreach_outcomes')->where('enrollment_id', $p['enrollment']->id)->value('outcome'),
            DB::table('outreach_outcomes')->where('enrollment_id', $p['enrollment']->id)->value('reason_code'),
        ]);
        $this->assertSame([], $espia->subjects);
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows());
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
    }

    /**
     * Las reglas, una a una, por la ÚNICA ingesta (`OutcomeRecorder`) sobre
     * trabajos reales de cada canal: quién suprime qué (finalidad y medio).
     */
    public function test_sc1_las_reglas_de_la_supresion_por_fuente_y_detalle(): void
    {
        $this->whatsappLive(['outreach.voice.enabled' => true]);
        $this->v9GreenVoice();
        $plantilla = $this->whatsappTemplate();
        $wa = $this->whatsappStrategy($plantilla);
        $voz = $this->v9VoiceStrategy('PUBLISHED');
        $s = new SubjectSuppressions;
        $grabar = new OutcomeRecorder;
        $ana = Admin::query()->firstOrFail();
        $n = 0;
        $caso = function (string $canal, string $outcome, string $source, ?string $detail = null) use ($wa, $voz, $plantilla, $grabar, $ana, &$n): string {
            $m = $this->persona('30012390'.str_pad((string) ++$n, 2, '0', STR_PAD_LEFT));
            if ($canal === 'voice_call') {
                $t = $this->v9VoiceJob($voz, $m, 'dispatching');
            } else {
                $t = $this->whatsappJobFor($wa, $m, $plantilla);
            }
            $o = ['job' => $t['job']] + ($detail !== null ? ['detailCode' => $detail] : []) + ($source === 'staff' ? ['recordedBy' => $ana] : []);
            $r = $grabar->record($t['enrollment'], $outcome, $source, 'prueba:'.$t['job']->uuid, now(), $o);
            $this->assertNotSame(OutcomeRecorder::RESULT_IGNORED, $r['result'], "{$outcome}/{$source}");

            return $m['key'];
        };

        // SC-1: la baja que la persona dijo con sus palabras alcanza TODOS los medios de su finalidad, y ninguna otra finalidad.
        foreach ([['whatsapp_template', 'inbound_message'], ['voice_call', 'voice_call']] as [$canal, $fuente]) {
            $k = $caso($canal, 'opted_out', $fuente);
            $this->assertTrue($s->isSuppressed($k, 'commercial', 'whatsapp'), "{$fuente}: WhatsApp");
            $this->assertTrue($s->isSuppressed($k, 'commercial', 'voice'), "{$fuente}: voz");
            $this->assertFalse($s->isSuppressed($k, 'service', 'whatsapp'), "{$fuente}: otra finalidad, intacta");
            $this->assertFalse($s->isSuppressed($k, 'service', 'voice'), "{$fuente}: otra finalidad, intacta");
        }
        // SC-2: la palabra de baja que la ejecución dejó en el libro (el canal que no envió; el normalizador que derivó), igual.
        foreach ([['failed', 'system'], ['human_requested', 'inbound_message']] as [$resultado, $fuente]) {
            $k = $caso('whatsapp_template', $resultado, $fuente, SubjectSuppressions::OPT_OUT_WORD_DETAIL);
            $this->assertTrue($s->isSuppressed($k, 'commercial', 'voice'), "{$resultado}/opt_out_word: voz");
            $this->assertTrue($s->isSuppressed($k, 'commercial', 'whatsapp'), "{$resultado}/opt_out_word: WhatsApp");
            $this->assertFalse($s->isSuppressed($k, 'service', 'voice'), "{$resultado}/opt_out_word: otra finalidad, intacta");
        }
        // Lo que no es una baja no suprime: un fallo de Meta, una respuesta, una derivación sin palabra de baja.
        foreach ([['failed', 'meta_status', 'meta_error_131026'], ['replied', 'inbound_message', null], ['human_requested', 'inbound_message', null]] as [$resultado, $fuente, $detalle]) {
            $k = $caso('whatsapp_template', $resultado, $fuente, $detalle);
            $this->assertFalse($s->isSuppressed($k, 'commercial', 'whatsapp'), "{$resultado}/{$fuente}");
            $this->assertFalse($s->isSuppressed($k, 'commercial', 'voice'), "{$resultado}/{$fuente}");
        }
        // Sin cambios: la baja que registra el PERSONAL sigue siendo por finalidad y medio (D-1 del informe sec-fix-baja: lo decide el lead),
        // y `wrong_person` por medio en cualquier finalidad.
        $k = $caso('whatsapp_template', 'opted_out', 'staff');
        $this->assertTrue($s->isSuppressed($k, 'commercial', 'whatsapp'));
        $this->assertFalse($s->isSuppressed($k, 'commercial', 'voice'), 'staff: por medio, como hasta ahora');
        $k = $caso('voice_call', 'wrong_person', 'voice_call');
        $this->assertTrue($s->isSuppressed($k, 'service', 'voice'));
        $this->assertFalse($s->isSuppressed($k, 'commercial', 'whatsapp'));
    }

    // ── El mundo ────────────────────────────────────────────────────────────

    /**
     * T0 · La plantilla comercial de WhatsApp sale a P y a Q por la ejecución
     * real; P contesta «No me contacten más» por el camino de siempre y el
     * normalizador registra su `opted_out` (fuente `inbound_message`).
     *
     * @return array{p: array<string, mixed>, q: array<string, mixed>, whatsapp: array<string, mixed>}
     */
    private function whatsappOptOutAtT0(): array
    {
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        // A 19 días del vencimiento: la ventana de esta estrategia es de 21.
        $wa = $this->whatsappStrategy($plantilla, ['trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 21]]]);
        $p = $this->persona('3001239001');
        $q = $this->persona('3001239002');
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(2, $planner->planDue(now())['planned']);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->assertSame(['started' => 2], $this->openDispatcher()->dispatch(now(), null, true)['results']);
        Carbon::setTestNow(now()->addMinutes(5));
        $conversacion = MarketingConversation::query()->where('lead_id', $p['lead'])->firstOrFail();
        app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.sc1', 'No me contacten más', ['message_type' => 'text']);

        $this->assertSame(['opted_out' => 1], (new WhatsappTemplateOutcomes)->run(now())['recorded']);
        $baja = DB::table('outreach_outcomes')->where('subject_key', $p['key'])->sole();
        $this->assertSame(['opted_out', 'inbound_message', 'whatsapp_template'], [$baja->outcome, $baja->source, $baja->channel]);
        $s = new SubjectSuppressions;
        $this->assertTrue($s->isSuppressed($p['key'], 'commercial', 'voice'), 'SC-1: la baja por WhatsApp alcanza la voz de su finalidad');
        $this->assertFalse($s->isSuppressed($p['key'], 'service', 'voice'), 'y no otra finalidad');
        $this->assertFalse($s->isSuppressed($p['key'], 'service', 'whatsapp'), 'y no otra finalidad');
        $this->assertFalse($s->isSuppressed($q['key'], 'commercial', 'voice'), 'Q no pidió nada');

        return ['p' => $p, 'q' => $q, 'whatsapp' => $wa];
    }

    /** T1 · Dos semanas después: la estrategia de WhatsApp revertida (no compite), la voz del H8 en regla y la de la automatización con cupo. @param  array<string, mixed>  $whatsapp */
    private function voiceStrategyAtT1(array $whatsapp): void
    {
        Carbon::setTestNow(Carbon::parse(self::T1, 'UTC'));
        $this->rollbackStrategy($whatsapp);
        $this->v9GreenVoice();
        $this->whatsappLive([
            'outreach.voice.enabled' => true,
            'outreach.voice.daily_cap' => 5,
            'outreach.voice.daily_budget_usd' => '10',
            'outreach.voice.reserve_usd_per_call' => '1',
        ]);
    }

    /**
     * Una persona socia que vence el 22 de noviembre (a 19 días de T0, a 5 de
     * T1) con TODO para los dos medios: su prospecto de WhatsApp autorizado,
     * con conversación y número canario de WhatsApp, y su contacto de voz
     * ACTIVO con consentimiento comercial a cuatro ojos, RNE vigente y canario
     * de voz (lo de `v9Member()`, con su propia fecha).
     *
     * @return array{user: int, member: int, key: string, lead: int, contact: int, e164: string}
     */
    private function persona(string $phone): array
    {
        $r = $this->expiringMember(self::utcAt(self::T0), 19);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $e164 = '+57'.$phone;
        $this->canary($e164, 'whatsapp');
        $contacto = $this->v9VoiceContact('member', $r['member'], $e164);
        $this->voiceConsent($contacto['id'], 'granted', 2, now()->utc()->subDays(10)->format('Y-m-d H:i'), 1);
        $this->rneCheck($contacto['hash'], now()->utc()->subDay()->format('Y-m-d H:i'));
        $this->v9VoiceCanary($e164);

        return $r + ['lead' => $lead, 'contact' => $contacto['id'], 'e164' => $e164];
    }

    /**
     * Una inscripción viva de la persona en la estrategia de WhatsApp y su trabajo en `dispatching`.
     *
     * @param  array<string, mixed>  $live
     * @param  array{member: int, key: string}  $member
     * @return array{enrollment: object, job: object}
     */
    private function whatsappJobFor(array $live, array $member, int $templateId): array
    {
        $p = $this->v9VoiceJob($live, $member, 'dispatching', [
            'channel' => WhatsappTemplateChannel::NAME, 'template_version_id' => $templateId,
            'template_hash' => (string) DB::table('ultron_studio_message_templates')->where('id', $templateId)->value('content_hash'),
        ]);

        return ['enrollment' => $p['enrollment'], 'job' => (new OutreachStore)->job((int) $p['job']->id)];
    }

    private function dispatcherWith(OutreachChannel $voice): OutreachDispatcher
    {
        return new OutreachDispatcher(
            inputs: new LadderInputBuilder(sendsEnabledInCode: self::gateOpen()),
            channels: [new SimulatedChannel, new AssistedChannel, new WhatsappTemplateChannel(self::gateOpen()), $voice],
        );
    }

    /** El canal de voz REAL, con un registro de a quién se intentó (la puerta del H8 es `final`: no se dobla). */
    private function voiceSpy(): OutreachChannel
    {
        return new class implements OutreachChannel
        {
            /** @var list<string> */
            public array $subjects = [];

            private readonly VoiceStrategyChannel $real;

            public function __construct()
            {
                $this->real = new VoiceStrategyChannel;
            }

            public function name(): string
            {
                return VoiceStrategyChannel::NAME;
            }

            public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
            {
                $this->subjects[] = (string) $job->subject_key;

                return $this->real->attempt($job, $enrollment, $live, $now);
            }
        };
    }
}
