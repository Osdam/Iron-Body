<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * Que la persona sienta que la ayudan, no que la empujan.
 *
 * El Critic juzga el tono con criterio y con contexto; esto es la red de
 * abajo, determinista, y por eso SOLO bloquea lo inequívoco: el reproche en
 * segunda persona, la escasez sin cifra, el «ahora o nunca», el testimonio y
 * el porcentaje que nadie verificó. Todo lo que puede ser un hecho contado
 * bien —«tu plan termina mañana», «quedan 2 cupos en la de las 6», «la promo
 * va hasta el 30», «ya deberías haber recibido el link»— se ANOTA como
 * bandera para que lo juzgue el Critic, nunca se corta: un falso positivo aquí
 * deja a la persona sin respuesta, y eso cuesta un cliente. Nada de esto mira
 * cifras ni hechos: sólo forma y presión.
 */
final class ComposerStyleGuard
{
    public const CODE_PRESSURE = 'machine_reply_pressure';

    /** Urgencia y escasez que nadie declaró, dichas como se dicen para empujar. */
    private const URGENCIA_FALSA = [
        '/\b(no lo pienses (mas|tanto)|es ahora o nunca|decide (ya|ahora)( mismo)?\b|aprovecha ya\b)/u',
        '/\bultim[oa]s? (cupos?|lugares?|plazas?|oportunidad)\b/u',
        '/\bquedan (pocos|apenas|poquitos) (cupos?|lugares?|plazas?)\b/u',
        '/\bno te quedes sin (tu |el |un )?(cupo|lugar|plaza)\b/u',
        '/\b(es )?solo por hoy\b/u',
    ];

    /** Culpa y vergüenza corporal: sólo con el marco acusatorio, y nunca negadas. */
    private const CULPA = [
        '/(?<!\bno )(?<!\bnada de )\b(sin excusas|no hay excusas|deja (ya )?las excusas)\b/u',
        '/(?<!\bno )\b(todo (esta|es) (en la|de la|en tu) mente|es (solo )?cuestion de (actitud|voluntad|querer|ganas))\b/u',
        '/\b(estas|eres|te ves|sigues|vas a seguir|quedarte) (muy |tan |mas |asi de )?(gord[oa]s?|obes[oa]s?|flac[oa]s?|barrig[oó]n|panz[oó]n)\b/u',
        '/\b(por|de) (gord[oa]|flac[oa]|vag[oa]|perezos[oa])\b|\bcon ese cuerpo\b/u',
        '/\b(si (de verdad|realmente|en serio) quisieras|si te importara|no te (da|dio) verg[uü]enza|te va a (dar|quedar) pena)\b/u',
        '/\bya deberias (haber (empezado|venido|decidido|arrancado|cambiado)|estar (entrenando|yendo|aqui|en forma))\b/u',
        '/\ba tu edad (ya )?(deberias|tendrias que|no puedes|no deberias)\b/u',
    ];

    /** Testimonios y cifras sociales que nadie verificó. */
    private const TESTIMONIO_INVENTADO = [
        '/\b(mis|nuestr[oa]s|los) (clientes|alumn[oa]s|soci[oa]s|miembros) (dicen|cuentan|logran|bajan|pierden|ganan|renuevan)\b/u',
        '/\bun[a]? (cliente|clienta|soci[oa]|alumn[oa]) (mi[oa]|nuestr[oa])[^.!?]{0,24}?\b(bajo|perdio|gano|logro|consiguio|rebajo) \d+/u',
        '/\b(cientos|miles|millones) de (personas|clientes|socios|alumnos|usuarios)\b/u',
        '/\b\d+ de cada \d+ (clientes|socios|personas|alumnos)\b/u',
        '/\bla mayoria de (nuestros |los )?(clientes|socios|alumnos)\b/u',
        '/\bel \d+\s?% de (nuestros |los )?(clientes|socios|miembros|alumnos|que entran|que empiezan)\b/u',
    ];

    /** Lo que PUEDE ser presión o puede ser un hecho: lo decide el Critic. */
    private const URGENCIA_AMBIGUA = '/\b(termina (hoy|pronto|manana)|se acaba (hoy|pronto|ya|manana)|solo hasta|aprovecha (ahora|hoy)|quedan (pocos|solo) dias|ultim[oa]s? dias?|antes de que se (acabe|agote|llene)|precio (sube|subira|aumenta))\b/u';

    private const EDAD_O_CUERPO = '/\b(a tu edad|gord[oa]s?|obes[oa]s?|flac[oa]s?|barrig[oó]n|panz[oó]n)\b/u';

    /** Muletillas de bot y cierres automáticos. */
    private const MULETILLAS = '/\b(hay algo mas en (lo )?que (te )?pueda ayudar(te|le)?|no dudes en (consultar|preguntar|escribir)(me|nos)?|estoy aqui para ayudarte|estamos para servirte|quedo atent[oa] a (tus|cualquier)|sera un placer atenderte|como asistente virtual)\b/u';

    /** Más de esto en un mensaje de WhatsApp ya es un folleto. */
    public const MAX_PLANS_PER_MESSAGE = 3;

    public const MAX_BULLETS = 4;

    public const MAX_EMOJI = 2;

    public const BROCHURE_LENGTH = 900;

    /**
     * @param  array<int, array{id:int, name:string}>  $sellablePlans
     * @param  bool  $askedForAll  la persona pidió ver todos los planes
     * @return array{hard: string[], soft: string[], plans_mentioned: int}
     */
    public function inspect(string $replyFinal, array $sellablePlans, bool $askedForAll = false, bool $askedForDetail = false): array
    {
        $t = $this->normalize($replyFinal);
        $hard = [];
        $soft = [];

        foreach (['fake_urgency' => self::URGENCIA_FALSA, 'guilt_or_body_shaming' => self::CULPA, 'invented_testimonial' => self::TESTIMONIO_INVENTADO] as $code => $patterns) {
            foreach ($patterns as $rx) {
                if (preg_match($rx, $t) === 1) {
                    $hard[] = $code;
                    break;
                }
            }
        }

        if (preg_match(self::URGENCIA_AMBIGUA, $t) === 1) {
            $soft[] = 'urgency_wording';
        }
        if (preg_match(self::EDAD_O_CUERPO, $t) === 1) {
            $soft[] = 'age_or_body_reference';
        }
        $plans = $this->plansMentioned($t, $this->stripAccentsKeepCase($replyFinal), $sellablePlans);
        if ($plans > self::MAX_PLANS_PER_MESSAGE && ! $askedForAll) {
            $soft[] = 'plan_dump';
        }
        if ($this->bulletCount($replyFinal) > self::MAX_BULLETS) {
            $soft[] = 'bullet_abuse';
        }
        if (preg_match(self::MULETILLAS, $t) === 1) {
            $soft[] = 'bot_phrase';
        }
        if ($this->emojiCount($replyFinal) > self::MAX_EMOJI) {
            $soft[] = 'emoji_excess';
        }
        if (mb_strlen($replyFinal) > self::BROCHURE_LENGTH && ! $askedForDetail) {
            $soft[] = 'brochure_length';
        }

        return ['hard' => array_values(array_unique($hard)), 'soft' => array_values(array_unique($soft)), 'plans_mentioned' => $plans];
    }

    /** «Muéstrame todos los planes», «qué planes tienen»: pidió el catálogo. */
    public function askedForAllPlans(string $inbound): bool
    {
        $t = $this->normalize($inbound);

        return preg_match('/\b(todos los planes|(que|cuales|k) planes (tienen|hay|manejan|ofrecen|existen)|cuales son (los|sus) planes|los planes que (tienen|manejan)|opciones de planes|planes disponibles|lista de planes|todas las opciones|(info|informacion) de (los |sus )?planes|me (muestras|mandas|pasas|envias) los planes)\b/u', $t) === 1;
    }

    /** «Explícame bien», «con detalle», «todo lo que incluye»: pidió profundidad. */
    public function askedForDetail(string $inbound): bool
    {
        $t = $this->normalize($inbound);

        return preg_match('/\b(con (mas )?detalle|en detalle|detalladamente|explicame bien|explicamelo bien|todo lo que (incluye|trae)|cuentame todo|paso a paso|a fondo|bien explicado)\b/u', $t) === 1;
    }

    /**
     * Planes NOMBRADOS como planes: el nombre completo del catálogo («plan
     * semana»), el núcleo con artículo («el trimestre») o el núcleo con
     * mayúscula inicial en el texto original («Trimestre, Semestre y Élite»,
     * que es como se escribe un folleto). La palabra suelta en prosa («esta
     * semana no puedo», «el otro semestre») no cuenta.
     *
     * @param  array<int, array{id:int, name:string}>  $sellablePlans
     */
    private function plansMentioned(string $t, string $originalCase, array $sellablePlans): int
    {
        $n = 0;
        foreach ($sellablePlans as $p) {
            $full = $this->normalize((string) ($p['name'] ?? ''));
            if ($full === '') {
                continue;
            }
            $core = trim(preg_replace('/^plan\s+/u', '', $full) ?? $full);
            $q = preg_quote($core, '/');
            $nombrado = ($full !== $core && preg_match('/\b'.preg_quote($full, '/').'\b/u', $t) === 1)
                || preg_match('/\b(el|la|del|plan) '.$q.'\b/u', $t) === 1
                || preg_match('/\b'.mb_strtoupper(mb_substr($q, 0, 1)).mb_substr($q, 1).'\b/u', $originalCase) === 1;
            if ($nombrado) {
                $n++;
            }
        }

        return $n;
    }

    /** Sin tildes pero con mayúsculas: para distinguir «Semestre» (plan) de «semestre» (palabra). */
    private function stripAccentsKeepCase(string $s): string
    {
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private function bulletCount(string $s): int
    {
        // Guion ASCII y números exigen espacio (para no contar guiones de prosa);
        // los marcadores tipográficos y de emoji cuentan con o sin él.
        return preg_match_all('/^\s*(?:[-*]\s+|\d+[.)]\s+|[–—•·▪▶✔✅👉➡]\s*)/mu', $s);
    }

    /** Grafemas emoji: una secuencia con tono de piel o ZWJ cuenta uno; una bandera cuenta una. */
    private function emojiCount(string $s): int
    {
        return preg_match_all('/(?:[\x{1F1E6}-\x{1F1FF}]{2}|\p{Extended_Pictographic}\x{FE0F}?(?:[\x{1F3FB}-\x{1F3FF}])?(?:\x{200D}\p{Extended_Pictographic}\x{FE0F}?(?:[\x{1F3FB}-\x{1F3FF}])?)*)/u', $s);
    }

    private function normalize(string $s): string
    {
        return strtr(SalesAgentDecisionSchema::normalize($s), ['ü' => 'u']);
    }
}
