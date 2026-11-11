<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\OutreachStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * W9 · EL CANAL DE WHATSAPP POR PLANTILLAS (contrato H9, D9-9.3 «W-B», §3
 * protecciones 2 y 12, D9-8 etapas 5–7): en sombra no se crea nada; en vivo
 * con la constante cerrada nada sale y la reserva se libera; con la puerta
 * abierta SOLO en la prueba (un doble, la constante sigue en `false`) y todo
 * encendido, el mensaje queda en el outbox con su correlación, una sola vez;
 * sin correspondencia aprobada por Meta, `failed_to_start`; la pausa del
 * agente retiene (`waitsForRelease`). Cero red salvo el Graph falso.
 */
class WhatsappTemplateChannelTest extends TestCase
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

    public function test_la_constante_sigue_cerrada_y_whatsapp_es_un_canal_implementado(): void
    {
        $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
        $this->assertTrue(OutreachSendGate::channelLive(WhatsappTemplateChannel::NAME), 'W9 implementa la puerta (LIVE_CHANNELS)');
        $this->assertTrue(OutreachSendGate::channelLive('voice_call'), 'la voz también está declarada (V9, L9-81): implementada no es abierta');
        $this->assertFalse(OutreachSendGate::automaticSendAllowed('voice_call'), 'la constante cierra también la voz');
        $this->whatsappLive();
        $this->assertFalse(OutreachSendGate::automaticSendAllowed(WhatsappTemplateChannel::NAME), 'implementado no es abierto: la constante cierra');
    }

    public function test_en_sombra_nada_se_crea_ni_sale(): void
    {
        $this->runFlags('shadow', ['outreach.whatsapp.enabled' => true, 'outreach.whatsapp.daily_cap' => 10, 'outreach.whatsapp.daily_budget_usd' => '5']);
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $this->whatsappStrategy($plantilla);
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');

        // Incluso con la puerta abierta en el doble: en sombra el canal es el simulado.
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(15));
        $r = $this->openDispatcher()->dispatch(now(), null, true);

        $this->assertSame(['simulated' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('simulated', $trabajo->channel);
        $this->assertSame(0, (int) $trabajo->reserved_units, 'en sombra no se reserva');
        $this->assertNull($trabajo->marketing_message_id);
        $this->assertSame(0, DB::table('marketing_messages')->count(), 'en sombra no nace ningún mensaje');
        Http::assertNothingSent();
    }

    public function test_en_vivo_con_la_constante_cerrada_nada_sale_y_la_reserva_se_libera(): void
    {
        // El planificador REAL (la constante) no planifica WhatsApp en vivo: N4 lo niega.
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $this->whatsappStrategy($plantilla);
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $real = new OutreachPlanner;
        $real->sweep(now(), true);
        $this->assertSame(0, $real->planDue(now())['planned'] ?? 0, 'con la constante cerrada no se planifica WhatsApp en vivo');
        $this->assertSame(0, DB::table('outreach_jobs')->count());
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->orderByDesc('id')->first();
        $this->assertNotNull($negada);
        $this->assertSame('N4', $negada->level);
        $this->assertContains('sends_disabled_in_code', json_decode((string) $negada->reasons, true));
        Http::assertNothingSent();
    }

    public function test_un_trabajo_planificado_con_la_puerta_abierta_no_sale_si_la_constante_se_cierra_y_libera_su_reserva(): void
    {
        // Planificado cuando «estaba abierta» (el doble); al despachar manda la constante real (un `outreach(sends-open)` revertido).
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $this->assertSame('whatsapp_template', $p['job']->channel);
        $this->assertSame(1, (int) $p['job']->reserved_units);
        $this->assertSame(50000, (int) $p['job']->reserved_micros, '0,05 USD reservados');
        $this->assertSame(1, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:whatsapp_template')->value('reserved_units'));

        $r = (new OutreachDispatcher)->dispatch(now(), null, true);

        $this->assertSame(1, $r['claimed']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $trabajo->status);
        $this->assertSame('sends_disabled_in_code', $trabajo->cancel_reason);
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva se libera');
        $contador = DB::table('outreach_budget_counters')->where('scope', 'channel:whatsapp_template')->first();
        $this->assertSame(0, (int) $contador->reserved_units);
        $this->assertSame(0, (int) $contador->reserved_micros);
        $this->assertSame(0, DB::table('marketing_messages')->count());
        Http::assertNothingSent();
    }

    public function test_el_canal_con_la_constante_cerrada_no_llama_a_la_puerta(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        $puerta = $this->mock(MarketingMessageDispatcher::class);
        $puerta->shouldNotReceive('dispatchWhatsappTemplate');

        $intento = (new WhatsappTemplateChannel)->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());

        $this->assertSame('failed_to_start', $intento->status);
        $this->assertSame('sends_disabled_in_code', $intento->detailCode);
        $this->assertSame(0, DB::table('marketing_messages')->count());
        Http::assertNothingSent();
    }

    public function test_con_la_puerta_abierta_solo_en_la_prueba_el_mensaje_queda_en_el_outbox_con_su_correlacion_una_sola_vez(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));

        $r = $this->openDispatcher()->dispatch(now(), null, true);

        $this->assertSame(['started' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('started', $trabajo->status);
        $this->assertNotNull($trabajo->marketing_message_id);
        $this->assertNull($trabajo->budget_released_at, 'salió: la reserva se consume');

        $m = MarketingMessage::query()->sole();
        $this->assertSame((int) $trabajo->marketing_message_id, (int) $m->id);
        $this->assertSame('outbound', $m->direction);
        $this->assertSame('ai', $m->sender_type, 'remitente ai: la pausa del agente la retiene');
        $this->assertSame('sent', $m->status);
        $this->assertStringStartsWith('wamid.W9.', (string) $m->meta_message_id);
        $this->assertSame((string) $trabajo->uuid, (string) $m->correlation_id, 'la correlación une el trabajo al mensaje');
        $this->assertSame(OutreachTemplateMessage::KIND, $m->metadata['kind']);
        $this->assertSame((string) $trabajo->uuid, $m->metadata['outreach_job_uuid']);
        $this->assertSame(self::META_NAME, $m->metadata['template']['name']);
        $this->assertSame((string) $trabajo->template_hash, $m->metadata['template']['hash']);
        $this->assertSame('Hola Socio, tu plan vence el 22 de noviembre de 2026. Responde BAJA para no recibir más mensajes.', $m->body);
        $this->assertSame(['Socio', '22 de noviembre de 2026'], $m->metadata['template']['parameters']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), self::PHONE_ID.'/messages')
            && $req['type'] === 'template'
            && $req['to'] === '573001234567'
            && $req['template']['name'] === self::META_NAME
            && $req['template']['language']['code'] === 'es'
            && $req['template']['components'][0]['parameters'][0]['text'] === 'Socio'
            && ! isset($req['text']));

        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame(1, (int) $e->touches);
        $this->assertSame(0, DB::table('outreach_outcomes')->count(), 'el resultado lo trae el normalizador de Meta');

        // UNA SOLA VEZ: el mismo trabajo otra vez por el canal (no debería ocurrir) no crea ni envía nada.
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        $otra = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());
        $this->assertSame('started', $otra->status);
        $this->assertSame((int) $m->id, $otra->refs['marketing_message_id']);
        $this->assertSame(1, MarketingMessage::query()->count());
        Http::assertSentCount(1);
        // Y el despachador no lo vuelve a reclamar.
        $this->assertSame(0, $this->openDispatcher()->dispatch(now(), null, true)['claimed']);
    }

    public function test_la_puerta_de_marketing_es_idempotente_por_trabajo(): void
    {
        $this->metaOn();
        $lead = MarketingLead::query()->findOrFail($this->lead(['phone' => '3009998877', 'consent_status' => 'granted']));
        $this->conversation($lead->id);
        $plantilla = new OutreachTemplateMessage('renovacion_w9', 'es', 'marketing', str_repeat('a', 64), ['Ana'], 'Hola Ana. Responde BAJA para no recibir más.');
        $trabajo = '0b9d6c3e-5f4a-4b8e-9c1d-2a3b4c5d6e7f';

        $uno = app(MarketingMessageDispatcher::class)->dispatchWhatsappTemplate($lead, $plantilla, $trabajo);
        $dos = app(MarketingMessageDispatcher::class)->dispatchWhatsappTemplate($lead, $plantilla, strtoupper($trabajo));

        $this->assertTrue($uno['sent']);
        $this->assertFalse($uno['replayed']);
        $this->assertTrue($dos['replayed']);
        $this->assertSame($uno['message_id'], $dos['message_id']);
        $this->assertSame(1, MarketingMessage::query()->count());
        Http::assertSentCount(1);
        $this->assertNull(Context::get('correlation_id'), 'el contexto vuelve como estaba');
    }

    public function test_la_puerta_de_marketing_exige_consentimiento_para_escribir_primero_y_no_crea_conversaciones(): void
    {
        $this->metaOn();
        $plantilla = new OutreachTemplateMessage('renovacion_w9', 'es', 'marketing', str_repeat('b', 64), [], 'Hola. Responde BAJA para no recibir más.');
        $pendiente = MarketingLead::query()->findOrFail($this->lead(['phone' => '3009990001', 'consent_status' => 'pending']));
        $this->conversation($pendiente->id);
        $sinConversacion = MarketingLead::query()->findOrFail($this->lead(['phone' => '3009990002', 'consent_status' => 'granted']));
        $dnc = MarketingLead::query()->findOrFail($this->lead(['phone' => '3009990003', 'consent_status' => 'granted', 'do_not_contact' => true]));
        $this->conversation($dnc->id);
        $puerta = app(MarketingMessageDispatcher::class);

        $this->assertSame('no_proactive_consent', $puerta->dispatchWhatsappTemplate($pendiente, $plantilla, (string) Str::uuid())['reason']);
        $this->assertSame('no_whatsapp_conversation', $puerta->dispatchWhatsappTemplate($sinConversacion, $plantilla, (string) Str::uuid())['reason']);
        $this->assertSame('do_not_contact', $puerta->dispatchWhatsappTemplate($dnc, $plantilla, (string) Str::uuid())['reason']);
        $this->assertSame(0, MarketingMessage::query()->count());
        $this->assertSame(0, DB::table('marketing_conversations')->where('lead_id', $sinConversacion->id)->count(), 'la puerta nunca crea conversaciones');
        Http::assertNothingSent();
    }

    public function test_sin_correspondencia_aprobada_por_meta_failed_to_start_y_la_reserva_se_libera(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW), metaCheck: false);

        $r = $this->openDispatcher()->dispatch(now(), null, true);

        $this->assertSame(['failed' => 1], $r['results']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('failed_to_start', $trabajo->status);
        $this->assertNotNull($trabajo->budget_released_at);
        $this->assertSame(0, (int) DB::table('outreach_budget_counters')->where('scope', 'channel:whatsapp_template')->value('reserved_units'));
        $fallo = DB::table('outreach_outcomes')->first();
        $this->assertSame('failed', $fallo->outcome);
        $this->assertSame('template_not_approved_by_meta', $fallo->detail_code);
        $this->assertSame(0, DB::table('marketing_messages')->count());
        Http::assertNothingSent();
    }

    public function test_la_ultima_comprobacion_de_meta_manda_y_tiene_que_casar_con_el_contenido(): void
    {
        $this->whatsappLive();
        $plantilla = $this->whatsappTemplate();
        $puente = new OutreachPublishedStrategies;
        $t = $puente->approvedWhatsappTemplate($plantilla, (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash'), 'commercial');
        $this->assertNotNull($t);
        $this->assertFalse($puente->metaApproved($t), 'sin comprobación');

        $this->metaCheck($plantilla);
        $this->assertTrue($puente->metaApproved($t));

        // Meta la pausó después: una fila nueva; manda la última.
        Carbon::setTestNow(now()->addMinute());
        $this->metaCheck($plantilla, ['status' => 'PAUSED']);
        $this->assertFalse($puente->metaApproved($t), 'pausada en Meta');
        Carbon::setTestNow(now()->addMinute());
        $this->metaCheck($plantilla);
        $this->assertTrue($puente->metaApproved($t), 'reactivada en Meta');

        foreach ([['meta_body_hash' => hash('sha256', 'otro texto')], ['meta_name' => 'otra_plantilla'], ['language' => 'es_CO'], ['category' => 'utility']] as $distinto) {
            Carbon::setTestNow(now()->addMinute());
            $this->metaCheck($plantilla, $distinto);
            $this->assertFalse($puente->metaApproved($t), 'no casa: '.json_encode($distinto));
        }
        // Una plantilla de otra finalidad no es la del sello.
        $this->assertNull($puente->approvedWhatsappTemplate($plantilla, $t['hash'], 'collections'));
        $this->assertNull($puente->approvedWhatsappTemplate($plantilla, str_repeat('0', 64), 'commercial'), 'otra huella');
    }

    public function test_la_pausa_del_agente_retiene_el_trabajo_y_la_inscripcion_espera(): void
    {
        $this->plannedWhatsappJob(self::utcAt(self::NOW));
        DB::table('marketing_agent_settings')->updateOrInsert(['id' => 1], ['paused' => true, 'paused_at' => now(), 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);

        $r = $this->openDispatcher()->dispatch(now(), null, true);

        $this->assertSame(['waiting' => 1], $r['results'], 'N2 (waitsForRelease): espera, no falla');
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $trabajo->status);
        $this->assertSame('marketing_agent_paused', $trabajo->cancel_reason);
        $this->assertNotNull($trabajo->budget_released_at);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $e->status, 'la inscripción no sale');
        $this->assertSame(now()->addMinutes(OutreachDispatcher::WAIT_MINUTES)->format('Y-m-d H:i:s'), substr((string) $e->next_due_at, 0, 19), 'se vuelve a mirar en 15 min');
        $this->assertSame(0, DB::table('outreach_outcomes')->count(), 'ningún resultado que cierre la secuencia');
        $this->assertSame(0, DB::table('marketing_messages')->count());
        Http::assertNothingSent();
    }

    public function test_la_pausa_del_agente_entre_la_escalera_y_la_puerta_tambien_cierra(): void
    {
        // La carrera (TOCTOU): la escalera vio el agente activo y la pausa llegó justo antes de la puerta.
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        DB::table('marketing_agent_settings')->updateOrInsert(['id' => 1], ['paused' => true, 'paused_at' => now(), 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);

        $intento = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());

        $this->assertSame('failed_to_start', $intento->status);
        $this->assertSame('agent_paused', $intento->detailCode, 'la misma guarda que hoy detiene los mensajes de la máquina');
        $this->assertSame(0, DB::table('marketing_messages')->count(), 'la puerta no deja fila');
        Http::assertNothingSent();
    }

    public function test_cada_cerrojo_del_canal_cierra_por_su_cuenta(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        $intentar = fn (object $job): ?string => (new WhatsappTemplateChannel(self::gateOpen()))->attempt($job, $e, $live, now()->toDateTimeImmutable())->detailCode;

        config(['outreach.whatsapp.enabled' => false]);
        $this->assertSame('channel_disabled', $intentar($p['job']));
        config(['outreach.whatsapp.enabled' => true, 'outreach.mode' => 'shadow']);
        $this->assertSame('mode_not_live', $intentar($p['job']));
        config(['outreach.mode' => 'live']);
        $this->assertSame('budget_not_reserved', $intentar((object) (['reserved_units' => 0] + (array) $p['job'])));
        $this->assertSame('template_not_approved', $intentar((object) (['template_hash' => str_repeat('f', 64)] + (array) $p['job'])));
        $this->assertSame('job_channel_mismatch', $intentar((object) (['channel' => 'assisted'] + (array) $p['job'])));

        // Sin consentimiento expreso: el hecho lo niega antes de la puerta.
        DB::table('marketing_leads')->where('id', $p['member']['lead'])->update(['consent_status' => 'pending']);
        $this->assertSame('no_consent_for_purpose_channel', $intentar($p['job']));
        DB::table('marketing_leads')->where('id', $p['member']['lead'])->update(['consent_status' => 'granted']);

        // Sin un número que la puerta sepa normalizar no hay identidad de WhatsApp (el motivo exacto, no el del consentimiento).
        DB::table('marketing_leads')->where('id', $p['member']['lead'])->update(['phone' => '123']);
        $this->assertSame('no_whatsapp_identity', $intentar($p['job']));
        DB::table('marketing_leads')->where('id', $p['member']['lead'])->update(['phone' => '3001234567']);

        $this->assertSame(0, DB::table('marketing_messages')->count());
        Http::assertNothingSent();
    }

    public function test_un_marcador_que_w9_no_sabe_resolver_falla_cerrado_sin_crear_nada(): void
    {
        // El enlace de pago escribiría en pagos (el H9 nunca lo hace): la plantilla no sale.
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate(['bodyPreview' => 'Hola {{1}}, renueva aquí: {{2}}. Responde BAJA para no recibir más mensajes.', 'variables' => ['first_name', 'payment_link']]);
        $this->metaCheck($plantilla);
        $vivo = $this->whatsappStrategy($plantilla);
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $e = $this->enrollAndFakeJob($vivo, $plantilla);
        $live = (new OutreachPublishedStrategies)->liveVersion($vivo['version'], 'canary');

        $intento = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($e['job'], $e['enrollment'], $live, now()->toDateTimeImmutable());

        $this->assertSame('failed_to_start', $intento->status);
        $this->assertSame('template_marker_unresolvable', $intento->detailCode);
        $this->assertSame(0, DB::table('marketing_messages')->count());
        $this->assertNotNull($socio['lead']);
        Http::assertNothingSent();
    }

    /**
     * Una inscripción por el planificador real y, como lo dejaría el
     * planificador con la puerta abierta, un trabajo de WhatsApp (para probar
     * el canal sin que N3 —`payment_links_disabled`— lo niegue antes).
     *
     * @param  array<string, mixed>  $vivo
     * @return array{enrollment: object, job: object}
     */
    private function enrollAndFakeJob(array $vivo, int $plantilla): array
    {
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertNotNull($e, 'la inscripción nace con el barrido');
        $hash = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        $t = (new OutreachStore)->insertJob($e, 0, 1, 'whatsapp_template', null, $plantilla, $hash, null,
            now()->toDateTimeImmutable(), now()->addHours(2)->toDateTimeImmutable(), '2026-11-17', ['units' => 1, 'micros' => 50000], now()->toDateTimeImmutable());
        $this->assertNotNull($t);

        return ['enrollment' => $e, 'job' => DB::table('outreach_jobs')->where('id', $t['id'])->first()];
    }
}
