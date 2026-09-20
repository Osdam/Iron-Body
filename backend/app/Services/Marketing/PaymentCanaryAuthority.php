<?php

namespace App\Services\Marketing;

/**
 * Quién puede acuñar un cobro real, y para quién.
 *
 * Hasta ahora el permiso era un booleano: o se podían generar cobros o no se
 * podía ninguno. Eso servía mientras la respuesta fuera «ninguno», pero deja
 * de servir en cuanto alguien quiere ver UN cobro real antes de abrir el
 * grifo: encender la bandera para probar con una persona lo habría encendido
 * para todas, y eso no es un canario, es el despliegue entero con otro nombre.
 *
 * Aquí el permiso deja de ser «sí o no» y pasa a ser «sí, y para quién»:
 *
 *   GLOBAL    la bandera del negocio. Cuando está encendida vale para todos,
 *             y es la que se enciende el día que esto salga de pruebas.
 *   CANARIO   una ÚNICA conversación, nombrada por id. Mientras el global esté
 *             apagado, es la única puerta, y se cierra sola para todo lo demás.
 *
 * Es pura: no lee configuración ni base de datos, sólo decide con lo que se le
 * da. Quien la consulta es {@see SalesPaymentReadinessService}, y el cerrojo
 * que de verdad manda está en {@see SalesPaymentGuardrailService}, justo antes
 * de acuñar nada.
 *
 * DOS COSAS QUE NO HACE, y las dos son deliberadas:
 *
 *  - No acepta una conversación «por defecto». Sin id no hay permiso: un
 *    llamador que no sabe de quién habla no puede cobrarle a nadie.
 *  - No mira la fase, ni el plan, ni el lead. Eso ya lo deciden otros y
 *    mezclarlo aquí convertiría un cerrojo simple en una opinión.
 */
final class PaymentCanaryAuthority
{
    public const GLOBAL_ENABLED = 'global_enabled';

    public const CANARY_CONVERSATION = 'canary_conversation';

    public const NO_CONVERSATION = 'no_conversation';

    public const OUTSIDE_CANARY = 'outside_canary';

    public const DISABLED = 'payment_links_disabled';

    /**
     * ¿Se puede acuñar un cobro para esta conversación?
     *
     * @param  bool  $global  la bandera del negocio
     * @param  ?int  $canario  la conversación del canario, o null/0 si no hay
     * @param  ?int  $conversacion  para quién se pide
     * @return array{allowed:bool, via:string}
     */
    public function decide(bool $global, ?int $canario, ?int $conversacion): array
    {
        if ($global) {
            // La bandera del negocio vale para todos, también para quien no
            // dice de qué conversación habla: ese es su significado.
            return ['allowed' => true, 'via' => self::GLOBAL_ENABLED];
        }

        $canario = ($canario !== null && $canario > 0) ? $canario : null;

        if ($canario === null) {
            return ['allowed' => false, 'via' => self::DISABLED];
        }

        if ($conversacion === null || $conversacion <= 0) {
            /*
             * Sin conversación no hay permiso, y esto no es una formalidad.
             * Es el caso de la llamada directa —un comando, una prueba, un
             * endpoint interno— que llega al generador sin decir para quién.
             * Antes ese camino heredaba el permiso global; ahora, con el
             * global apagado y un canario abierto, heredaría el del canario
             * sin ser el canario. Falla cerrado.
             */
            return ['allowed' => false, 'via' => self::NO_CONVERSATION];
        }

        return $conversacion === $canario
            ? ['allowed' => true, 'via' => self::CANARY_CONVERSATION]
            : ['allowed' => false, 'via' => self::OUTSIDE_CANARY];
    }
}
