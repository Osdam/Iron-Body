<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\Ultron\PaymentFactGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UNA BANDERA APAGADA NO ES UNA POLÍTICA DE NEGOCIO.
 *
 * Son dos hechos distintos y el asistente los estaba mezclando:
 *
 *   CAPACIDAD DESACTIVADA PARA EL CANARIO — el envío automático de links de
 *   pago por WhatsApp (`marketing.ultron.payment_links_enabled`). Reversible
 *   con una variable de entorno.
 *
 *   PROCESO MANUAL DEL NEGOCIO — «alguien del equipo confirma el medio de
 *   pago». NO EXISTE. Nunca existió. Pagar es algo que la persona hace sola,
 *   desde la app con Wompi o en el mostrador del gimnasio.
 *
 * Decir lo primero como si fuera lo segundo deja a alguien esperando un mensaje
 * que no va a llegar. Estas pruebas fijan que el texto que Laravel escribe dice
 * la verdad, y que sigue sin poder afirmar un pago ni ofrecer un traspaso.
 */
class PaymentCopyTest extends TestCase
{
    private SalesConversationReplyService $replies;

    private OutboundContentGuard $guard;

    private PaymentFactGuard $pagos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replies = new SalesConversationReplyService;
        $this->guard = new OutboundContentGuard;
        $this->pagos = new PaymentFactGuard;
    }

    public function test_the_payment_reply_names_the_two_real_paths(): void
    {
        $texto = $this->replies->paymentPendingReply();

        $this->assertStringContainsString('app Iron Body Workout', $texto, 'el camino que puede recorrer solo un cliente nuevo');
        $this->assertStringContainsString('documento', $texto, 'la cuenta se crea con el documento, no con el teléfono');
        $this->assertStringContainsStringIgnoringCase('gimnasio', $texto, 'y el mostrador, que también existe');
    }

    public static function inventosDeProcesoManual(): array
    {
        return [
            'el equipo confirma' => ['equipo confirma'],
            'confirma el medio' => ['confirma el medio'],
            'revisión manual' => ['revisión manual'],
            'proceso manual' => ['proceso manual'],
            'te escriben' => ['te escribe'],
            'un asesor te' => ['un asesor te'],
            'una persona del equipo' => ['una persona del equipo'],
        ];
    }

    #[DataProvider('inventosDeProcesoManual')]
    public function test_the_payment_reply_does_not_invent_a_manual_process(string $frase): void
    {
        $this->assertStringNotContainsStringIgnoringCase(
            $frase,
            $this->replies->paymentPendingReply(),
            'eso describe un trámite que no existe: deja a la persona esperando',
        );
    }

    /** Ni promete el link que hoy está apagado, ni explica por qué no lo hay. */
    public function test_the_payment_reply_never_mentions_a_payment_link(): void
    {
        $texto = mb_strtolower($this->replies->paymentPendingReply());

        $this->assertStringNotContainsString('link', $texto);
        $this->assertStringNotContainsString('http', $texto);
    }

    /** Y sigue sin ofrecer traspaso ni afirmar un pago en ningún estado del CRM. */
    public function test_the_payment_reply_passes_every_outbound_guard(): void
    {
        $texto = $this->replies->paymentPendingReply();

        foreach ([false, true] as $handoffPermitido) {
            $r = $this->guard->inspect($texto, MarketingMessage::SENDER_AI, $handoffPermitido);
            $this->assertTrue($r['safe'], 'el guard de salida lo rechaza: '.($r['code'] ?? ''));
        }

        $this->assertNull($this->guard->handoffOfferIn($texto), 'no ofrece pasar con nadie');

        foreach (['none', 'pending', 'approved', 'declined', 'expired'] as $estado) {
            $this->assertNull(
                $this->pagos->contradiction($texto, $estado),
                "afirma algo del pago que el CRM no respalda con state=$estado",
            );
        }
    }

    /** El texto de los enlaces de la app sigue siendo de Laravel, palabra por palabra. */
    public function test_laravel_still_owns_the_app_links_text(): void
    {
        $this->assertStringContainsString('play.google.com', MobileAppCatalog::linksInline());
        $this->assertStringContainsString('apps.apple.com', MobileAppCatalog::linksInline());
        $this->assertStringNotContainsString('http', MobileAppCatalog::LINKS_ALREADY_SENT);
    }
}
