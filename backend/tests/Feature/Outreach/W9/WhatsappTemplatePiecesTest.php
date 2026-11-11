<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Outreach\Facts\WhatsappRecipient;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * W9 · las piezas sueltas: la palabra de baja (lista CERRADA, el mensaje
 * entero, nunca interpretación), la plantilla de Marketing (lo que Meta
 * aceptaría, y su reconstrucción en cada intento), la rama de plantillas del
 * outbox (también en los reintentos, nunca como texto libre), los marcadores y
 * la traducción de lo que devuelve la puerta.
 */
class WhatsappTemplatePiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_la_palabra_de_baja_es_el_mensaje_entero_de_una_lista_cerrada(): void
    {
        foreach (['BAJA', 'baja', ' Baja. ', 'BAJA!!!', 'Baja 🙏', 'baja por favor', 'Por favor, BAJA', 'por favor baja gracias', 'Stop', 'STOP',
            'No más', 'no mas', 'NO MÁS MENSAJES', 'No me escriban más, gracias', 'no me escribas', 'Darme de baja', 'quiero darme de baja',
            'Detener promociones', 'stop promotions', 'Unsubscribe', 'no me contacten'] as $si) {
            $this->assertTrue(WhatsappSignals::isOptOutText($si), "«{$si}» es una baja");
        }
        foreach (['', '   ', 'gracias', 'por favor', 'hola', '¿qué es la baja?', 'no me interesa el plan anual', 'la bajada de precio',
            'no más tarde de las 5', 'stop motion', 'quiero saber cómo darme de baja de la clase de spinning', 'para', 'ok', '👍', null] as $no) {
            $this->assertFalse(WhatsappSignals::isOptOutText($no), '«'.var_export($no, true).'» no es una baja (no se interpreta)');
        }
        $this->assertFalse(WhatsappSignals::isOptOutText("\xB1\x31"), 'UTF-8 inválido no es nada');
    }

    public function test_la_plantilla_de_marketing_solo_nace_con_lo_que_meta_aceptaria(): void
    {
        $bien = new OutreachTemplateMessage('renovacion_w9', 'es_CO', 'utility', str_repeat('c', 64), ['Ana', '22 de noviembre'], "Hola Ana,\nvence el 22 de noviembre.");
        $this->assertSame([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana'], ['type' => 'text', 'text' => '22 de noviembre']]]], $bien->components());
        $this->assertSame([], (new OutreachTemplateMessage('sin_variables', 'es', 'marketing', str_repeat('c', 64), [], 'Hola.'))->components());

        $mal = [
            'nombre con mayúsculas' => ['Renovacion', 'es', 'marketing', str_repeat('c', 64), [], 'x'],
            'idioma' => ['renovacion', 'en', 'marketing', str_repeat('c', 64), [], 'x'],
            'categoría' => ['renovacion', 'es', 'authentication', str_repeat('c', 64), [], 'x'],
            'huella' => ['renovacion', 'es', 'marketing', 'abc', [], 'x'],
            'salto en un parámetro' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), ["Ana\nPérez"], 'x'],
            'tabulador' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), ["Ana\tPérez"], 'x'],
            'cinco espacios' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), ['Ana     Pérez'], 'x'],
            'parámetro vacío' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), [' '], 'x'],
            'once parámetros' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), array_fill(0, 11, 'a'), 'x'],
            'cuerpo vacío' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), [], '  '],
            'cuerpo largo' => ['renovacion', 'es', 'marketing', str_repeat('c', 64), [], str_repeat('a', 1025)],
        ];
        foreach ($mal as $que => $args) {
            try {
                new OutreachTemplateMessage(...$args);
                $this->fail("Nació una plantilla inválida: {$que}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_plantilla_se_reconstruye_de_lo_guardado_y_si_no_tiene_la_forma_no_sale(): void
    {
        $t = new OutreachTemplateMessage('renovacion_w9', 'es', 'marketing', str_repeat('d', 64), ['Ana'], 'Hola Ana.');
        $guardada = $t->metadata('7c0f2a54-3b1d-4e8f-9a6b-5c4d3e2f1a0b') + ['recipient' => '573001112233'];
        $this->assertTrue(OutreachTemplateMessage::isTemplate($guardada));
        $otra = OutreachTemplateMessage::fromStored($guardada, 'Hola Ana.');
        $this->assertNotNull($otra);
        $this->assertSame($t->components(), $otra->components());

        $this->assertFalse(OutreachTemplateMessage::isTemplate(['kind' => 'followup']));
        $this->assertNull(OutreachTemplateMessage::fromStored(['kind' => 'followup'], 'x'));
        $roto = $guardada;
        $roto['template']['parameters'] = ['Ana', ['no' => 'texto']];
        $this->assertNull(OutreachTemplateMessage::fromStored($roto, 'Hola Ana.'));
        $roto = $guardada;
        $roto['template']['name'] = 'Otra Cosa';
        $this->assertNull(OutreachTemplateMessage::fromStored($roto, 'Hola Ana.'));
        $this->expectException(InvalidArgumentException::class);
        $t->metadata('no-es-un-uuid');
    }

    public function test_el_outbox_entrega_la_plantilla_tambien_en_un_reintento_y_nunca_como_texto_libre(): void
    {
        config(['meta.enabled' => true, 'meta.access_token' => 'tok-w9', 'meta.app_secret' => 'sec-w9', 'meta.whatsapp_phone_number_id' => '555']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REINTENTO']]], 200)]);
        $conversacion = $this->conversation();
        $t = new OutreachTemplateMessage('renovacion_w9', 'es', 'marketing', str_repeat('e', 64), ['Ana'], 'Hola Ana. Responde BAJA para no recibir más.');
        // Un saliente de plantilla que falló con reintento (como lo dejaría un 429) y vuelve por el comando de reintentos.
        $m = MarketingMessage::create([
            'conversation_id' => $conversacion->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => $t->body,
            'status' => WhatsappOutboxService::STATUS_FAILED, 'send_attempts' => 1, 'next_attempt_at' => now()->subMinute(),
            'metadata' => $t->metadata('7c0f2a54-3b1d-4e8f-9a6b-5c4d3e2f1a0b') + ['recipient' => '573001112233'],
        ]);

        $r = app(WhatsappOutboxService::class)->deliver($m, '573001112233');

        $this->assertTrue($r['sent']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req): bool => $req['type'] === 'template' && $req['template']['name'] === 'renovacion_w9' && ! isset($req['text']));
        $this->assertSame('wamid.REINTENTO', $m->fresh()->meta_message_id);
    }

    public function test_el_outbox_no_envia_una_plantilla_con_la_forma_rota_ni_como_texto(): void
    {
        config(['meta.enabled' => true, 'meta.access_token' => 'tok-w9', 'meta.app_secret' => 'sec-w9', 'meta.whatsapp_phone_number_id' => '555']);
        Http::fake();
        $conversacion = $this->conversation();
        $m = MarketingMessage::create([
            'conversation_id' => $conversacion->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => 'Texto que nadie aprobó',
            'status' => WhatsappOutboxService::STATUS_QUEUED,
            'metadata' => ['kind' => OutreachTemplateMessage::KIND, 'template' => ['name' => 'renovacion_w9'], 'outreach_job_uuid' => '7c0f2a54-3b1d-4e8f-9a6b-5c4d3e2f1a0b'],
        ]);

        $r = app(WhatsappOutboxService::class)->deliver($m, '573001112233');

        $this->assertFalse($r['sent']);
        $this->assertFalse($r['retryable']);
        $this->assertSame('template_metadata_invalid', $r['reason']);
        $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $m->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_los_marcadores_que_w9_resuelve_y_los_que_no(): void
    {
        $this->assertSame('Ana', WhatsappRecipient::firstName('ana maría pérez'));
        $this->assertSame('José', WhatsappRecipient::firstName('JOSÉ'));
        $this->assertSame("D'arcy", WhatsappRecipient::firstName("d'arcy"));
        foreach ([null, '', '   ', '🔥🔥', 'X', 'Ana123', '@ana', str_repeat('a', 31)] as $raro) {
            $this->assertNull(WhatsappRecipient::firstName($raro), 'mejor ningún nombre que uno raro: '.var_export($raro, true));
        }
        $this->assertSame('1 de enero de 2027', WhatsappRecipient::dateText('2027-01-01'));
        $this->assertSame('29 de febrero de 2028', WhatsappRecipient::dateText('2028-02-29'));
        $this->assertNull(WhatsappRecipient::dateText('2027-02-29'));
        $this->assertSame(['days_to_expiry', 'first_name', 'gym_name', 'membership_end_date_text', 'plan_name'], WhatsappRecipient::RESOLVABLE);
        foreach (['class_name', 'class_time_text', 'payment_link', 'plan_price'] as $no) {
            $this->assertNotContains($no, WhatsappRecipient::RESOLVABLE, "{$no}: falla cerrado en W9");
        }
    }

    public function test_lo_que_devuelve_la_puerta_como_intento_del_canal(): void
    {
        $base = ['message_id' => null, 'sent' => false, 'dry_run' => false, 'will_retry' => false, 'replayed' => false, 'status' => null, 'provider_error_code' => null, 'reason' => null];
        $casos = [
            'guarda: no_proactive_consent' => [['reason' => 'no_proactive_consent'], 'failed_to_start', 'no_proactive_consent'],
            'guarda: agent_paused' => [['reason' => 'agent_paused'], 'failed_to_start', 'agent_paused'],
            'guarda con un motivo raro' => [['reason' => 'Algo Raro'], 'failed_to_start', 'gate_refused'],
            'simulacro' => [['message_id' => 7, 'dry_run' => true, 'reason' => 'meta_disabled_or_unconfigured'], 'failed_to_start', 'meta_disabled_or_unconfigured'],
            'aceptado por Meta' => [['message_id' => 7, 'sent' => true, 'status' => 'sent'], 'started', null],
            'con reintento en el outbox' => [['message_id' => 7, 'will_retry' => true, 'status' => 'failed'], 'started', null],
            'rechazo definitivo de Meta' => [['message_id' => 7, 'status' => 'dead', 'provider_error_code' => 132001, 'reason' => 'provider_send_failed'], 'failed_to_start', 'meta_error_132001'],
            'una persona tomó la conversación' => [['message_id' => 7, 'status' => 'dead', 'reason' => 'human_takeover'], 'failed_to_start', 'human_takeover'],
            'repetido y ya enviado' => [['message_id' => 7, 'replayed' => true, 'sent' => true, 'status' => 'sent'], 'started', null],
            'repetido y a medias' => [['message_id' => 7, 'replayed' => true, 'status' => 'queued'], 'ambiguous', 'already_dispatched'],
            'repetido y muerto' => [['message_id' => 7, 'replayed' => true, 'status' => 'dead'], 'failed_to_start', 'dead'],
        ];
        foreach ($casos as $que => [$r, $estado, $codigo]) {
            $intento = WhatsappTemplateChannel::fromGate($r + $base);
            $this->assertSame($estado, $intento->status, $que);
            $this->assertSame($codigo, $intento->detailCode, $que);
            if ($estado === 'started') {
                $this->assertSame(['marketing_message_id' => 7], $intento->refs, $que);
            }
        }
    }

    private function conversation(): MarketingConversation
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001112233', 'status' => MarketingLead::STATUS_NEW, 'consent_status' => 'granted']);

        return MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
    }
}
