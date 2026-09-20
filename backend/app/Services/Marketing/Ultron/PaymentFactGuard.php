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

    /**
     * Verbos de «entró el dinero», en las formas en que se dicen de verdad.
     *
     * La tercera persona del singular faltaba —«tu pago se recibió ayer» pasaba—
     * y se añade con cuidado: `se registro` va con el pronombre pegado porque
     * «registro» a secas es también un sustantivo, y «el registro de tu pago
     * está pendiente» es una frase honesta que no se puede bloquear.
     */
    private const ENTRO = '(recibimos|recibi|recibio|recibido|llego|entro|ingreso|acreditado|acredito|registramos|se\s+registro|registrado|confirmado|confirmamos|aprobado|aprobamos|aprobo)';

    /**
     * Afirmaciones de que el pago está hecho.
     *
     * Las ventanas excluyen el punto y coma: una afirmación no cruza de una
     * oración a otra. Sin eso, «debes hacer el pago de forma manual; una vez
     * confirmado el pago…» casaba de «el pago» (de la primera) a «confirmado»
     * (de la segunda) y el guard veía una afirmación que nadie escribió.
     *
     * La COMA sí se admite dentro, y es deliberado: un inciso no parte la
     * afirmación. «Ya recibimos, efectivamente, tu pago» y «Tu pago, el de
     * ayer, quedó registrado» son exactamente la misma mentira con una aclaración
     * en medio, y excluir la coma las dejaba pasar.
     */
    private const AFIRMA_PAGADO = [
        '~\b(ya\s+)?(?:te\s+)?'.self::ENTRO.'\b[^.!?;]{0,20}\b(tu|el|su)\s+pago\b~u',
        '~\b(tu|el|su)\s+pago\b[^.!?;]{0,25}\b(ya\s+)?(esta|quedo|fue|esa)?\s*'.self::ENTRO.'\b~u',
        '~\b(tu|el|su)\s+(transferencia|consignacion|giro|abono)\b[^.!?;]{0,25}\b'.self::ENTRO.'\b~u',
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
    private const SUBORDINA = '~\b(cuando|una vez|en cuanto|apenas|tan pronto|luego que|despues de que|luego de que|mientras(?!\s+tanto)|hasta que|si\s+(el|la|los|las|se|lo|le|tu|su))\b~u';

    /**
     * Cuánto texto puede haber entre la conjunción y la afirmación que gobierna.
     *
     * Las subordinadas legítimas pegan las dos cosas: «una vez confirmado el
     * pago», «apenas el pago quede confirmado», «cuando se confirme el pago».
     * Estirarlo permitiría que la conjunción gobernara una oración entera que
     * viene después y que no tiene nada de hipotética.
     */
    private const ALCANCE_SUBORDINADA = 16;

    /**
     * Cuánto puede haber entre el «no» y el verbo que niega para considerarlo
     * PEGADO.
     *
     * Seis caracteres: lo que cabe en un pronombre o un artículo —«no se
     * acreditó», «no llegó», «no recibimos»—. Un predicado entero por medio
     * («no te preocupes que…») mide el triple, y ésa es exactamente la
     * diferencia entre negar el pago y negar otra cosa mientras se afirma.
     */
    private const ALCANCE_NEGACION_PEGADA = 6;

    /** Pedir una captura o un comprobante como prueba. Nunca vale, con pago o sin él. */
    private const PIDE_COMPROBANTE = [
        '~\b(captura|pantallazo|screenshot|foto|imagen|soporte|comprobante|recibo|voucher)\b[^.!?;]{0,40}\b(del?\s+)?(pago|transferencia|consignacion|comprobante|deposito)\b~u',
        '~\b(mandame|mandeme|envia|enviame|pasame|adjunta|sube|comparte|muestra|muestrame)\b[^.!?;]{0,25}\b(captura|pantallazo|screenshot|soporte|comprobante|recibo|voucher)\b~u',
        '~\bcon\s+(el|la)\s+(soporte|captura|comprobante|recibo)\b[^.!?;]{0,30}\b(basta|alcanza|es\s+suficiente|sirve|vale)\b~u',
    ];

    /**
     * @param  string  $paymentState  lo que el CRM ve: none|pending|approved|declined|expired
     * @return ?array{code:string, reason:string, detail:string}
     */
    public function contradiction(string $reply, string $paymentState): ?array
    {
        $aprobado = $paymentState === self::APPROVED;

        foreach ($this->frases($reply) as $frase) {
            /*
             * Pedir un comprobante está mal con pago y sin él, así que aquí no
             * exime ninguna condicional: «mándame la captura del pago cuando
             * puedas» enseña lo mismo que sin el «cuando». Sólo se perdona si
             * la NEGACIÓN gobierna la petición («no hace falta que me mandes la
             * captura»).
             */
            if ($this->pideComprobante($frase)) {
                return $this->hallazgo(self::REASON_ACCEPTS_RECEIPT, 'reply accepts a receipt as proof of payment');
            }

            if (! $aprobado && $this->afirmaPago($frase)) {
                return $this->hallazgo(self::REASON_CLAIMS_PAID, 'reply says the payment arrived, CRM state is '.$paymentState);
            }
        }

        return null;
    }

    /** ¿Pide un comprobante, sin que una negación lo desmienta? */
    private function pideComprobante(string $frase): bool
    {
        foreach (self::PIDE_COMPROBANTE as $patron) {
            if (preg_match($patron, $frase, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            if (! $this->niegaLaNegacion($frase, (int) $m[0][1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Afirma que el dinero entró?
     *
     * Tres exenciones, y las tres tienen que GOBERNAR la afirmación concreta,
     * no aparecer en cualquier sitio de la frase. Esa diferencia es todo el
     * guard: mirar la frase entera fue el agujero tres veces seguidas.
     */
    private function afirmaPago(string $frase): bool
    {
        foreach (self::AFIRMA_PAGADO as $patron) {
            if (preg_match($patron, $frase, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $texto = (string) $m[0][0];
            $pos = (int) $m[0][1];

            /*
             * «todavía no me llegó tu pago»: eso es la honestidad que el
             * sistema existe para poder decir, y es la frase más importante de
             * todo este guard.
             *
             * Lo que distingue esa honestidad de la cortesía que miente no es
             * el tiempo verbal —las dos van en pasado— sino DÓNDE está el «no».
             * La negación se pega al verbo que niega: «no llegó», «no se
             * acreditó». La cortesía mete un predicado entero por medio: «no te
             * preocupes QUE ya recibimos tu pago». Por eso el pasado de
             * indicativo sólo cancela la exención cuando la negación NO está
             * pegada, y una negación dentro del propio tramo que casó («tu pago
             * no llegó todavía») siempre cuenta.
             *
             * El «ya» sigue mandando sobre todo lo demás: dice que el dinero
             * entró, lo acompañe la negación que lo acompañe.
             */
            $dentroDelTramo = preg_match(self::NIEGA, $texto) === 1;
            $niega = $dentroDelTramo || $this->niegaLaNegacion($frase, $pos);
            $pegadaAlVerbo = $dentroDelTramo || $this->gobierna(self::NIEGA, $frase, $pos, self::ALCANCE_NEGACION_PEGADA);

            if ($niega
                && ! $this->yaOcurrido($frase)
                && (! $this->enPasadoIndicativo($texto) || $pegadaAlVerbo)) {
                continue;
            }

            /*
             * «una vez confirmado el pago, se activa tu membresía» describe el
             * proceso. Pero colgar de la conjunción no basta: en pasado de
             * indicativo se sigue afirmando («cuando tu pago fue confirmado…»),
             * y un «ya» en la frase dice que el dinero entró, lo diga donde lo
             * diga.
             */
            if ($this->gobierna(self::SUBORDINA, $frase, $pos, self::ALCANCE_SUBORDINADA)
                && ! $this->yaOcurrido($frase)
                && ! $this->enPasadoIndicativo($texto)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function frases(string $reply): array
    {
        $texto = SalesAgentDecisionSchema::normalize($reply);
        $partes = preg_split('~[.!?\n]+~u', $texto) ?: [];

        return array_values(array_filter(array_map('trim', $partes), fn (string $f) => $f !== ''));
    }

    /**
     * ¿Hay una negación que gobierne lo que empieza en $pos?
     *
     * TERCERA versión del mismo agujero, y la más probable de las tres: bastaba
     * que apareciera un «no» en cualquier parte de la frase para eximirla
     * entera, y «no te preocupes» es frase de catálogo de cualquier modelo
     * comercial. «Ya recibimos tu pago, no te preocupes» pasaba.
     *
     * La negación en español gobierna su cláusula, así que tiene que ir DELANTE
     * de lo que niega y sin una coma en medio —la coma abre cláusula nueva—.
     * Sin límite de distancia, al contrario que la subordinada: «no hace falta
     * que me mandes la captura del pago» niega desde el principio de una
     * cláusula larga y sigue siendo honesto.
     */
    private function niegaLaNegacion(string $frase, int $pos): bool
    {
        return $this->gobierna(self::NIEGA, $frase, $pos, PHP_INT_MAX);
    }

    /** Las negaciones que valen. En español van delante del verbo. */
    private const NIEGA = '~\b(no|aun no|todavia no|sin)\b~u';

    /**
     * ¿La frase dice que el dinero YA entró?
     *
     * «Ya», «acabamos de», «hace un momento»: ninguna conjunción vuelve
     * hipotético el pasado, así que estas marcas anulan la exención de la
     * subordinada aunque la afirmación cuelgue de ella.
     */
    private function yaOcurrido(string $frase): bool
    {
        return preg_match('~\b(ya|acabamos\s+de|acaba\s+de|hace\s+un\s+(momento|rato)|recien)\b~u', $frase) === 1;
    }

    /**
     * Formas de pasado de INDICATIVO: dicen que ya ocurrió, y eso no lo vuelve
     * hipotético ninguna conjunción. Lista cerrada a propósito; los participios
     * («confirmado», «recibido») quedan fuera porque son justo los que forman
     * las condicionales legítimas: «una vez confirmado el pago».
     */
    private function enPasadoIndicativo(string $texto): bool
    {
        return preg_match('~\b(fue|fueron|quedo|quedaron|entro|entraron|llego|llegaron|ingreso|acredito|recibimos|recibi|recibio|confirmamos|aprobamos|aprobo|registramos|se\s+registro)\b~u', $texto) === 1;
    }

    /**
     * ¿Alguna marca de $patron gobierna lo que empieza en $pos?
     *
     * Gobernar es ir DELANTE, en la misma cláusula (sin coma ni punto y coma de
     * por medio) y dentro del alcance. Es la única pregunta que hace falta para
     * las dos exenciones del guard, y hacérsela por afirmación —en vez de por
     * frase— es lo que cierra las tres puertas.
     */
    private function gobierna(string $patron, string $frase, int $pos, int $alcance): bool
    {
        $antes = substr($frase, 0, $pos);

        if (preg_match_all($patron, $antes, $mm, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        $ultima = end($mm[0]);
        $desde = (int) $ultima[1] + strlen((string) $ultima[0]);
        $entre = substr($frase, $desde, $pos - $desde);

        return ! str_contains($entre, ',')
            && ! str_contains($entre, ';')
            && mb_strlen($entre) <= $alcance;
    }

    /** @return array{code:string, reason:string, detail:string} */
    private function hallazgo(string $reason, string $detail): array
    {
        return ['code' => self::CODE, 'reason' => $reason, 'detail' => $detail];
    }
}
