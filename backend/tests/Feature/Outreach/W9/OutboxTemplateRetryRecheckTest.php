<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * SEC · SC-10 de la revisión de seguridad del cierre: entre el primer intento
 * fallido de una plantilla del H9 y sus reintentos (30 s, 60 s, 120 s…) el
 * outbox no volvía a mirar la autorización de la persona ni la baja de su
 * número. Ahora, SOLO en la rama de plantillas y SOLO en los reintentos, el
 * outbox repite las guardas de la persona de la puerta
 * (`canReplyReactively()`, `canContactProactively()`, la baja de las hermanas
 * con el mismo número normalizado): si ya no se puede, el mensaje no se
 * reenvía y queda muerto con su motivo, visible en el Inbox.
 *
 * El texto libre de hoy (ULTRON Legacy, el Inbox) no pasa por esa rama: ni una
 * consulta más (la regresión dirigida de Marketing lo cubre entero).
 */
class OutboxTemplateRetryRecheckTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

    private const JOB_UUID = '7c0f2a54-3b1d-4e8f-9a6b-5c4d3e2f1a0b';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        config(['meta.enabled' => true, 'meta.access_token' => 'tok-sc10', 'meta.app_secret' => 'sec-sc10', 'meta.whatsapp_phone_number_id' => '555']);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sc10_un_reintento_de_plantilla_vuelve_a_mirar_la_autorizacion_y_la_baja_del_numero(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SC10']]], 200)]);
        $casos = [
            'la autorización ya no está vigente' => [['consent_status' => 'pending'], null, 'no_proactive_consent'],
            'la persona rechazó el tratamiento' => [['consent_status' => 'denied'], null, 'do_not_contact'],
            'la persona pidió «no contactar»' => [['do_not_contact' => true], null, 'do_not_contact'],
            // La hermana guardada con otro formato del MISMO número: la puerta los compara normalizados, el reintento también.
            'una hermana con su número pidió «no contactar»' => [[], ['phone' => '+57 300 111 2233', 'do_not_contact' => true], 'do_not_contact_sibling'],
        ];
        foreach ($casos as $que => [$cambio, $hermana, $motivo]) {
            [$lead, $m] = $this->templateWaitingForRetry('3001112233');
            if ($hermana !== null) {
                MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'status' => MarketingLead::STATUS_NEW] + $hermana);
            }
            $lead->forceFill($cambio)->save();

            $r = app(WhatsappOutboxService::class)->deliver($m, '573001112233');

            $this->assertSame(['sent' => false, 'provider_message_id' => null, 'reason' => $motivo, 'retryable' => false], $r, $que);
            $m->refresh();
            $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $m->status, $que);
            $this->assertNull($m->next_attempt_at, $que);
            $this->assertNull($m->last_error_code, "{$que}: no lo paró Meta");
            $this->assertSame($motivo, $m->last_error_message, $que);
            $this->assertSame($motivo, data_get($m->metadata, 'failure.code'), $que);
            $this->assertNotEmpty(data_get($m->metadata, 'failure.title'), "{$que}: el Inbox lo explica");
            $this->assertSame(1, (int) $m->send_attempts, "{$que}: no hubo intento");
            $this->assertSame(OutreachTemplateMessage::KIND, data_get($m->metadata, 'kind'), "{$que}: la plantilla sigue siendo la plantilla");
            // Otro reintento tampoco: muerto es definitivo.
            $this->assertSame('dead', app(WhatsappOutboxService::class)->deliver($m, '573001112233')['reason'], $que);
            MarketingLead::query()->delete();
        }
        Http::assertNothingSent();

        // Control: con la persona igual que al nacer, el reintento sale como plantilla.
        [, $m] = $this->templateWaitingForRetry('3001112233');
        $this->assertTrue(app(WhatsappOutboxService::class)->deliver($m, '573001112233')['sent']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req): bool => $req['type'] === 'template');
    }

    public function test_sc10_por_el_comando_de_reintentos_hasta_el_libro_del_h9(): void
    {
        // Meta: el primer intento, un 429 (pasajero); el que vendría después, aceptado.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit hit', 'type' => 'OAuthException', 'code' => 130429]], 429)
            ->push(['messages' => [['id' => 'wamid.NO.DEBE.SALIR']]], 200)]);
        $this->whatsappLive();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $this->whatsappStrategy($plantilla);
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $plan = $planner->planDue(now());
        $this->assertSame(1, $plan['planned'], json_encode($plan).' '.json_encode(DB::table('outreach_decisions')->get(['kind', 'level', 'reasons'])));
        Carbon::setTestNow(now()->addMinutes(15));
        $this->assertSame(['started' => 1], $this->openDispatcher()->dispatch(now(), null, true)['results'], 'con su reintento programado en el outbox');
        $mensaje = MarketingMessage::query()->sole();
        $this->assertSame([WhatsappOutboxService::STATUS_FAILED, 1], [$mensaje->status, (int) $mensaje->send_attempts]);
        $this->assertNotNull($mensaje->next_attempt_at);

        // Mientras espera, la persona pide que no le escriban (lo marca el Inbox).
        DB::table('marketing_leads')->where('id', $socio['lead'])->update(['do_not_contact' => true]);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->assertSame(0, Artisan::call('marketing:retry-outbox'));

        $mensaje->refresh();
        $this->assertSame([WhatsappOutboxService::STATUS_DEAD, 'do_not_contact', 1], [$mensaje->status, $mensaje->last_error_message, (int) $mensaje->send_attempts]);
        Http::assertSentCount(1);

        // Y el H9 lo lee como un envío que no llegó: `failed` del outbox, sin texto del proveedor.
        $r = (new WhatsappTemplateOutcomes)->run(now());
        $this->assertSame(['failed' => 1], $r['recorded']);
        $this->assertSame(['failed', 'meta_status', 'outbox_dead'], [
            DB::table('outreach_outcomes')->value('outcome'), DB::table('outreach_outcomes')->value('source'), DB::table('outreach_outcomes')->value('detail_code'),
        ]);
    }

    public function test_sc10_el_texto_libre_de_hoy_no_pasa_por_la_rama_nueva(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.LEGACY']]], 200)]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001112233', 'status' => MarketingLead::STATUS_NEW, 'consent_status' => 'granted']);
        $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        $m = MarketingMessage::create([
            'conversation_id' => $conversacion->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => 'Hola, ¿te ayudo con tu plan?',
            'status' => WhatsappOutboxService::STATUS_FAILED, 'send_attempts' => 1, 'next_attempt_at' => now()->subMinute(),
            'metadata' => ['kind' => 'followup', 'recipient' => '573001112233'],
        ]);
        $leads = 0;
        DB::listen(function (QueryExecuted $q) use (&$leads): void {
            if (str_contains($q->sql, 'marketing_leads')) {
                $leads++;
            }
        });

        $r = app(WhatsappOutboxService::class)->deliver($m, '573001112233');

        $this->assertTrue($r['sent']);
        $this->assertSame(0, $leads, 'el reintento del texto libre no consulta a la persona: su camino no cambió');
        Http::assertSent(fn (Request $req): bool => $req['type'] === 'text');
    }

    /**
     * Una plantilla del H9 que ya tuvo su primer intento (un 429) y espera su
     * reintento, con su prospecto autorizado tal y como estaba al nacer.
     *
     * @return array{0: MarketingLead, 1: MarketingMessage}
     */
    private function templateWaitingForRetry(string $phone): array
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $phone, 'status' => MarketingLead::STATUS_NEW, 'consent_status' => 'granted']);
        $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        $t = new OutreachTemplateMessage('renovacion_w9', 'es', 'marketing', str_repeat('e', 64), ['Ana'], 'Hola Ana. Responde BAJA para no recibir más.');
        $m = MarketingMessage::create([
            'conversation_id' => $conversacion->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => $t->body,
            'status' => WhatsappOutboxService::STATUS_FAILED, 'send_attempts' => 1, 'next_attempt_at' => now()->subMinute(),
            'last_error_code' => 130429, 'correlation_id' => self::JOB_UUID,
            'metadata' => $t->metadata(self::JOB_UUID) + ['recipient' => '57'.$phone],
        ]);

        return [$lead, $m];
    }
}
