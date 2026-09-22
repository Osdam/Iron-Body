<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * A qué se refiere la persona cuando no lo dice.
 *
 * «sí por favor», «eso», «el mensual», «y cuánto?», «mándamelo», «dale» no
 * significan nada solos: significan algo respecto a lo que el agente acaba de
 * ofrecer o de lo que se venía hablando. Resolverlo es determinista y es tarea
 * de Laravel, que tiene la memoria; el modelo recibe el referente ya resuelto
 * como HECHO, no como adivinanza.
 *
 * Tres reglas de diseño, todas aprendidas de fallos reproducidos:
 *  - Se falla hacia NONE. Un referente inventado es peor que ninguno, porque
 *    viaja al modelo como hecho de Laravel y a la memoria como compromiso.
 *  - La negación manda. «no me mandes el link» no es pedir el link; «no me
 *    interesa el mensual» no es elegirlo.
 *  - Un nombre de plan sólo cuenta cuando se ELIGE («el mensual», «quiero el
 *    élite»), nunca por aparecer en una frase («esta semana no puedo» no es
 *    comprar el Plan Semana).
 */
final class ReferenceResolver
{
    public const ACCEPT_OFFER = 'accept_offer';

    public const DECLINE_OFFER = 'decline_offer';

    public const MORE_INFO = 'more_info';

    public const PRICE_OF_PENDING = 'price_of_pending';

    public const HOW_TO_START = 'how_to_start';

    public const SEND_IT = 'send_it';

    public const CHOOSE_PLAN = 'choose_plan';

    public const REFUSE_HUMAN = 'refuse_human';

    public const AFFIRMATION_WITHOUT_OFFER = 'affirmation_without_offer';

    public const NONE = 'none';

    /**
     * Nombres de plan que además son palabras del calendario.
     *
     * Aquí está la causa de tres arreglos fallidos seguidos: el catálogo vende
     * «Semana», «Mensual», «Trimestre», «Semestre» y «Anualidad», que son
     * también cómo se dice el tiempo en español. Los dos primeros intentos
     * enumeraron los contextos donde la palabra es inocente («a la», «por la»,
     * «toda la»…) y los dos cayeron con el siguiente: los contextos son
     * infinitos. El tercero miró la posición del artículo y cayó con «la semana
     * pasada no pude», donde el periodo es el sujeto.
     *
     * Esta lista es de otra clase: enumera las PALABRAS ambiguas, y el
     * calendario es cerrado. Un plan que se llame así sólo se elige cuando el
     * texto nombra el producto —«plan semana»—, cuando el mensaje ES el nombre,
     * o cuando un verbo de elección lo precede. Los nombres que no chocan con
     * el idioma («Élite», «Pro», «Valera») conservan las formas sueltas.
     */
    private const NOMBRE_DE_CALENDARIO = '/^(dia|diario|diaria|semana|semanal|quincena|quincenal|mes|mensual|bimestre|bimestral|trimestre|trimestral|cuatrimestre|cuatrimestral|semestre|semestral|ano|anio|anual|anualidad)$/u';

    /** Cuántos caracteres hacia atrás se mira para ver si algo está negado. */
    private const VENTANA_NEGACION = 32;

    private const NEGACION = '/\b(no|ni|nunca|jamas|sin|tampoco|todavia no|aun no|mejor no|por ahora no)\b\s*$/u';

    private const AFIRMA = '/^(si|si claro|claro|claro que si|dale|dale pues|listo|listo pues|ok|okay|okey|vale|de una|de una pues|bueno|bueno dale|por favor|si por favor|porfa|si porfa|por fa|hagale|hagale pues|hagamoslo|obvio|perfecto|me interesa|si me interesa|si quiero|quiero|va|va pues|de acuerdo|excelente|genial|esta bien|por supuesto|si senor|si senora|eso|ese|esa)(\s+(si|por favor|porfa|gracias|dale|pues|claro))*$/u';

    private const NIEGA = '/^(no|no gracias|nop|ahora no|luego|despues|mas adelante|otro dia|todavia no|aun no|mejor no|no por ahora|no me interesa|paso)(\s+gracias)?$/u';

    private const MAS_INFO = '/\b(cuentame mas|cuentame|dime mas|mas info|mas informacion|que mas|y que mas|explicame|detalles|mas detalles|que incluye|que trae|que ofrece)\b/u';

    private const PRECIO = '/\b(cuanto (vale|cuesta|es|sale|seria)|(el )?precio|que precio|a cuanto|(y )?cuanto(?!\s+(tiempo|dura|tarda|demora|falta|dias|semanas|meses|se demora|tardan)))\b/u';

    private const COMO_EMPIEZO = '/\b(como (hago|empiezo|inicio|arranco|me inscribo|me anoto|pago|seria|es el proceso)|que (tengo que|debo|hay que) hacer|cuales son los pasos|como funciona para empezar)\b/u';

    private const MANDAMELO = '/\b(mandamelo|mandame(lo)?|enviamelo|enviame(lo)?|pasamelo|pasame el (link|enlace)|mandame el (link|enlace)|manda el (link|enlace)|quiero el (link|enlace)|dame el (link|enlace)|envia(me)? el (link|enlace)|el link|el enlace)\b/u';

    private const ELECCION_DESNUDA = '/^(quiero|me quedo con|dame|voy con|prefiero|me interesa|si|el|la)?\s*(el|la)?\s*(ese|esa|eso|aquel|el mismo|la misma)(\s+(si|por favor|porfa|dale|pues))*$/u';

    private const ORDINAL = ['primer' => 0, 'primero' => 0, 'primera' => 0, 'segundo' => 1, 'segunda' => 1, 'tercero' => 2, 'tercera' => 2, 'ultimo' => -1, 'ultima' => -1];

    /** Quien rechaza a la persona quiere que sigamos nosotros. Formas cerradas, no palabras sueltas. */
    private const RECHAZA_HUMANO = '/(^(contesta|responde|contestame|respondeme)(\s+tu)?(\s+(por favor|porfa))?$)|\b(contesta|responde)(me)?\s+tu\b|\bno quiero (hablar con )?(alguien|nadie|una persona|un asesor|el equipo|humanos?)\b|\bno me pases (con|a) (nadie|alguien|una persona|un asesor)\b|\bsigamos (tu y yo|asi)\b|\bconmigo esta bien\b|\btu me puedes ayudar\b/u';

    /** Y quien PIDE una persona no está rechazándola: eso lo decide la autoridad de derivación, no este resolutor. */
    private const PIDE_HUMANO = '/\b(hablar con (una persona|alguien|un asesor|un humano|el equipo)|quiero (un|una) (asesor|persona|humano)|necesito (un|una) (asesor|persona|humano)|pasame con|comunicame con|un asesor me (atienda|responda|conteste))\b/u';

    /**
     * @param  array<int, array{id:int, name:string}>  $sellablePlans  planes vendibles, con id y nombre
     * @return array{type:string, plan_id:?int, offer_kind:?string, evidence:?string, unresolved_question:?array{source:string,text:string}}
     */
    /**
     * ¿Este nombre de plan es también una palabra del calendario?
     *
     * «Semana», «Mensual», «Trimestre» aparecen en frases que no hablan de
     * ningún plan —«entrenar en la semana», «el mes que viene»—. Quien
     * busque el plan por su nombre corto tiene que preguntarse esto antes;
     * la memoria no lo hacía y anotó el Plan Semana como discutido porque el
     * agente escribió «¿qué días tienes disponibles en la semana?».
     */
    public static function isCalendarWord(string $core): bool
    {
        return preg_match(self::NOMBRE_DE_CALENDARIO, SalesAgentDecisionSchema::normalize(trim($core))) === 1;
    }

    public function resolve(string $inbound, ConversationMemory $memory, array $sellablePlans): array
    {
        $t = $this->normalize($inbound);
        $sellableIds = array_map('intval', array_column($sellablePlans, 'id'));
        $offer = $memory->get('last_agent_offer');
        $confirmation = $memory->get('pending_confirmation');
        $unresolved = MemoryRedactor::quote(MemoryRedactor::lead($memory->get('unresolved_question')['text'] ?? null));
        $pendiente = $this->sellable($memory->pendingPlanId(), $sellableIds);
        $out = fn (string $type, ?int $plan = null, ?string $kind = null, ?string $ev = null) => [
            'type' => $type, 'plan_id' => $plan, 'offer_kind' => $kind, 'evidence' => $ev, 'unresolved_question' => $unresolved,
        ];

        if ($t === '') {
            return $out(self::NONE);
        }

        // Pedir una persona no es cosa de este resolutor; y desde luego no es rechazarla.
        if ($this->matchNotNegated(self::PIDE_HUMANO, $t) !== null) {
            return $out(self::NONE);
        }
        if (preg_match(self::RECHAZA_HUMANO, $t, $m) === 1) {
            return $out(self::REFUSE_HUMAN, $pendiente, null, trim($m[0]));
        }

        // Precio ANTES que elegir plan: «cuánto vale el mensual» pregunta, no compra.
        if (($ev = $this->matchNotNegated(self::PRECIO, $t)) !== null) {
            return $out(self::PRICE_OF_PENDING, $this->planIn($t, $memory, $sellablePlans, permisivo: true) ?? $pendiente, null, $ev);
        }

        if (($ev = $this->matchNotNegated(self::MANDAMELO, $t)) !== null) {
            return $out(self::SEND_IT, $this->planIn($t, $memory, $sellablePlans, permisivo: true) ?? $pendiente, null, $ev);
        }

        if (($ev = $this->matchNotNegated(self::COMO_EMPIEZO, $t)) !== null) {
            return $out(self::HOW_TO_START, $pendiente, null, $ev);
        }

        if (($ev = $this->matchNotNegated(self::MAS_INFO, $t)) !== null) {
            return $out(self::MORE_INFO, $this->planIn($t, $memory, $sellablePlans, permisivo: true) ?? $pendiente, null, $ev);
        }

        // Un plan nombrado y NEGADO es un rechazo, no una elección.
        $negado = $this->planIn($t, $memory, $sellablePlans, negated: true);
        if ($negado !== null) {
            return $out(self::DECLINE_OFFER, $negado, $offer['kind'] ?? null, $t);
        }

        $chosen = $this->planIn($t, $memory, $sellablePlans);
        if ($chosen !== null && $this->wordCount($t) <= 6) {
            return $out(self::CHOOSE_PLAN, $chosen, null, $t);
        }

        $hayOferta = is_array($offer) && ($offer['kind'] ?? null) !== null && is_array($confirmation);

        if (preg_match(self::NIEGA, $t) === 1) {
            return $out(self::DECLINE_OFFER, $hayOferta ? $this->sellable($offer['plan_id'] ?? null, $sellableIds) : null, $hayOferta ? (string) $offer['kind'] : null, $t);
        }

        if (preg_match(self::AFIRMA, $t) === 1) {
            if ($hayOferta) {
                return $out(self::ACCEPT_OFFER, $this->sellable($offer['plan_id'] ?? null, $sellableIds) ?? $pendiente, (string) $offer['kind'], $t);
            }
            $pending = $memory->get('pending_reference');
            if (is_array($pending) && ($pending['type'] ?? null) === 'plan' && $this->sellable((int) $pending['plan_id'], $sellableIds) !== null) {
                return $out(self::CHOOSE_PLAN, (int) $pending['plan_id'], null, $t);
            }

            return $out(self::AFFIRMATION_WITHOUT_OFFER, $pendiente, null, $t);
        }

        return $out(self::NONE);
    }

    /**
     * Un plan ELEGIDO en el texto: por nombre con contexto de elección («el
     * mensual», «quiero el élite», «plan semana»), por ordinal respecto a la
     * última recomendación («el primero») o por deíctico en una elección
     * desnuda («quiero ese», «eso»). Con `negated`, devuelve el plan sólo si la
     * mención está negada («no me interesa el mensual»).
     *
     * @param  array<int, array{id:int, name:string}>  $sellablePlans
     */
    /**
     * El plan que la frase NOMBRA, si de verdad lo nombra.
     *
     * @param  bool  $permisivo  cuando la frase YA está clasificada como «precio
     *                           de», «mándame» o «cuéntame más», el nombre puede
     *                           ir en mitad de la oración: ahí «el élite» de
     *                           «¿cuánto sale el élite?» es el plan, sin duda.
     */
    private function planIn(string $t, ConversationMemory $memory, array $sellablePlans, bool $negated = false, bool $permisivo = false): ?int
    {
        $sellableIds = array_map('intval', array_column($sellablePlans, 'id'));

        foreach ($sellablePlans as $p) {
            $name = $this->normalize((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $core = trim(preg_replace('/^plan\s+/u', '', $name) ?? $name);
            if ($core === '') {
                continue;
            }
            $q = preg_quote($core, '/');
            $ambiguo = preg_match(self::NOMBRE_DE_CALENDARIO, $core) === 1;

            /*
             * Cuatro formas valen para cualquier plan, porque son actos de
             * elección y no apariciones de la palabra:
             *   1. el mensaje ES el nombre                → «semana»
             *   2. el mensaje ES (sí +) artículo + nombre → «si el mensual»
             *   3. el nombre va con la palabra «plan»     → «el plan semana»
             *   4. un verbo de elección lo precede        → «me quedo con la semana»
             *
             * Para un nombre que NO choca con el calendario se añaden las
             * formas sueltas: el artículo que abre la cláusula y, cuando la
             * frase ya se clasificó como precio, envío o más información, el
             * nombre en cualquier posición («¿cuánto sale el élite?»).
             *
             * Para uno ambiguo, sólo una más: el nombre REGIDO por el núcleo
             * de la pregunta de precio o de información. Así «precio del
             * trimestral» sigue resolviendo y «cuánto cuesta entrenar en la
             * semana» —el fallo del canario entrando por la otra puerta— no.
             */
            $eleccion = '(quiero|prefiero|me quedo con|me quedo|dame|deme|voy con|me interesa|me gusta|elijo|escojo|llevo|compro)';
            $articulo = '(el|la|los|las|del|de la|con el|con la|si el|si la|el de)';
            $cortesia = '(\s+(por favor|porfa|gracias|dale|pues|entonces|mejor))*[.!?]*';

            $formas = [
                '^'.$q.'$',
                '^(si|claro|dale|listo|ok|vale|bueno)?\s*(el|la|los|las)\s+(plan\s+)?'.$q.'$',
                '\bplan\s+'.$q.'\b',
                '\b'.$eleccion.'\s+(el|la|los|las)?\s*(plan\s+)?'.$q.'\b',
            ];

            if (! $ambiguo) {
                $formas[] = '(^|,\s*)'.$articulo.'\s+(plan\s+)?'.$q.'\b';
                if ($permisivo) {
                    $formas[] = '\b'.$articulo.'\s+(plan\s+)?'.$q.'\b';
                }
            } elseif ($permisivo) {
                /*
                 * El nombre ambiguo sólo cuenta si lo RIGE el núcleo de la
                 * pregunta —precio, valor, «cuánto vale», «qué incluye»—, no un
                 * verbo de actividad.
                 *
                 * «precio DEL trimestral»: el artículo cuelga del sustantivo de
                 * precio, y se está preguntando por un plan.
                 * «cuánto cuesta entrenar EN la semana»: cuelga de «entrenar»,
                 * es un complemento de tiempo, y se está preguntando por un
                 * ritmo. La forma anterior aceptaba las dos y volvía a cotizar
                 * el Plan Semana, que es el fallo del canario por otra puerta.
                 *
                 * El conjunto es cerrado por la misma razón que el del
                 * calendario: son los encabezados con los que ESTA rama ya
                 * clasificó la frase, no los contextos donde la palabra es
                 * inocente, que no terminan nunca.
                 */
                $nucleo = '(precio|valor|costo|cuanto\s+(vale|cuesta|sale|es|seria)|info|informacion|que\s+incluye|incluye|que\s+trae|que\s+ofrece)';
                $formas[] = '\b'.$nucleo.'\s+(de\s+|del\s+|de\s+la\s+|el\s+|la\s+)?(plan\s+)?'.$q.'\b';
            }

            $rx = '/('.implode(')|(', $formas).')/u';
            if (preg_match($rx, $t, $m, PREG_OFFSET_CAPTURE) === 1) {
                $esNegado = $this->negatedAt($t, (int) $m[0][1]);
                if ($esNegado === $negated) {
                    return (int) $p['id'];
                }
            }
        }
        if ($negated) {
            return null;
        }

        $rec = $memory->get('last_recommendation')['plan_ids'] ?? [];
        if (is_array($rec) && $rec !== []) {
            foreach (self::ORDINAL as $palabra => $idx) {
                if (preg_match('/\b(el|la)\s+'.$palabra.'\b/u', $t, $m, PREG_OFFSET_CAPTURE) === 1 && ! $this->negatedAt($t, (int) $m[0][1])) {
                    $i = $idx < 0 ? count($rec) - 1 : $idx;

                    return isset($rec[$i]) ? $this->sellable((int) $rec[$i], $sellableIds) : null;
                }
            }
        }

        // «quiero ese», «eso», «el mismo»: sólo como elección desnuda. «eso es muy
        // caro» o «no quiero ese» no lo son.
        if (preg_match(self::ELECCION_DESNUDA, $t) === 1) {
            return $this->sellable($memory->pendingPlanId(), $sellableIds);
        }

        return null;
    }

    /** Primer match del patrón que NO esté negado en los 32 caracteres previos; devuelve el fragmento. */
    private function matchNotNegated(string $pattern, string $t): ?string
    {
        if (preg_match_all($pattern, $t, $mm, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        foreach ($mm[0] as [$frag, $offset]) {
            if (! $this->negatedAt($t, (int) $offset)) {
                return trim($frag);
            }
        }

        return null;
    }

    private function negatedAt(string $t, int $offset): bool
    {
        $antes = mb_substr($t, max(0, $offset - self::VENTANA_NEGACION), min($offset, self::VENTANA_NEGACION));

        return preg_match(self::NEGACION, $antes) === 1
            || preg_match('/\b(no|ni|nunca|jamas|tampoco)\b/u', $antes) === 1;
    }

    /** @param int[] $sellableIds */
    private function sellable(?int $planId, array $sellableIds): ?int
    {
        return $planId !== null && in_array($planId, $sellableIds, true) ? $planId : null;
    }

    /** Minúsculas, sin tildes, sin nada que no sea letra, número o espacio; «siii» → «si». */
    private function normalize(string $s): string
    {
        $t = SalesAgentDecisionSchema::normalize($s);
        $t = preg_replace('/[^a-z0-9\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/([aeiou])\1+\b/u', '$1', $t) ?? $t;
        $t = preg_replace('/(\w)\1{2,}/u', '$1', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    private function wordCount(string $t): int
    {
        return $t === '' ? 0 : count(explode(' ', $t));
    }
}
