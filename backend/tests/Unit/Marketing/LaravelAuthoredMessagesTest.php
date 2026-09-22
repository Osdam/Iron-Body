<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\ApprovedPaymentClaimer;
use App\Services\Marketing\MarketingPaymentOutcomeService;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\MobileAppLinks;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronCommitService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Los textos que escribe LARAVEL salen sin pasar por el guard.
 *
 * Y con razón: {@see OutboundContentGuard} filtra el texto de una MÁQUINA
 * ajena (el modelo, n8n), no el que compone Laravel desde `Plan::price` y sus
 * propias constantes —lo dice su cabecera—. El precio del mensaje del link es
 * la verdad de la base de datos y el enlace de la tienda lo pone el CRM: si el
 * guard los mirara, los bloquearía por «precio inventado» y por URL.
 *
 * El agujero está en el resto. Nadie comprueba que esos textos no ofrezcan
 * pasar la conversación a una persona, no prometan activar una membresía y no
 * prometan resultados. Se escriben a mano, se editan a mano, y hasta aquí solo
 * los leía quien los cambiaba. Esta prueba es esa lectura, hecha por las
 * MISMAS reglas que se le aplican al modelo.
 *
 * Qué NO comprueba, y por qué: `inspect()` no mira URLs. La regla del enlace
 * (CODE_URL_IN_REPLY) la aplica {@see UltronCommitService}
 * sobre el BORRADOR del modelo, no el guard sobre el texto que sale. Por eso
 * aquí las URLs oficiales se retiran antes de inspeccionar: son de Laravel, no
 * del modelo, y sus dígitos (el id de la App Store) no son un precio.
 */
class LaravelAuthoredMessagesTest extends TestCase
{
    private OutboundContentGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new OutboundContentGuard;
    }

    /** El plan real del que sale el precio del mensaje del link (no persiste: solo se lee su nombre). */
    private function plan(): Plan
    {
        return new Plan(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30]);
    }

    /**
     * Cada texto que compone Laravel y sale a WhatsApp, con su nombre.
     *
     * Los mensajes privados se leen por reflexión A PROPÓSITO: copiarlos aquí
     * convertiría la prueba en una fotocopia que sigue pasando cuando el texto
     * de producción cambia, que es justo lo que hay que detectar.
     *
     * @return array<string,string>
     */
    private function textos(): array
    {
        $outcome = (new ReflectionClass(MarketingPaymentOutcomeService::class))->newInstanceWithoutConstructor();
        $onboarding = new ReflectionMethod(MarketingPaymentOutcomeService::class, 'onboardingMessage');
        $lead = new MarketingLead(['name' => 'Ana Pérez', 'channel' => 'whatsapp']);

        $textos = [
            'enlaces de la app' => MobileAppCatalog::linksMessage(),
            'mensaje del link de pago' => (new SalesConversationReplyService)->paymentLinkMessage(
                $this->plan(), 80000.0, 'https://checkout.wompi.co/l/VPOS_test',
            ),
            'inicio con cuenta en la app' => (string) $onboarding->invoke($outcome, $lead, $this->plan(), true),
            'inicio sin cuenta en la app' => (string) $onboarding->invoke($outcome, $lead, $this->plan(), false),
        ];

        // Sin `defined()`: la constante existe y si alguien la renombra esta
        // prueba tiene que caerse, no dejar de mirar el texto en silencio.
        $textos['aviso del pago por reclamar'] = ApprovedPaymentClaimer::NOTICE;

        return $textos;
    }

    /** El texto sin lo que Laravel pone por derecho propio: sus enlaces oficiales. */
    private function sinEnlacesOficiales(string $texto): string
    {
        return str_replace(
            [MobileAppLinks::ANDROID, MobileAppLinks::IOS, MobileAppLinks::WEB, 'https://checkout.wompi.co/l/VPOS_test'],
            ' ',
            $texto,
        );
    }

    /**
     * Lo que motivó todo: un texto nuestro que ofrece pasar con una persona
     * sale igual que si lo hubiera escrito el modelo, y a nadie le consta.
     */
    public function test_no_laravel_authored_message_offers_a_human_handoff(): void
    {
        foreach ($this->textos() as $nombre => $texto) {
            $this->assertNotSame('', trim($texto), "El texto «{$nombre}» está vacío.");
            $this->assertNull(
                $this->guard->handoffOfferIn($texto),
                "El texto «{$nombre}», escrito por Laravel, ofrece pasar la conversación a una persona.",
            );
        }
    }

    /** Ni acción prohibida (activar membresía, aprobar pago) ni promesa/diagnóstico. */
    public function test_no_laravel_authored_message_promises_a_forbidden_action_or_a_result(): void
    {
        foreach ($this->textos() as $nombre => $texto) {
            $this->assertNull(SalesAgentDecisionSchema::forbiddenSignalIn($texto), "El texto «{$nombre}» anuncia una acción prohibida.");
            $this->assertNull(SalesAgentDecisionSchema::unsafeSignalIn($texto), "El texto «{$nombre}» promete un resultado o diagnostica.");
        }
    }

    /**
     * El guard completo sobre cada texto, con los enlaces oficiales retirados.
     * El único que puede quedar señalado es el mensaje del link de pago, porque
     * lleva un precio: el REAL del plan, no uno inventado por nadie.
     */
    public function test_the_guard_has_nothing_to_object_beyond_the_price_laravel_owns(): void
    {
        foreach ($this->textos() as $nombre => $texto) {
            $r = $this->guard->inspect($this->sinEnlacesOficiales($texto), MarketingMessage::SENDER_AI);

            if ($nombre === 'mensaje del link de pago') {
                $this->assertSame(OutboundContentGuard::CODE_INVENTED_PRICE, $r['code'], 'El mensaje del link lleva precio: el del plan.');
                $this->assertStringContainsString('80.000', $texto, 'Y ese precio es el del plan, no otro.');

                continue;
            }

            $this->assertNull($r['code'], "El guard objeta el texto «{$nombre}»: {$r['code']}.");
        }
    }

    /** Ningún texto de Laravel pide datos de tarjeta, claves ni el código del SMS. */
    public function test_no_laravel_authored_message_asks_for_card_data(): void
    {
        foreach ($this->textos() as $nombre => $texto) {
            $this->assertFalse(
                OutboundContentGuard::containsCardDataRequest($texto),
                "El texto «{$nombre}» pide datos sensibles de pago.",
            );
        }
    }

    /**
     * El catálogo de la app no sale a WhatsApp, pero lo lee el modelo y de ahí
     * copia. Si una entrada ofrece pasar con alguien, el guard bloqueará la
     * respuesta que la repita y nadie sabrá que el texto venía de aquí.
     */
    public function test_the_app_catalogue_never_dictates_a_handoff(): void
    {
        foreach (MobileAppCatalog::FEATURES as $f) {
            $this->assertNull($this->guard->handoffOfferIn($f['what']), "La entrada «{$f['key']}» del catálogo ofrece pasar a una persona.");
        }
        foreach (MobileAppCatalog::HELP as $clave => $texto) {
            $this->assertNull($this->guard->handoffOfferIn($texto), "La ayuda «{$clave}» ofrece pasar a una persona.");
        }
    }

    /**
     * LOS TEXTOS DE RESPALDO NO SUENAN A MÁQUINA.
     *
     * El saludo curado decía «¿Buscas información de planes, ubicación o
     * quieres empezar con algún objetivo?» y los últimos recursos «puedes
     * preguntarme por planes, horarios, ubicación, clases…»: un menú y una
     * pregunta de objetivo a quien solo dijo «hola». Esta lectura fija que
     * ningún texto de respaldo enumere un menú, use «cliente», «usuario»,
     * «siguiente paso» o «puedes consultar», que el saludo no pregunte por
     * objetivo, plan o inscripción, y que los que cierran con pregunta no
     * lleven verbo de ofrecimiento (el detector de ofertas de la memoria los
     * registraría como oferta del agente). Los textos de pago y traspaso no
     * son de esta lectura: los gobierna su propia autoridad.
     */
    public function test_no_curated_fallback_sounds_like_a_menu_or_a_bot(): void
    {
        $replies = new SalesConversationReplyService;
        $fueraDeLectura = [
            SalesIntents::PAYMENT_LINK_REQUEST,
            SalesIntents::HIGH_INTENT_CLOSE,
            SalesIntents::HUMAN_REQUEST,
        ];
        $menu = ['puedes preguntarme por', 'puedes consultar', 'cliente', 'usuario', 'siguiente paso'];

        $textos = [];
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            if (in_array($intent, $fueraDeLectura, true)) {
                continue;
            }
            $t = $replies->replyFor($intent);
            if ($t !== null) {
                $textos["replyFor({$intent})"] = $t;
            }
            foreach ($replies->lastResorts($intent) as $i => $v) {
                $textos["lastResorts({$intent})[{$i}]"] = $v;
            }
        }
        // Y las bienvenidas de recepción: tres franjas, primera vez y «de nuevo».
        foreach (['buenos dias', 'buenas tardes', 'buenas noches'] as $franja) {
            foreach ([false, true] as $yaSaludado) {
                foreach ($replies->welcomeReplies($franja, $yaSaludado) as $i => $v) {
                    $textos["welcomeReplies({$franja}, ".($yaSaludado ? 'de nuevo' : 'primera').")[{$i}]"] = $v;
                }
            }
        }

        foreach ($textos as $nombre => $texto) {
            $bajo = mb_strtolower($texto);
            foreach ($menu as $frase) {
                $this->assertStringNotContainsString($frase, $bajo, "El texto «{$nombre}» dice «{$frase}».");
            }
        }

        $saludo = mb_strtolower((string) $replies->replyFor(SalesIntents::GREETING));
        $this->assertStringContainsString('bienvenido', $saludo);
        foreach (['objetivo', 'plan', 'inscri'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $saludo, "El saludo pregunta por «{$prohibido}» a quien solo dijo hola.");
        }

        $sinOfrecer = [
            $replies->replyFor(SalesIntents::GREETING),
            $replies->replyFor(SalesIntents::GENERAL_INFO),
            ...$replies->lastResorts(SalesIntents::GENERAL_INFO),
            ...$replies->welcomeReplies('buenos dias', false),
            ...$replies->welcomeReplies('buenas noches', true),
        ];
        foreach ($sinOfrecer as $texto) {
            $this->assertDoesNotMatchRegularExpression(
                '/\\b(quieres|te gustaria|te interesa|prefieres|deseas|te parece)\\b/u',
                SalesAgentDecisionSchema::normalize((string) $texto),
                "«{$texto}» cierra con un verbo de ofrecimiento.",
            );
        }
    }
}
