<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;

/**
 * Guardrails de PAGO del agente comercial. Defensa en profundidad: aunque el
 * cerebro IA (n8n / futuro motor) proponga algo inseguro, AQUÍ se valida antes
 * de generar cualquier link. Reglas NO negociables:
 *
 *   - Nunca generar link sin AUTORIDAD para acuñarlo (ver más abajo).
 *   - Nunca generar link si el lead tiene do_not_contact=true.
 *   - Nunca aceptar `amount`/`amount_in_cents` desde el request: el monto es
 *     SIEMPRE autoritativo del backend (Plan::price).
 *   - Nunca generar link para un plan inactivo o sin precio válido.
 *   - Generar un link NUNCA activa membresía (eso es exclusivo del webhook
 *     Wompi aprobado / reconciliación / PaymentMembershipActivator).
 *   - Una venta NO se marca ganada hasta que el pago esté aprobado (la
 *     atribución la hace MarketingAttributionService desde un pago real).
 *   - Capturas/“comprobantes” enviados por el lead JAMÁS confirman un pago.
 *   - Descuentos/precios especiales no autorizados → escalar a humano.
 *
 * Las violaciones se lanzan como {@see SalesGuardrailException} (code + mensaje
 * saneado). No se loguean secretos ni montos libres del cliente.
 *
 * ── LA AUTORIDAD PARA ACUÑAR UN COBRO ───────────────────────────────────────
 *
 * Esta clase es el ÚNICO sitio donde se contesta «¿puede existir este enlace?».
 * Antes contestaba solo la mitad —a quién se le puede vender— y la otra mitad,
 * si el negocio autoriza a acuñar cobros, la consultaba únicamente la
 * herramienta de ULTRON. El resultado es el agujero que cierra este cambio: el
 * secreto interno, que es una máquina, generaba enlaces de pago REALES con
 * `MARKETING_ULTRON_PAYMENT_LINKS_ENABLED=false`.
 *
 * La regla no se repite en cada llamador —así nació el agujero—: vive aquí y
 * {@see WompiPaymentLinkService::generateForLead()} la invoca por TODOS, que es
 * el único punto por el que pasan todos los caminos.
 *
 * Dos orígenes, y la diferencia importa:
 *
 *   - {@see self::ORIGIN_AUTOMATIC} (por defecto, falla cerrado): cualquier
 *     máquina —n8n con el secreto interno, ULTRON, el orquestador legado, el
 *     subsistema Commercial, un job—. Exige la fórmula completa de
 *     {@see SalesPaymentReadinessService::canGenerateAutomaticLink()}: Wompi
 *     productivo Y permiso del negocio.
 *   - {@see self::ORIGIN_HUMAN_PANEL}: una PERSONA con sesión real del CRM
 *     generando el enlace a mano desde el panel. No la gobierna la bandera de
 *     la automatización, y el llamador debe dejar traza de quién fue.
 *
 * El origen NUNCA llega del payload: lo fija el controlador tras resolver la
 * sesión administrativa real (`auth_admin`), que el token compartido de
 * automatizaciones no obtiene.
 */
class SalesPaymentGuardrailService
{
    /** Lo pide una máquina, sin persona detrás. Es el valor por defecto. */
    public const ORIGIN_AUTOMATIC = 'automatic';

    /** Lo pide una persona con sesión real del CRM desde el panel. */
    public const ORIGIN_HUMAN_PANEL = 'human_panel';

    public function __construct(
        private readonly SalesPaymentReadinessService $readiness = new SalesPaymentReadinessService,
    ) {}

    /**
     * Valida que se PUEDA generar un link de pago para (lead, plan).
     *
     * @param  array  $input  payload tal cual llegó (para detectar montos prohibidos).
     * @param  array{origin?:string,admin_id?:int|null}  $authority  quién lo pide.
     *
     * @throws SalesGuardrailException
     */
    public function assertCanGeneratePaymentLink(MarketingLead $lead, Plan $plan, array $input = [], array $authority = []): void
    {
        // 1) do_not_contact o consentimiento denegado: bloqueo duro.
        if (! $lead->canReplyReactively()) {
            throw SalesGuardrailException::make(
                'lead_do_not_contact',
                'Este lead está marcado como no contactar (do_not_contact).',
            );
        }

        // 2) Monto autoritativo: el cliente/n8n NUNCA define el precio.
        foreach (['amount', 'amount_in_cents', 'price', 'total'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw SalesGuardrailException::make(
                    'amount_not_allowed',
                    'El monto no se acepta desde el cliente: es autoritativo del backend.',
                );
            }
        }

        /*
         * 3) ¿Se le puede vender esto a alguien?
         *
         * Una sola pregunta, contestada por la regla de dominio (Plan::isSellable),
         * no por condiciones repetidas aquí. Antes se comprobaban `active` y
         * precio > 0 por separado, y entre las dos se colaba lo que de verdad
         * importaba: un plan interno con precio simbólico —uno de 1 peso, dos
         * días— pasaba ambas y habría generado un cobro real.
         *
         * El motivo se desglosa sólo para el diagnóstico; quien decide es
         * isSellable().
         */
        if (! $plan->isSellable()) {
            [$code, $message] = match (true) {
                ! (bool) $plan->active => ['plan_inactive', 'El plan seleccionado no está activo.'],
                (float) $plan->price <= 0 => ['plan_price_invalid', 'El plan no tiene un precio válido para cobrar.'],
                (int) $plan->duration_days <= 0 => ['plan_duration_invalid', 'El plan no tiene una duración válida.'],
                default => ['plan_not_sellable', 'Ese plan no está a la venta.'],
            };

            throw SalesGuardrailException::make($code, $message);
        }

        // 4) La conversación que se alega tiene que ser de ESTE lead.
        $this->assertConversationBelongsToLead($lead, $authority);

        // 5) ¿Quién lo pide, y tiene autoridad para acuñar un cobro?
        $this->assertAuthorityToMint($authority);
    }

    /**
     * El id de conversación que decide el canario tiene que ser de este lead.
     *
     * Sin esto, el canario es un NÚMERO, y un número se escribe. Un llamador
     * que pueda nombrar la conversación del canario acuñaría un cobro real
     * para cualquier otra persona: el cerrojo comprobaría que el id coincide
     * —coincide— y nadie miraría de quién es.
     *
     * Hoy ningún camino lo permite, pero por accidente, no por diseño: el
     * endpoint interno vuelca `conversation_id` del CUERPO en las opciones del
     * generador (`InternalMarketingController:531`) y sólo se salva porque su
     * pre-chequeo llama al guardrail sin la autoridad y deniega antes. Su
     * controlador hermano del panel sí la pasa. El día que alguien armonice los
     * dos —una tarea natural, que además parece una mejora— ese campo del
     * cuerpo se convierte en la llave.
     *
     * La pertenencia es el invariante que hace segura toda la superficie, y no
     * depende de quién llame ni de qué se olvide de pasar. Va aquí, donde el
     * lead y la autoridad están juntos, y no en cada controlador.
     *
     * @param  array{conversation_id?:int|string|null}  $authority
     *
     * @throws SalesGuardrailException
     */
    private function assertConversationBelongsToLead(MarketingLead $lead, array $authority): void
    {
        $id = $authority['conversation_id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0) {
            // Sin id no hay nada que comprobar aquí; de eso responde el canario.
            return;
        }

        $duena = MarketingConversation::query()->whereKey((int) $id)->value('lead_id');

        if ($duena === null || (int) $duena !== (int) $lead->id) {
            throw SalesGuardrailException::make(
                'payment_conversation_mismatch',
                'Esa conversación no es de este lead: no se acuña un cobro con la conversación de otra persona.',
                escalate: true,
                httpStatus: 403,
            );
        }
    }

    /**
     * ¿Está autorizado ESTE solicitante a acuñar un cobro ahora mismo?
     *
     * Orden deliberado:
     *
     *  1. Sin configuración de Web Checkout no hay enlace para nadie, y de eso
     *     ya responde {@see WompiPaymentLinkService::generateForLead()} con su
     *     error controlado (`configured=false`, 503 y la lista de config que
     *     falta). Contestar aquí lo mismo con otras palabras solo cambiaría el
     *     mensaje del mismo «no» y rompería el contrato que n8n ya lee.
     *  2. La bandera del negocio manda sobre TODOS los orígenes, también sobre
     *     la persona del panel. Es un cerrojo, no una preferencia: mientras esté
     *     apagada no se acuña un cobro por ningún camino. El origen humano sigue
     *     distinguiéndose, pero para la TRAZA —quién lo pidió—, no para el
     *     permiso. Encenderla es una línea del entorno y devuelve el panel.
     *
     * La decisión la toma `canGenerateAutomaticLink()`, que es donde vive la
     * fórmula; `isProductionReady()` solo elige el MOTIVO, porque mandar a
     * revisar la pasarela cuando lo que falta es el permiso cuesta una tarde.
     *
     * @param  array{origin?:string,admin_id?:int|null}  $authority
     *
     * @throws SalesGuardrailException
     */
    private function assertAuthorityToMint(array $authority): void
    {
        if ($this->readiness->state() === SalesPaymentReadinessService::STATE_NOT_CONFIGURED) {
            return;
        }

        /*
         * EL CERROJO DEL CANARIO, y está aquí a propósito.
         *
         * Éste es el único punto por el que pasa TODO el que quiere acuñar un
         * cobro: el commit de ULTRON, el panel, un comando, una prueba. Poner
         * el permiso sólo donde se ofrece la herramienta habría dejado el
         * generador abierto a cualquiera que lo llamara directamente, y un
         * cerrojo que se puede rodear no es un cerrojo.
         *
         * Corre ANTES de que exista nada: sin referencia, sin fila de
         * transacción, sin URL. Una llamada fuera del canario se va con las
         * manos vacías y sin haber tocado la base de datos.
         *
         * `conversation_id` viaja en `$authority`, que lo construye el
         * llamador EN CÓDIGO —igual que `origin` y `admin_id`—; ningún
         * controlador vuelca aquí el cuerpo de una petición, así que no es un
         * campo que se pueda mandar desde fuera.
         */
        $conversacion = $authority['conversation_id'] ?? null;
        $veredicto = $this->readiness->canaryVerdict(
            is_numeric($conversacion) ? (int) $conversacion : null,
        );

        if ($veredicto['allowed'] && $this->readiness->isProductionReady()) {
            return;
        }

        $humano = $this->isVerifiedHumanPanel($authority);

        [$code, $message] = match (true) {
            ! $this->readiness->isProductionReady() => ['wompi_not_production', 'La pasarela de pago no está en producción: no se entrega un enlace de prueba como si fuera real.'],
            /*
             * El motivo distingue «apagado» de «no eres el canario», y no es
             * cosmética: durante el canario lo primero es el estado normal del
             * sistema y lo segundo es alguien llamando a una puerta que no le
             * toca. Leerlos igual en el log habría escondido el segundo.
             */
            $veredicto['via'] === PaymentCanaryAuthority::OUTSIDE_CANARY => ['payment_outside_canary', 'El cobro automático está abierto sólo para la conversación del canario.'],
            $veredicto['via'] === PaymentCanaryAuthority::NO_CONVERSATION => ['payment_conversation_required', 'No se acuña un cobro sin saber para qué conversación es.'],
            $humano => ['payment_links_disabled', 'La generación de enlaces de pago está desactivada por el negocio. Mientras lo esté, tampoco se generan a mano desde el panel.'],
            default => ['payment_links_disabled', 'La generación de enlaces de pago está desactivada por el negocio.'],
        };

        throw SalesGuardrailException::make($code, $message, escalate: true, httpStatus: 403);
    }

    /**
     * ¿Es una persona del panel, de verdad?
     *
     * Hacen falta las dos cosas: que el llamador declare el origen humano y que
     * traiga el id del administrador que ya resolvió la sesión. Un origen a
     * secas sería una cadena de texto haciendo de permiso.
     *
     * @param  array{origin?:string,admin_id?:int|null}  $authority
     */
    private function isVerifiedHumanPanel(array $authority): bool
    {
        return ($authority['origin'] ?? self::ORIGIN_AUTOMATIC) === self::ORIGIN_HUMAN_PANEL
            && (int) ($authority['admin_id'] ?? 0) > 0;
    }

    /**
     * Heurística mínima: ¿el lead pide un descuento/precio especial no
     * autorizado? El agente NO debe inventar promociones; debe escalar.
     * (Detección textual liviana; el cerebro IA puede afinarla luego.)
     */
    public function requestsUnauthorizedDiscount(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = mb_strtolower($text);
        foreach (['descuento', 'rebaja', 'promo', 'oferta', 'me dejas', 'precio especial', 'mas barato', 'más barato', 'cupon', 'cupón'] as $kw) {
            if (str_contains($needle, $kw)) {
                return true;
            }
        }

        return false;
    }
}
