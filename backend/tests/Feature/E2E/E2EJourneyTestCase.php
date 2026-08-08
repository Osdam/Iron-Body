<?php

namespace Tests\Feature\E2E;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de los recorridos E2E de la fase F.3.
 *
 * La diferencia con el resto de la suite: aquí **no se invoca un método
 * interno**. Cada recorrido entra por donde entra la realidad —una petición
 * HTTP firmada al webhook de Meta, o una llamada autenticada del CRM— y
 * atraviesa controlador, validación de firma, ingesta, job, servicios,
 * observadores y persistencia. Lo único falso son las fronteras externas: Meta,
 * Wompi, Factus y OpenAI.
 *
 * Esa distinción importa porque los fallos que ha ido soltando este proyecto
 * —el cursor con microsegundos, el índice que no se usaba, el compositor que
 * aplastaba el hilo— no vivían dentro de un método, sino entre dos piezas que
 * por separado funcionaban.
 *
 * **Aislamiento**: cada recorrido corre sobre una base en memoria que se crea y
 * se destruye con la prueba. No toca ningún dato real, y `preventStrayRequests`
 * hace fallar cualquier petición que se escape a la red.
 */
abstract class E2EJourneyTestCase extends TestCase
{
    use RefreshDatabase;

    protected array $saHeaders = [];

    protected Admin $admin;

    /** Secreto del webhook para esta prueba. No es el de producción. */
    protected const WEBHOOK_SECRET = 'e2e-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Las banderas de esta suite son las de producción hoy: canal apagado,
        // agente apagado, autonomía apagada. Si un recorrido necesitara otra
        // cosa, tendría que decirlo explícitamente.
        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('marketing.agent_enabled', false);
        config()->set('commercial.autonomy_enabled', false);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.media.disk', 'whatsapp');

        Storage::fake('whatsapp');

        // Cualquier llamada a un servicio externo que no esté falseada revienta
        // la prueba. Es la garantía de que ningún recorrido toca Meta de verdad.
        Http::preventStrayRequests();
        Http::fake();

        $this->admin = Admin::create([
            'name' => 'E2E', 'email' => 'e2e-'.uniqid().'@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($this->admin);
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->saHeaders, $headers);
    }

    // ── Entrada real: el webhook de Meta ────────────────────────────────

    /**
     * Manda un evento al webhook FIRMADO, como lo haría Meta.
     *
     * Se firma de verdad: si la validación de firma se rompiera, estos
     * recorridos dejarían de pasar, que es justo lo que se quiere.
     */
    protected function metaWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/api/webhooks/meta',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_ACCEPT' => 'application/json',
            ],
            $raw,
        );
    }

    /**
     * Un mensaje entrante de WhatsApp con la forma exacta de Cloud API.
     *
     * @param  array<string,mixed>|null  $referral  bloque de click-to-WhatsApp
     */
    protected function inboundMessage(
        string $from,
        string $text,
        ?array $referral = null,
        ?string $waid = null,
        array $over = [],
    ): array {
        $message = array_merge([
            'from' => $from,
            'id' => $waid ?? 'wamid.'.uniqid(),
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => $text],
        ], $over);

        if ($referral !== null) {
            $message['referral'] = $referral;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-E2E',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '573143455483', 'phone_number_id' => 'PNID-E2E'],
                        'contacts' => [['profile' => ['name' => 'Prospecto E2E'], 'wa_id' => $from]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /** El referral de una pauta de Meta. */
    protected function adReferral(array $over = []): array
    {
        return array_merge([
            'source_type' => 'ad',
            'source_id' => 'AD-E2E-1',
            'source_url' => 'https://www.instagram.com/p/e2e/',
            'headline' => 'Plan mensual desde 90.000',
            'body' => 'Entrena en Iron Body Neiva',
            'media_type' => 'image',
            'ctwa_clid' => 'CLID-E2E',
        ], $over);
    }

    // ── Utilidades de dominio ───────────────────────────────────────────

    protected function plan(string $name, float $price, int $days = 30, bool $active = true): Plan
    {
        return Plan::create([
            'name' => $name, 'price' => $price, 'duration_days' => $days,
            'active' => $active, 'sort_order' => 1,
        ]);
    }

    protected function conversationFor(string $phone): ?MarketingConversation
    {
        $lead = MarketingLead::query()
            ->where('phone', $phone)
            ->orWhere('meta_user_id', $phone)
            ->first();

        return $lead === null
            ? null
            : MarketingConversation::query()->where('lead_id', $lead->id)->first();
    }

    protected function leadFor(string $phone): ?MarketingLead
    {
        return MarketingLead::query()
            ->where('phone', $phone)
            ->orWhere('meta_user_id', $phone)
            ->first();
    }

    /** Convierte un lead en miembro con usuario, como haría el alta real. */
    protected function makeMember(MarketingLead $lead, ?Plan $plan = null): Member
    {
        $user = User::create([
            'name' => 'Cliente E2E '.$lead->id,
            'email' => 'e2e'.$lead->id.'-'.uniqid().'@ironbody.test',
            'password' => 'x',
        ]);

        /*
         * El plan se asigna con `forceFill`, NO por asignación en masa.
         *
         * `plan_id` no es asignable en masa a propósito: si lo fuera, bastaría
         * mandarlo en el cuerpo de una petición para regalarse una membresía.
         * La activación real la hace el servicio de membresías tras un pago
         * aprobado, y esto imita ese camino sin abrir el agujero.
         */
        if ($plan !== null) {
            $user->forceFill([
                // La columna es `plan`, no `plan_id`: guarda el nombre del
                // plan, y es la que mira el servicio de membresias para decidir
                // si alguien esta activo.
                'plan' => $plan->name,
                // No existe `membership_status` en `users`: el estado se deduce
                // del plan y de la fecha de fin, que es lo que mira el servicio
                // de membresias. Ponerlo era inventarse una columna.
                'membership_start_date' => now(),
                'membership_end_date' => now()->addDays($plan->duration_days),
            ])->save();
        }

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Cliente E2E '.$lead->id,
            'document_number' => (string) (30000000 + $lead->id),
            'phone' => $lead->phone,
        ]);

        $lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }

    protected function payment(
        Member $member,
        float $amount,
        string $status = 'pending',
        ?\Illuminate\Support\Carbon $at = null,
    ): PaymentTransaction {
        $payment = PaymentTransaction::create([
            'reference' => 'E2E-'.uniqid(),
            'idempotency_key' => 'E2E-IDEM-'.uniqid('', true),
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'amount' => $amount,
            'currency' => 'COP',
            'status' => $status,
            'provider' => 'wompi',
        ]);

        $payment->forceFill([
            'created_at' => $at ?? now(),
            'paid_at' => $status === 'approved' ? ($at ?? now()) : null,
        ])->save();

        return $payment;
    }

    // ── Aserciones transversales ────────────────────────────────────────

    /**
     * Ningún recorrido puede haber tocado la red.
     *
     * Es la comprobación que separa «probado» de «probado sin mandarle un
     * mensaje a nadie».
     */
    protected function assertNoExternalCalls(): void
    {
        Http::assertNothingSent();
    }

    /** Con el canal apagado, nada sale entregado de verdad. */
    protected function assertNothingDelivered(): void
    {
        $this->assertSame(
            0,
            \App\Models\MarketingMessage::query()
                ->where('direction', 'outbound')
                ->whereNotNull('meta_message_id')
                ->count(),
            'Un mensaje quedó marcado como entregado por Meta.',
        );
    }

    /** El evento debe llevar un identificador de correlación de punta a punta. */
    protected function assertCorrelated(MarketingConversation $conversation): void
    {
        $message = \App\Models\MarketingMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($message, 'No se registró ningún mensaje.');
        $this->assertNotEmpty($message->correlation_id, 'El mensaje llegó sin correlation_id.');
    }
}
