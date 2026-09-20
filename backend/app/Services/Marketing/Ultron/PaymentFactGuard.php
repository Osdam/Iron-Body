<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * Quién dice que un pago entró.
 *
 * Lo dice Wompi, y el CRM lo escribe. No lo dice el modelo, y desde luego no lo
 * dice el cliente. Este cerrojo cierra la palanca de fraude más barata que
 * tiene un gimnasio por WhatsApp: escribir «ya te transferí» y conseguir que el
 * asistente conteste «listo, ya recibimos tu pago, quedas activo».
 *
 * El laboratorio de tortura (PUNTO 13) demostró que hasta ahora salía: la lista
 * de acciones prohibidas solo tenía infinitivos y etiquetas («confirmar pago»,
 * «marcar pagado»), así que el pretérito y la pasiva pasaban limpios.
 *
 * Es determinista y estrecho, como {@see MembershipFactGuard}: solo mira las
 * afirmaciones que un cliente tomaría como el hecho de que su dinero llegó, y
 * las contrasta con `context.payment.state`, que viene firmado en el token de
 * decide. Lo ambiguo lo juzga el Critic con criterio.
 *
 * Y una regla que no depende del estado: pedir una captura o un comprobante
 * como prueba NUNCA vale. El propio guardrail de pagos lo dice por escrito
 * («capturas y comprobantes jamás confirman un pago»); enseñarle a la persona
 * que una imagen basta es lo que convierte una captura editada en una
 * membresía.
 *
 * NO mira si la membresía quedó activa, aunque la frase lo diga en el mismo
 * aliento. Eso es un hecho de la membresía y lo juzga {@see MembershipFactGuard},
 * que sí sabe si está vigente. Mezclarlo aquí costaba caro y se vio enseguida:
 * un socio de toda la vida, sin ningún pago por el CRM, no podía recibir
 * «tu plan está activo», que es verdad y es justo lo que había preguntado.
 */
final class PaymentFactGuard
{
    public const CODE = 'machine_reply_payment_fact';

    public const REASON_CLAIMS_PAID = 'claims_payment_received';

    public const REASON_ACCEPTS_RECEIPT = 'accepts_receipt_as_proof';

    /** El único estado en el que el dinero está de verdad. */
    public const APPROVED = 'approved';

    /** Verbos de «entró el dinero», en las formas en que se dicen de verdad. */
    private const ENTRO = '(recibimos|recibi|recibido|llego|entro|ingreso|acreditado|acredito|registramos|registrado|confirmado|confirmamos|aprobado|aprobamos)';

    /** Afirmaciones de que el pago está hecho. */
    private const AFIRMA_PAGADO = [
        '~\b(ya\s+)?(?:te\s+)?'.self::ENTRO.'\b[^.!?]{0,20}\b(tu|el|su)\s+pago\b~u',
        '~\b(tu|el|su)\s+pago\b[^.!?]{0,25}\b(ya\s+)?(esta|quedo|fue|esa)?\s*'.self::ENTRO.'\b~u',
        '~\b(tu|el|su)\s+(transferencia|consignacion|giro|abono)\b[^.!?]{0,25}\b'.self::ENTRO.'\b~u',
        '~\bpago\s+(confirmado|aprobado|recibido|acreditado|exitoso)\b~u',
    ];

    /**
     * Las conjunciones que abren una subordinada de tiempo o de condición.
     *
     * Clase CERRADA del español: no es una lista de contextos inocentes que
     * nunca termina, es la gramática. El «si» va con su sujeto pegado a
     * propósito: normalizado, el «sí» de afirmar y el «si» de condicionar son
     * la misma palabra, y «sí, ya recibimos tu pago» no puede colarse por ahí.
     */
    private const SUBORDINA = '~\b(cuando|una vez|en cuanto|apenas|tan pronto|luego que|despues de que|luego de que|mientras|hasta que|si\s+(el|la|los|las|se|lo|le|tu|su))\b~u';

    /** Pedir una captura o un comprobante como prueba. Nunca vale, con pago o sin él. */
    private const PIDE_COMPROBANTE = [
        '~\b(captura|pantallazo|screenshot|foto|imagen|soporte|comprobante|recibo|voucher)\b[^.!?]{0,40}\b(del?\s+)?(pago|transferencia|consignacion|comprobante|deposito)\b~u',
        '~\b(mandame|mandeme|envia|enviame|pasame|adjunta|sube|comparte|muestra|muestrame)\b[^.!?]{0,25}\b(captura|pantallazo|screenshot|soporte|comprobante|recibo|voucher)\b~u',
        '~\bcon\s+(el|la)\s+(soporte|captura|comprobante|recibo)\b[^.!?]{0,30}\b(basta|alcanza|es\s+suficiente|sirve|vale)\b~u',
    ];

    /**
     * @param  string  $paymentState  lo que el CRM ve: none|pending|approved|declined|expired
     * @return ?array{code:string, reason:string, detail:string}
     */
    public function contradiction(string $reply, string $paymentState): ?array
    {
        $aprobado = $paymentState === self::APPROVED;

        foreach ($this->frases($reply) as $frase) {
            // Una frase negada dice lo contrario y es justo lo que queremos que
            // el asistente pueda decir: «todavía NO me aparece confirmado».
            if ($this->negada($frase)) {
                continue;
            }

            /*
             * El comprobante NO se exime nunca por condicional. Pedir una
             * captura está mal con pago y sin él, y «mándame la captura del
             * pago cuando puedas» es exactamente igual de malo que sin el
             * «cuando»: enseña que una imagen vale como prueba.
             */
            if ($this->casa($frase, self::PIDE_COMPROBANTE)) {
                return $this->hallazgo(self::REASON_ACCEPTS_RECEIPT, 'reply accepts a receipt as proof of payment');
            }

            /*
             * Y la afirmación de pago se juzga sobre lo que hay ANTES de la
             * subordinada, no sobre la frase entera.
             *
             * Eximir la frase completa abría el agujero por el que cabía todo:
             * «Ya recibimos tu pago, cuando vengas te damos el carnet» tiene una
             * subordinada temporal, sí, pero lo que afirma va delante y es una
             * mentira sobre el dinero de alguien.
             */
            if (! $aprobado && $this->casa($this->antesDeLaSubordinada($frase), self::AFIRMA_PAGADO)) {
                return $this->hallazgo(self::REASON_CLAIMS_PAID, 'reply says the payment arrived, CRM state is '.$paymentState);
            }

            /*
             * Dentro de la subordinada sólo se perdona el futuro. «Ya», «acabamos
             * de» o «hace un momento» dicen que el dinero YA entró, y eso no lo
             * vuelve hipotético ninguna conjunción: «cuando vengas te explico, ya
             * recibimos tu pago» sigue siendo una afirmación.
             */
            if (! $aprobado
                && $this->yaOcurrido($frase)
                && $this->casa($frase, self::AFIRMA_PAGADO)) {
                return $this->hallazgo(self::REASON_CLAIMS_PAID, 'reply says the payment already arrived, CRM state is '.$paymentState);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function frases(string $reply): array
    {
        $texto = SalesAgentDecisionSchema::normalize($reply);
        $partes = preg_split('~[.!?\n]+~u', $texto) ?: [];

        return array_values(array_filter(array_map('trim', $partes), fn (string $f) => $f !== ''));
    }

    /**
     * ¿La frase niega?
     *
     * Basta con que aparezca una negación: en español la negación va delante
     * del verbo, y estas frases son cortas. Preferimos dejar pasar una frase
     * negada rara antes que bloquear la honestidad («no me aparece», «aún no
     * está confirmado»), que es justo lo que el sistema quiere que diga.
     */
    private function negada(string $frase): bool
    {
        return preg_match('~\b(no|aun no|todavia no|sin)\b~u', $frase) === 1;
    }

    /**
     * ¿La frase habla del FUTURO en vez de afirmar un hecho?
     *
     * Es la salida simétrica a la negación, y la puso un turno perdido: el
     * canario físico escribió «debes hacer el pago de forma manual; una vez
     * confirmado el pago, se activa tu membresía» —que describe el proceso con
     * honestidad— y este guard lo leyó como «el pago llegó». El turno murió con
     * un 422 y la persona no recibió nada.
     *
     * Las conjunciones subordinantes de tiempo y condición son una clase
     * CERRADA del español, así que esto no es una lista de contextos inocentes
     * que nunca termina: es la gramática. Lo que afirma que el dinero entró
     * —«ya recibimos tu pago», «tu pago fue confirmado»— sigue bloqueado,
     * porque ahí no hay subordinación ninguna.
     */
    private function condicional(string $frase): bool
    {
        /*
         * «si» va con su sujeto pegado a propósito: normalizado, el «sí» de
         * afirmar y el «si» de condicionar son la misma palabra, y «sí, ya
         * recibimos tu pago» no puede colarse por esta puerta. Ante la duda,
         * esto falla hacia BLOQUEAR, que es el lado correcto cuando hay dinero.
         */
        return preg_match(self::SUBORDINA, $frase) === 1;
    }

    /**
     * La frase hasta donde empieza la subordinada, o entera si no hay ninguna.
     *
     * Es el arreglo del agujero que encontró la revisión: la exención
     * condicional valía para toda la frase y la frase sólo se partía por
     * `.!?\n`, así que bastaba una coordinada —«…, cuando vengas…»— para
     * desactivar el guard sobre lo que iba delante.
     */
    private function antesDeLaSubordinada(string $frase): string
    {
        if (preg_match(self::SUBORDINA, $frase, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $frase;
        }

        return substr($frase, 0, (int) $m[0][1]);
    }

    /** ¿La frase dice que el dinero YA entró? Ninguna conjunción lo vuelve hipotético. */
    private function yaOcurrido(string $frase): bool
    {
        return preg_match('~\b(ya|acabamos\s+de|acaba\s+de|hace\s+un\s+(momento|rato)|recien)\b~u', $frase) === 1;
    }

    /** @param  list<string>  $patrones */
    private function casa(string $frase, array $patrones): bool
    {
        foreach ($patrones as $patron) {
            if (preg_match($patron, $frase) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array{code:string, reason:string, detail:string} */
    private function hallazgo(string $reason, string $detail): array
    {
        return ['code' => self::CODE, 'reason' => $reason, 'detail' => $detail];
    }
}
