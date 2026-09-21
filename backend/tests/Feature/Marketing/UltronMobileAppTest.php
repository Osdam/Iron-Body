<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\MobileAppLinks;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronCommitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lo que el modelo puede decir de la app sale de un catálogo escrito desde el
 * código real de la app (no de nombres de carpetas ni de la imaginación), y los
 * enlaces oficiales los envía Laravel en su propio mensaje cuando el modelo lo
 * pide con `app_links_send`. El modelo nunca escribe una URL de la app.
 */
class UltronMobileAppTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_INTERESTED]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->assertOk();
    }

    private function commit(MarketingMessage $m, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge([
                'strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te paso los enlaces de la app para que la descargues.', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => ['app_links_send'],
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    /** Cuántos mensajes con los enlaces oficiales ha visto esta conversación. */
    private function linkMessages(): int
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->where('body', 'like', '%'.MobileAppLinks::IOS.'%')
            ->count();
    }

    /** @return array<int,string> la entrada del catálogo con esa clave. */
    private function feature(string $key): array
    {
        foreach (MobileAppCatalog::FEATURES as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }

        $this->fail("El catálogo no tiene la entrada «{$key}».");
    }

    public function test_decide_hands_the_model_the_app_catalogue_built_from_the_real_app(): void
    {
        $app = $this->decide($this->inbound('qué puedo hacer en la app?', 'a.1'))->json('context.app');

        $this->assertSame(['android' => MobileAppLinks::ANDROID, 'ios' => MobileAppLinks::IOS, 'web' => MobileAppLinks::WEB], $app['links']);
        $keys = array_column($app['features'], 'key');
        foreach (['otp_login', 'membership_and_renewal', 'wompi_payments', 'class_reservations', 'workouts', 'nutrition', 'progress', 'store', 'iron_ai_coach', 'weekly_streak'] as $k) {
            $this->assertContains($k, $keys, "la app sí tiene $k");
        }
        foreach ($app['features'] as $f) {
            $this->assertNotEmpty($f['title']);
            $this->assertNotEmpty($f['what']);
        }
        $this->assertStringContainsString('SMS', $app['help']['login'], 'el acceso es con el documento y un código por SMS');
        $this->assertStringContainsString('documento', mb_strtolower($app['help']['register']));
        $this->assertFalse($app['account']['has_account'], 'este prospecto no tiene cuenta');
        $this->assertStringNotContainsString('3150536026', json_encode($app));
    }

    public function test_the_model_may_ask_laravel_to_send_the_official_links(): void
    {
        $m = $this->inbound('mándame el link de la app', 'a.2');
        $this->assertContains('app_links_send', $this->decide($m)->json('allowed_tools'), 'no hay dinero en juego: siempre disponible');

        $r = $this->commit($m)->assertOk();

        /*
         * UN SOLO MENSAJE, y las URLs las sigue escribiendo Laravel.
         *
         * Antes eran dos globos seguidos —la prosa y, detrás, los enlaces— y en
         * el canario físico eso se leyó como que el asistente se repetía.
         */
        $salientes = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->get();
        $this->assertCount(1, $salientes, 'la respuesta y los enlaces son el mismo mensaje');

        $cuerpo = (string) $salientes->first()->body;
        $this->assertStringStartsWith('Claro, te paso los enlaces', $cuerpo, 'primero la prosa del modelo');
        foreach ([MobileAppLinks::ANDROID, MobileAppLinks::IOS, MobileAppLinks::WEB] as $url) {
            $this->assertStringContainsString($url, $cuerpo);
        }
        $this->assertContains('app_links_send', $r->json('applied.tools_executed'));
        $this->assertContains('app_links_send', MarketingAiAction::latest('id')->first()->metadata['tools_executed']);
        $this->assertNotNull($this->conversation->fresh()->memory['app_context']['links_sent_at'] ?? null, 'la memoria sabe que ya se enviaron');
    }

    public function test_the_model_never_writes_the_app_url_itself(): void
    {
        $r = $this->commit($this->inbound('mándame el link de la app', 'a.3'), ['reply_draft' => 'Descárgala en https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout', 'tools_requested' => []]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_URL_IN_REPLY);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }

    public function test_without_the_tool_no_links_go_out(): void
    {
        $this->commit($this->inbound('hola', 'a.4'), ['reply_draft' => 'Hola, ¿qué quieres lograr entrenando?', 'tools_requested' => []])->assertOk();

        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', 'like', '%apps.apple.com%')->count());
    }

    /**
     * Lo que el catálogo decía del acceso era falso: «con el número de documento
     * o el teléfono». La pantalla de login tiene UN campo, «Número de documento»
     * (lib/features/auth/screens/login_screen.dart:438), bajo el subtítulo
     * «Accede con tu documento o biometría» (:425), y el backend solo acepta
     * `document_number` (LoginMemberRequest::rules()). El teléfono no es una vía
     * de entrada. La biometría sí lo es (POST members/biometric-unlock,
     * routes/api.php:306) y el catálogo la callaba.
     */
    public function test_the_catalogue_describes_the_login_the_app_really_has(): void
    {
        $textos = [
            'features.otp_login' => $this->feature('otp_login')['what'],
            'help.login' => MobileAppCatalog::HELP['login'],
        ];

        foreach ($textos as $donde => $texto) {
            $n = SalesAgentDecisionSchema::normalize($texto);
            $this->assertStringNotContainsString('telefono', $n, "«{$donde}» sigue ofreciendo entrar con el teléfono; la app solo pide el documento.");
            $this->assertStringContainsString('documento', $n, "«{$donde}» no nombra el documento, que es lo único que pide la app.");
            $this->assertStringContainsString('biometr', $n, "«{$donde}» calla la biometría, que la app sí tiene.");
        }
    }

    /**
     * El registro tampoco era el que dice la app. `register_screen.dart` declara
     * seis pasos en su cabecera (:40-46) y sus campos del paso 1 son documento,
     * nombre, correo y teléfono (:1044-1078); la validación de identidad con
     * foto del documento y OCR es el paso 3, y la verificación facial es
     * OPCIONAL (:106, :1571-1585). No hay SMS en ninguna parte del flujo: ni en
     * la pantalla ni en member_registration_service / identity_verification_service
     * / face_verification_service.
     */
    public function test_the_catalogue_describes_the_registration_the_app_really_has(): void
    {
        $n = SalesAgentDecisionSchema::normalize(
            $this->feature('registration')['what'].' '.MobileAppCatalog::HELP['register'],
        );

        $this->assertStringNotContainsString('sms', $n, 'El registro no envía ningún código: no hay SMS en register_screen.dart ni en sus servicios.');
        $this->assertStringContainsString('documento', $n, 'El registro pide el documento y su foto.');
        $this->assertStringContainsString('facial', $n, 'El paso 6 del registro es la verificación facial.');
        $this->assertStringContainsString('opcional', $n, 'Y es opcional: se puede crear la cuenta sin ella.');
    }

    /**
     * El enlace del pago hecho por WhatsApp ya NO es automático: lo verifica el
     * equipo y avisa. El catálogo lo prometía dos veces («queda enlazado al
     * registrarte», «tu membresía queda enlazada al terminar») y una promesa así
     * en boca del modelo es una que el CRM no cumple.
     */
    public function test_the_catalogue_no_longer_promises_an_automatic_link_of_the_whatsapp_payment(): void
    {
        $textos = [
            'features.registration' => $this->feature('registration')['what'],
            'help.register' => MobileAppCatalog::HELP['register'],
            'help.membership_not_visible' => MobileAppCatalog::HELP['membership_not_visible'],
        ];

        foreach ($textos as $donde => $texto) {
            $n = SalesAgentDecisionSchema::normalize($texto);
            $this->assertStringNotContainsString('queda enlazad', $n, "«{$donde}» promete un enlace que ya no ocurre solo.");
            $this->assertStringNotContainsString('al registrarse', $n, "«{$donde}» ata el enlace al registro.");
            $this->assertStringNotContainsString('al terminar', $n, "«{$donde}» ata el enlace al final del registro.");
        }

        $this->assertStringContainsString(
            'verific',
            SalesAgentDecisionSchema::normalize(MobileAppCatalog::HELP['membership_not_visible']),
            'Lo que sí ocurre —el equipo verifica el pago y avisa— tiene que estar dicho.',
        );
    }

    /**
     * La memoria anotaba `app_context.links_sent_at` y nadie la leía: el modelo
     * podía pedir los tres enlaces en cada turno y Laravel los mandaba en cada
     * turno. Dentro de la hora se responde `skipped/already_sent` sin despachar.
     */
    public function test_the_links_do_not_go_out_twice_within_the_hour(): void
    {
        $this->commit($this->inbound('mándame el link de la app', 'a.5'))->assertOk();
        $primera = $this->conversation->fresh()->memory['app_context']['links_sent_at'] ?? null;
        $this->assertNotNull($primera);
        $this->assertSame(1, $this->linkMessages());

        $r = $this->commit($this->inbound('y para iPhone?', 'a.6'), ['reply_draft' => 'Claro, también está para iPhone.'])->assertOk();

        $this->assertNotContains('app_links_send', $r->json('applied.tools_executed'), 'los enlaces salieron hace un minuto');
        $this->assertSame(1, $this->linkMessages(), 'los enlaces siguen habiendo salido una sola vez');
        $this->assertSame($primera, $this->conversation->fresh()->memory['app_context']['links_sent_at'], 'la marca de la memoria no se reescribe');

        $freno = (new ReflectionMethod(UltronCommitService::class, 'appLinksDecision'))->invoke(
            app(UltronCommitService::class),
            $this->conversation->fresh(),
            ['tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND]],
            'da igual el borrador: la herramienta ya va pedida',
        );
        $this->assertSame(
            ['tool' => SalesIntents::TOOL_APP_LINKS_SEND, 'status' => 'skipped', 'reason' => 'already_sent', 'sent_at' => $primera],
            $freno,
        );
    }

    /**
     * Pasada la hora vuelve a ser una petición legítima: quien los perdió los
     * pide otra vez.
     *
     * OJO con lo que este test NO cubre, porque la causa está fuera de este
     * cambio: la marca de la memoria no se refresca en este segundo envío.
     * `ConversationMemoryService::recordAppLinksSent()` la escribe con
     * `$ctx + ['links_sent_at' => ...]`, y la unión de arrays de PHP NO pisa
     * una clave que ya existe. El freno, por tanto, solo muerde durante la
     * primera hora de la conversación: después la marca queda congelada y cada
     * turno vuelve a despachar. Se comprobó ejecutando este mismo test con una
     * sonda: tras viajar 61 minutos y reenviar, la marca seguía siendo la del
     * primer envío. Arreglarlo es un `array_merge` en ese servicio, que no
     * pertenece a este cambio.
     */
    public function test_after_the_window_the_links_can_go_out_again(): void
    {
        $this->commit($this->inbound('mándame el link de la app', 'a.7'))->assertOk();
        $this->assertSame(1, $this->linkMessages());
        $primeraMarca = data_get($this->conversation->fresh()->memory, 'app_context.links_sent_at');
        $this->assertIsString($primeraMarca);

        $this->travel(UltronCommitService::APP_LINKS_RESEND_MINUTES + 1)->minutes();
        $r = $this->commit($this->inbound('se me borró, me lo reenvías?', 'a.8'), ['reply_draft' => 'Claro, aquí van otra vez.'])->assertOk();

        $this->assertContains('app_links_send', $r->json('applied.tools_executed'));
        $this->assertSame(2, $this->linkMessages());

        // La marca se REESCRIBE con el reenvío: con la unión `+` de arrays quedaba
        // congelada en el primer envío y el freno solo mordía la primera hora.
        $segundaMarca = data_get($this->conversation->fresh()->memory, 'app_context.links_sent_at');
        $this->assertNotSame($primeraMarca, $segundaMarca, 'la marca debe refrescarse con cada envío');

        $this->travel(1)->minutes();
        $r3 = $this->commit($this->inbound('otra vez por favor', 'a.9'), ['reply_draft' => 'Los tienes justo arriba.'])->assertOk();

        $this->assertNotContains('app_links_send', (array) $r3->json('applied.tools_executed'), 'un minuto después del reenvío, el freno vuelve a morder');
        $this->assertSame(2, $this->linkMessages());
    }

    /**
     * `app_links_send` entró en ALLOWED_TOOLS —que es lo que reutiliza el
     * validador de ULTRON— y con ello se coló en el prompt del flujo LEGADO,
     * donde `SalesAgentOrchestratorService::execute()` la manda a
     * `unknown_tool`. Un prompt no anuncia herramientas que su flujo no ejecuta.
     */
    public function test_the_legacy_prompt_does_not_announce_a_tool_it_cannot_execute(): void
    {
        $prompt = app(SalesAgentPromptBuilder::class)->systemPrompt();

        $this->assertStringNotContainsString(SalesIntents::TOOL_APP_LINKS_SEND, $prompt, 'Hermes no ejecuta app_links_send.');
        $this->assertStringContainsString(SalesIntents::TOOL_PAYMENT_LINK_SEND, $prompt, 'payment_link_send sí la ejecuta el flujo legado: sigue anunciada.');
        $this->assertContains(SalesIntents::TOOL_APP_LINKS_SEND, SalesAgentDecisionSchema::ALLOWED_TOOLS, 'el validador que reutiliza ULTRON no cambia.');
    }

    // ── El marcador ───────────────────────────────────────────────────────────

    /**
     * El modelo puede decir DÓNDE van los enlaces sin escribir ni una URL.
     *
     * Es el mismo mecanismo del precio y por la misma razón: el borrador se
     * valida sin URL —`containsUrl()` sigue siendo absoluto— y Laravel escribe
     * la URL después. Y sirve de red: aunque la estrategia olvide pedir la
     * herramienta, el marcador basta, porque un desajuste entre lo que escribe
     * el modelo y lo que espera el backend es justo lo que deja turnos mudos.
     */
    public function test_the_marker_puts_the_links_where_the_model_wants_them(): void
    {
        $r = $this->commit($this->inbound('pásame la app', 'a.10'), [
            'reply_draft' => 'Claro, aquí la tienes: {{APP_LINKS}} — te registras con tu documento.',
            'tools_requested' => [],
        ])->assertOk();

        $salientes = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->get();
        $this->assertCount(1, $salientes);

        $cuerpo = (string) $salientes->first()->body;
        $this->assertStringContainsString('Claro, aquí la tienes: Android: ', $cuerpo, 'los enlaces van donde estaba el marcador');
        $this->assertStringContainsString('— te registras con tu documento.', $cuerpo, 'y el texto sigue después');
        $this->assertStringNotContainsString('{{', $cuerpo, 'ningún marcador llega a la persona');
        $this->assertContains('app_links_send', (array) $r->json('applied.tools_executed'));
    }

    /** Con el freno puesto el marcador no se queda escrito: se sustituye por la verdad. */
    public function test_within_the_window_the_marker_becomes_an_honest_sentence(): void
    {
        $this->commit($this->inbound('mándame la app', 'a.11'))->assertOk();
        $this->assertSame(1, $this->linkMessages());

        $r = $this->commit($this->inbound('y para iPhone?', 'a.12'), [
            'reply_draft' => 'También está para iPhone: {{APP_LINKS}}',
            'tools_requested' => [],
        ])->assertOk();

        $cuerpo = (string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()->body;

        $this->assertStringNotContainsString('{{', $cuerpo);
        $this->assertStringNotContainsString(MobileAppLinks::IOS, $cuerpo, 'no se repiten un minuto después');
        $this->assertStringContainsString(MobileAppCatalog::LINKS_ALREADY_SENT, $cuerpo);
        $this->assertSame(1, $this->linkMessages());
        $this->assertNotContains('app_links_send', (array) $r->json('applied.tools_executed'));
    }

    /** Un marcador que no existe sigue sin pasar: la lista blanca no se ha abierto. */
    public function test_an_invented_marker_is_still_refused(): void
    {
        $this->commit($this->inbound('pásame la app', 'a.13'), [
            'reply_draft' => 'Aquí la tienes: {{APP_STORE_URL}}',
            'tools_requested' => [],
        ])->assertStatus(422)->assertJsonPath('code', 'unknown_placeholder');
    }

    /**
     * LA RED DE LA NOVEDAD SIGUE ENCENDIDA CON LOS ENLACES DENTRO.
     *
     * Lo encontró la revisión: la novedad dura compara el borrador —que
     * todavía NO lleva los enlaces, porque se sustituyen después— contra lo
     * que quedó guardado, que sí los lleva. 192 caracteres de URL bajan el
     * parecido de 1.000 a 0.519, por debajo del umbral, y el mismo párrafo
     * podía salir dos veces. Con los enlaces fuera de la comparación, el
     * segundo turno vuelve a bloquearse.
     */
    public function test_the_same_paragraph_does_not_go_out_twice_because_of_the_links(): void
    {
        $mismo = 'Claro, te paso los enlaces para descargarla y registrarte con tu documento.';

        $this->commit($this->inbound('pásame la app', 'a.14'), ['reply_draft' => $mismo])->assertOk();

        $r = $this->commit($this->inbound('y para registrarme?', 'a.15'), ['reply_draft' => $mismo])->assertOk();

        /*
         * El párrafo repetido no sale; la persona sí recibe algo.
         *
         * Y los enlaces tampoco se reenvían: viajaban DENTRO del párrafo que
         * se descartó, así que anunciarlos en un texto que ya no los lleva
         * sería prometer lo que no va. Ésa es la razón de que la recuperación
         * deje fuera `app_links_send` y `payment_link_send` y no las demás.
         */
        $salientes = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->orderBy('id')->pluck('body')->all();

        $this->assertCount(2, $salientes, 'la persona se quedó sin respuesta');
        $this->assertNotSame($salientes[0], $salientes[1], 'salió dos veces el mismo párrafo');
        $this->assertStringNotContainsString('http', mb_strtolower((string) $salientes[1]), 'se reenviaron los enlaces en un texto que no era el suyo');
    }
}
