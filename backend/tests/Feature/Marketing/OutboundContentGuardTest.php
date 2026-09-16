<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La frontera entre lo que escribe una MÁQUINA y lo que escribe una PERSONA.
 *
 * `POST /api/internal/marketing/send-message` es la puerta prevista para las
 * automatizaciones, y hasta ahora entregaba a Meta cualquier cuerpo que le
 * pasaran: el validador del cerebro vive dentro de los responders, así que un
 * texto que llegaba ya escrito no lo atravesaba nunca. Un precio inventado
 * salía igual que uno real.
 *
 * Lo que estas pruebas fijan es la asimetría, que es la parte que se rompe sola
 * si alguien decide "aplicarlo a todo por seguridad":
 *
 *   - máquina citando un precio  → se detiene, con 422 y sin mensaje.
 *   - persona citando un precio  → sale, porque es su trabajo.
 *
 * Meta nunca se toca: `meta.enabled=false` y `Http::fake()` con aserción de que
 * no salió nada.
 */
class OutboundContentGuardTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_SECRET = 'test-internal-secret';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Admin $admin;

    private array $adminHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::INTERNAL_SECRET);
        config()->set('meta.enabled', false);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_INTERESTED,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        $this->admin = Admin::create([
            'name' => 'Asesora', 'email' => 'asesora-guard@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->adminHeaders = $this->actingAsAdmin($this->admin);
    }

    private function internalHeaders(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.self::INTERNAL_SECRET], $extra);
    }

    private function sendAsMachine(string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'channel' => 'whatsapp',
            'body' => $body,
        ], $this->internalHeaders($headers));
    }

    private function sendAsHuman(string $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            '/api/admin/marketing/inbox/conversations/'.$this->conversation->id.'/messages',
            ['body' => $body],
            $this->adminHeaders,
        );
    }

    // ── K1 · la máquina no puede inventar un precio ───────────────────────────

    public function test_send_message_rejects_invented_price(): void
    {
        Http::fake();

        $this->sendAsMachine('El plan cuesta $45.000 COP, aprovecha hoy.')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('safe_to_send', false);

        // Ni mensaje, ni llamada a Meta: se corta ANTES de que exista la fila.
        $this->assertDatabaseCount('marketing_messages', 0);
        Http::assertNothingSent();
    }

    /**
     * Las cuatro formas de escribir un precio que el validador ya reconocía.
     * Se prueban aquí porque el guard tiene que reconocer exactamente las
     * mismas: si un día divergen, este test lo dice antes que un cliente.
     */
    public function test_send_message_rejects_every_shape_of_price(): void
    {
        Http::fake();

        $casos = [
            'Son $80000 al mes',            // símbolo + dígito
            'Quedan 120.000 pesos',         // patrón de miles
            'El valor es 95000',            // cuatro cifras o más
            'Vale 90 COP mensuales',        // moneda nombrada
        ];

        foreach ($casos as $caso) {
            $this->sendAsMachine($caso)
                ->assertStatus(422)
                ->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE);
        }

        $this->assertDatabaseCount('marketing_messages', 0);
        Http::assertNothingSent();
    }

    // ── K1B · señales prohibidas e inseguras ya catalogadas ───────────────────

    public function test_send_message_rejects_forbidden_or_unsafe_machine_reply(): void
    {
        Http::fake();

        // FORBIDDEN_SIGNALS: intentar activar una membresía.
        $this->sendAsMachine('Listo, voy a activar membresia de una vez.')
            ->assertStatus(422)
            ->assertJsonPath('code', OutboundContentGuard::CODE_FORBIDDEN_ACTION)
            ->assertJsonPath('escalate', true);

        // UNSAFE_REPLY_SIGNALS: prometer un resultado físico.
        $this->sendAsMachine('Con el plan tienes resultados asegurados en un mes.')
            ->assertStatus(422)
            ->assertJsonPath('code', OutboundContentGuard::CODE_UNSAFE_CLAIM);

        // UNSAFE_REPLY_SIGNALS: diagnosticar.
        $this->sendAsMachine('Por lo que cuentas tienes una lesión de rodilla.')
            ->assertStatus(422)
            ->assertJsonPath('code', OutboundContentGuard::CODE_UNSAFE_CLAIM);

        $this->assertDatabaseCount('marketing_messages', 0);
        Http::assertNothingSent();
    }

    /** Lo que sí puede decir una máquina sigue saliendo igual que antes. */
    public function test_send_message_still_delivers_a_safe_machine_reply(): void
    {
        Http::fake();

        $this->sendAsMachine('Claro, te cuento los planes que tenemos. ¿Qué buscas lograr?')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('sender_type', MarketingMessage::SENDER_AI);

        $this->assertDatabaseHas('marketing_messages', [
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI,
            'status' => WhatsappOutboxService::STATUS_DRY_RUN,
        ]);
    }

    // ── K2 · la persona sí puede citar un precio ──────────────────────────────

    /**
     * El camino REAL del asesor: el endpoint del Inbox, que pasa por
     * MarketingManualReplyService con sender_type=human. No se fuerza el autor
     * sobre el endpoint interno, porque eso probaría un camino que no existe.
     */
    public function test_manual_reply_allows_price_from_human(): void
    {
        Http::fake();

        $this->sendAsHuman('El mensual está en $80.000 COP e incluye todo.')->assertOk();

        $message = MarketingMessage::where('sender_type', MarketingMessage::SENDER_HUMAN)->sole();
        $this->assertSame('El mensual está en $80.000 COP e incluye todo.', $message->body);
        $this->assertSame($this->admin->id, $message->sender_user_id);
        $this->assertSame(WhatsappOutboxService::STATUS_DRY_RUN, $message->status);
    }

    // ── K2B · el guard de máquina no toca el camino humano ────────────────────

    /**
     * Un asesor puede escribir cosas que a una máquina se le prohíben: confirmar
     * que él mismo va a activar la membresía, o dar una cifra. Son decisiones
     * suyas, con su nombre detrás. El filtro es para el texto sin autor humano.
     */
    public function test_manual_reply_path_is_not_filtered_by_machine_guard(): void
    {
        Http::fake();

        $frases = [
            'Tranquilo, yo te activo la membresia apenas llegues.',   // FORBIDDEN para máquina
            'Quedamos entonces en 120.000 pesos por el trimestre.',   // precio
        ];

        foreach ($frases as $frase) {
            $this->sendAsHuman($frase)->assertOk();
        }

        $this->assertSame(2, MarketingMessage::where('sender_type', MarketingMessage::SENDER_HUMAN)->count());
    }

    // ── El autor no se puede fingir desde el request ──────────────────────────

    public function test_sender_type_cannot_be_spoofed_from_the_request(): void
    {
        Http::fake();

        $res = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body' => 'El plan cuesta $45.000 COP',
            // Intento de pasar por humano para esquivar el filtro.
            'sender_type' => MarketingMessage::SENDER_HUMAN,
        ], $this->internalHeaders());

        $res->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE);

        $this->assertDatabaseCount('marketing_messages', 0);
        Http::assertNothingSent();
    }

    // ── X-Idempotency-Key: se acepta, se valida, NO deduplica todavía ─────────

    public function test_idempotency_key_is_echoed_but_not_yet_deduplicated(): void
    {
        Http::fake();

        $res = $this->sendAsMachine('Hola, ¿te ayudo?', ['X-Idempotency-Key' => 'wamid.HBgMNTc123']);

        $res->assertOk()
            ->assertJsonPath('idempotency.key', 'wamid.HBgMNTc123')
            // Honestidad explícita: hoy NO deduplica. Llega en /ai/commit (M2).
            ->assertJsonPath('idempotency.deduplicated', false);

        // Segunda llamada con la MISMA clave: hoy crea un segundo mensaje. Se
        // afirma para que el día que M2 lo cambie, este test obligue a mirarlo.
        $this->sendAsMachine('Hola, ¿te ayudo?', ['X-Idempotency-Key' => 'wamid.HBgMNTc123'])->assertOk();
        $this->assertDatabaseCount('marketing_messages', 2);
    }

    public function test_malformed_idempotency_key_is_discarded_not_fatal(): void
    {
        Http::fake();

        $this->sendAsMachine('Hola', ['X-Idempotency-Key' => 'clave con espacios y ñ'])
            ->assertOk()
            ->assertJsonPath('idempotency.key', null);
    }

    // ── El guard, como unidad ─────────────────────────────────────────────────

    public function test_guard_only_applies_to_machine_senders(): void
    {
        $guard = app(OutboundContentGuard::class);

        $this->assertTrue($guard->appliesTo(MarketingMessage::SENDER_AI));
        $this->assertTrue($guard->appliesTo(MarketingMessage::SENDER_SYSTEM));
        $this->assertFalse($guard->appliesTo(MarketingMessage::SENDER_HUMAN));
        $this->assertFalse($guard->appliesTo(MarketingMessage::SENDER_LEAD));

        // Mismo texto, dos autores, dos veredictos.
        $precio = 'El mensual está en $80.000 COP';
        $this->assertFalse($guard->inspect($precio, MarketingMessage::SENDER_AI)['safe']);
        $this->assertTrue($guard->inspect($precio, MarketingMessage::SENDER_HUMAN)['safe']);
    }

    /** Si un mensaje viola varias reglas, se reporta la más grave. */
    public function test_guard_reports_the_most_serious_violation_first(): void
    {
        $guard = app(OutboundContentGuard::class);

        $result = $guard->inspect(
            'Te garantizo resultados y activar membresia por $50.000',
            MarketingMessage::SENDER_AI,
        );

        $this->assertFalse($result['safe']);
        $this->assertSame(OutboundContentGuard::CODE_FORBIDDEN_ACTION, $result['code']);
        $this->assertSame('activar membresia', $result['signal']);
    }

    /** El motivo del bloqueo por precio no lleva el texto del mensaje al log. */
    public function test_guard_never_exposes_the_body_as_signal(): void
    {
        $guard = app(OutboundContentGuard::class);

        $result = $guard->inspect('Son $45.000 COP', MarketingMessage::SENDER_AI);

        $this->assertSame(OutboundContentGuard::CODE_INVENTED_PRICE, $result['code']);
        $this->assertNull($result['signal']);
    }
}
