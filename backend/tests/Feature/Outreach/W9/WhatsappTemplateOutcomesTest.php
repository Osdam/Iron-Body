<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachReconciler;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\Outreach\Run\SubjectSuppressions;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * W9 · LOS NORMALIZADORES (contrato H9, D9-8 etapa 8, D9-10, D9-15): lo que
 * el webhook de Meta deja por el camino de siempre (estados de entrega y
 * entrantes, sin tocar sus rutas ni su código) pasa a resultados del conjunto
 * cerrado: `meta_status` → `sent`/`delivered`/`read`/`failed`;
 * `inbound_message` → `replied` (deriva a una persona) u `opted_out` con la
 * palabra de baja (sale, cancela todo lo pendiente de la persona y la suprime).
 * Nunca interpreta el texto; una vez por fuente y clave.
 */
class WhatsappTemplateOutcomesTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

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

    /** Todo encendido con la puerta abierta solo aquí y el mensaje ya en el outbox. @return array{job: object, message: MarketingMessage, member: array<string, mixed>, live: array<string, mixed>} */
    private function sentTemplate(): array
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $this->assertSame(['started' => 1], $this->openDispatcher()->dispatch(now(), null, true)['results']);

        return ['job' => DB::table('outreach_jobs')->first(), 'message' => MarketingMessage::query()->sole(), 'member' => $p['member'], 'live' => $p['live']];
    }

    /** Un entrante de la persona por el camino de siempre (lo que guarda el webhook), minutos después. */
    private function inbound(int $leadId, string $body, int $minutesLater = 5): MarketingMessage
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));
        $conversacion = MarketingConversation::query()->where('lead_id', $leadId)->where('channel', 'whatsapp')->firstOrFail();

        return app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.'.uniqid('', true), $body, ['message_type' => 'text']);
    }

    public function test_los_estados_de_meta_por_el_webhook_de_siempre_se_vuelven_resultados_una_vez(): void
    {
        $s = $this->sentTemplate();
        $wamid = (string) $s['message']->meta_message_id;
        // Fuera de orden, como los manda Meta: `read` antes que `delivered` y un `sent` repetido.
        Carbon::setTestNow(now()->addMinute());
        $this->metaStatusWebhook($wamid, 'sent');
        $this->metaStatusWebhook($wamid, 'read');
        $this->metaStatusWebhook($wamid, 'delivered');
        $this->metaStatusWebhook($wamid, 'sent');
        $this->assertSame('read', $s['message']->fresh()->status, 'el webhook de siempre aplicó su regla');

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(1, $r['jobs']);
        $this->assertSame(['sent' => 1, 'delivered' => 1, 'read' => 1], $r['recorded']);
        $filas = DB::table('outreach_outcomes')->orderBy('id')->get();
        $this->assertSame(['sent', 'delivered', 'read'], $filas->pluck('outcome')->all(), 'en el orden real de la entrega');
        foreach ($filas as $f) {
            $this->assertSame('meta_status', $f->source);
            $this->assertSame((int) $s['job']->id, (int) $f->job_id);
            $this->assertSame('whatsapp_template', $f->channel);
            $this->assertNull($f->next_action, 'no terminales: se espera');
            $this->assertMatchesRegularExpression('/^job:[0-9a-f-]{36}:(sent|delivered|read)$/', $f->source_key);
        }
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));

        // Otra pasada: nada nuevo.
        $otra = (new WhatsappTemplateOutcomes)->run(now());
        $this->assertSame([], $otra['recorded']);
        $this->assertSame(3, DB::table('outreach_outcomes')->count());
        Http::assertSentCount(1);
    }

    public function test_un_fallo_de_meta_es_failed_con_su_codigo_y_la_secuencia_sale_por_defecto(): void
    {
        $s = $this->sentTemplate();
        Carbon::setTestNow(now()->addMinute());
        $this->metaStatusWebhook((string) $s['message']->meta_message_id, 'failed', 131026);

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['failed' => 1], $r['recorded']);
        $f = DB::table('outreach_outcomes')->sole();
        $this->assertSame('failed', $f->outcome);
        $this->assertSame('meta_status', $f->source);
        $this->assertSame('meta_error_131026', $f->detail_code, 'el código del proveedor, nunca su texto');
        $this->assertSame('end_exit', $f->next_action);
        $this->assertSame('exited', DB::table('outreach_enrollments')->value('status'));
    }

    public function test_un_failed_que_llega_tarde_a_un_mensaje_entregado_no_es_un_fallo(): void
    {
        $s = $this->sentTemplate();
        $wamid = (string) $s['message']->meta_message_id;
        Carbon::setTestNow(now()->addMinute());
        $this->metaStatusWebhook($wamid, 'delivered');
        $this->metaStatusWebhook($wamid, 'failed', 131000);

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['delivered' => 1], $r['recorded']);
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));
    }

    public function test_una_respuesta_posterior_es_replied_y_deriva_a_una_persona_sin_interpretar_el_texto(): void
    {
        $s = $this->sentTemplate();
        $this->inbound($s['member']['lead'], '¿Y cuánto vale el plan trimestral?');

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['replied' => 1], $r['recorded']);
        $f = DB::table('outreach_outcomes')->sole();
        $this->assertSame('replied', $f->outcome);
        $this->assertSame('inbound_message', $f->source);
        $this->assertSame('handoff_human', $f->next_action, 'por defecto: la conversación sigue con ULTRON o con una persona');
        $this->assertSame('handed_off', DB::table('outreach_enrollments')->value('status'));
        foreach (['detail_code', 'reason_code'] as $columna) {
            $this->assertNull($f->{$columna});
        }
        $this->assertStringNotContainsString('trimestral', json_encode(DB::table('outreach_outcomes')->get()), 'el texto no viaja');
    }

    public function test_un_entrante_de_antes_del_contacto_no_es_una_respuesta(): void
    {
        // La persona escribió ANTES de que saliera la plantilla: no es una respuesta a ella.
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $this->whatsappStrategy($plantilla);
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $conversacion = MarketingConversation::query()->where('lead_id', $socio['lead'])->firstOrFail();
        Carbon::setTestNow(now()->subHour());
        app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.ANTES', 'hola, una pregunta', []);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->assertSame(['started' => 1], $this->openDispatcher()->dispatch(now(), null, true)['results']);

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame([], $r['recorded']);
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));
    }

    public function test_la_palabra_de_baja_es_opted_out_con_supresion_y_cancela_lo_pendiente_de_todas_las_estrategias(): void
    {
        $s = $this->sentTemplate();
        // La misma persona, inscrita en una SEGUNDA estrategia con su trabajo pendiente.
        $otra = $this->whatsappStrategy($this->whatsappTemplate(['metaName' => 'renovacion_w9_b']), [], ['key' => 'segunda-w9']);
        $this->metaCheck((int) DB::table('ultron_studio_message_templates')->orderByDesc('id')->value('id'));
        $this->openPlanner()->sweep(now(), true);
        $abiertas = DB::table('outreach_enrollments')->where('status', 'active')->count();
        $this->assertSame(2, $abiertas, 'dos inscripciones abiertas de la persona');
        // Su trabajo pendiente para pasado mañana (el tope diario y las 48 h lo aplazarían; aquí ya está planificado, con su reserva).
        $segunda = DB::table('outreach_enrollments')->where('strategy_id', $otra['strategy'])->first();
        $plantillaB = (int) DB::table('ultron_studio_message_templates')->orderByDesc('id')->value('id');
        $pendiente = (new OutreachStore)->insertJob($segunda, 0, 1, 'whatsapp_template', null, $plantillaB,
            (string) DB::table('ultron_studio_message_templates')->where('id', $plantillaB)->value('content_hash'), null,
            now()->addDays(2)->toDateTimeImmutable(), now()->addDays(2)->addHours(4)->toDateTimeImmutable(), '2026-11-19', ['units' => 1, 'micros' => 50000], now()->toDateTimeImmutable());
        $this->assertNotNull($pendiente);
        $this->inbound($s['member']['lead'], '  BAJA, por favor 🙏 ');

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['opted_out' => 1], $r['recorded']);
        $f = DB::table('outreach_outcomes')->sole();
        $this->assertSame('opted_out', $f->outcome);
        $this->assertSame('inbound_message', $f->source);
        $this->assertSame('end_exit', $f->next_action, 'ninguna estrategia lo anula');
        $this->assertSame('opted_out', DB::table('outreach_enrollments')->where('id', $s['job']->enrollment_id)->value('status'));
        $this->assertSame(0, DB::table('outreach_enrollments')->whereIn('status', ['active', 'waiting'])->count(), 'la otra inscripción también sale');
        $cancelado = DB::table('outreach_jobs')->where('id', $pendiente['id'])->first();
        $this->assertSame('canceled', $cancelado->status, 'lo pendiente de la otra estrategia se cancela');
        $this->assertSame('opted_out', $cancelado->cancel_reason);
        $this->assertNotNull($cancelado->budget_released_at);
        $this->assertTrue((new SubjectSuppressions)->isSuppressed((string) $s['job']->subject_key, 'commercial', 'whatsapp'), 'suprimida para la finalidad y el medio');

        // Y ya nada sale: ni planificar de nuevo ni despachar.
        Carbon::setTestNow(now()->addDays(3));
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $this->assertSame(0, DB::table('outreach_jobs')->whereIn('status', ['planned', 'claimed', 'dispatching'])->count());
        Http::assertSentCount(1);
    }

    public function test_el_canal_no_escribe_a_quien_ya_pidio_la_baja_aunque_el_normalizador_no_haya_pasado(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        // La baja llegó por WhatsApp (en cualquier momento) y nadie la normalizó todavía.
        $this->inbound($p['member']['lead'], 'No me escriban más', 1);
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');

        $intento = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());

        $this->assertSame('failed_to_start', $intento->status);
        $this->assertSame('opt_out_word', $intento->detailCode);
        $this->assertSame(0, MarketingMessage::query()->where('direction', 'outbound')->count());
        Http::assertNothingSent();
    }

    public function test_un_trabajo_ambiguo_se_concilia_por_su_correlacion(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $store = new OutreachStore;
        $id = (int) $p['job']->id;
        $this->assertTrue($store->transitionJob($id, ['planned'], 'claimed', now()->toDateTimeImmutable(), ['claimed_at' => now()->format('Y-m-d H:i:s')]));
        $this->assertTrue($store->transitionJob($id, ['claimed'], 'dispatching', now()->toDateTimeImmutable()));
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        // El canal hizo su petición (el mensaje salió) y el proceso murió antes de cerrar el trabajo.
        $intento = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($store->job($id), $e, $live, now()->toDateTimeImmutable());
        $this->assertSame('started', $intento->status);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertSame(1, (new OutreachReconciler)->run(now())['ambiguous'], 'la conciliación lo deja ambiguo (nadie sabe si salió)');
        $this->assertNull($store->job($id)->marketing_message_id);
        $mensaje = MarketingMessage::query()->sole();
        $this->metaStatusWebhook((string) $mensaje->meta_message_id, 'delivered');

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(1, $r['linked']);
        $trabajo = $store->job($id);
        $this->assertSame('ambiguous', $trabajo->status, 'un final no cambia: se concilia con un resultado');
        $this->assertSame((int) $mensaje->id, (int) $trabajo->marketing_message_id, 'unido una vez por la correlación');
        $this->assertSame(['delivered' => 1], $r['recorded']);
        $this->assertSame(['ambiguous', 'delivered'], DB::table('outreach_outcomes')->orderBy('id')->pluck('outcome')->all());
        Http::assertSentCount(1);
    }

    public function test_la_orden_esta_apagada_con_la_maestra_apagada_y_en_marcha_con_ella(): void
    {
        $this->assertSame(0, Artisan::call('outreach:whatsapp-outcomes', ['--json' => true]));
        $this->assertStringContainsString('master_flag_off', Artisan::output());
        $this->assertSame(0, DB::table('outreach_runs')->count(), 'apagada no escribe nada');

        $s = $this->sentTemplate();
        $this->metaStatusWebhook((string) $s['message']->meta_message_id, 'sent');
        $pasadas = DB::table('outreach_runs')->count();
        $this->assertSame(0, Artisan::call('outreach:whatsapp-outcomes'));
        $this->assertStringContainsString('sent 1', Artisan::output());
        $this->assertSame(1, DB::table('outreach_outcomes')->where('outcome', 'sent')->count());
        // Sin fila de pasada: una cada minuto taparía en el panel la ingesta de `commercial_events` (la última por tipo).
        $this->assertSame($pasadas, DB::table('outreach_runs')->count());
    }
}
