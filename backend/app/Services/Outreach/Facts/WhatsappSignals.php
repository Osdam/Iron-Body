<?php

namespace App\Services\Outreach\Facts;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * W9 · LO QUE DEJÓ EL CAMINO DE SIEMPRE de los webhooks de Meta (contrato H9,
 * D9-8 etapa 8, D9-10 fuentes `meta_status` e `inbound_message`; §7: «los
 * normalizadores de Meta y de voz leen tablas que ya existen»). SOLO LECTURA
 * ({@see TABLES}): el webhook (`ProcessMetaWebhookEvent` →
 * `MetaConversationService::recordStatus/recordInbound`) sigue escribiendo
 * como hoy, sin cambiar sus rutas, sus firmas ni su código; esto solo lee lo
 * que dejó:
 *
 *  - el saliente de un trabajo (por su id o por su correlación, que es el
 *    `uuid` del trabajo) y su historial de estados de Meta (`sent`,
 *    `delivered`, `read`, `failed`, también los que llegaron fuera de orden);
 *  - los ENTRANTES de los prospectos de la persona por WhatsApp entre dos
 *    instantes, SIN SU TEXTO: de cada uno solo sale si es una BAJA SEGURA o
 *    una POSIBLE BAJA.
 *
 * LA BAJA (D9-15, Ley 2300 art. 5 par. 2: «una forma fácil y efectiva de
 * dejar de recibir mensajes»; SC-2 de la revisión de seguridad del cierre),
 * en dos niveles y siempre sobre la forma NORMALIZADA del mensaje:
 *
 *  - BAJA SEGURA ({@see isOptOutText()}): el mensaje ENTERO, sin «por favor»
 *    ni «gracias» alrededor, es UNA de la lista cerrada {@see OPT_OUT_PHRASES}
 *    («BAJA», «Stop», «no más», «no me escriban más, gracias»). Es lo ÚNICO que
 *    el normalizador registra por su cuenta como `opted_out`;
 *  - POSIBLE BAJA ({@see isPossibleOptOutText()}): la baja segura o un mensaje
 *    que CONTIENE alguna expresión de {@see POSSIBLE_OPT_OUT} («Quiero que me
 *    den de baja», «No me vuelvan a escribir», «no me llamen», «dejen de
 *    escribirme», «Sáquenme de su lista», «eliminen mi número», «Detener»…,
 *    y también «¿qué es la baja?» o «stop motion»). Es un detector
 *    CONSERVADOR por contención: no interpreta (eso es AI9), solo no deja
 *    pasar. Ante una posible baja el canal NO envía y una persona la revisa
 *    (el normalizador la deriva); nunca se registra sola como `opted_out`.
 *
 * Equivocarse hacia la baja es el lado seguro: un falso positivo cuesta una
 * revisión; un falso negativo, escribir o llamar a quien dijo que no. Por eso
 * lo que no se puede leer (UTF-8 inválido, una expresión que PCRE no pudo
 * evaluar) es una POSIBLE baja, nunca «no es nada». Lo que SÍ deja pasar, a
 * sabiendas: lo que la lista no nombra («no me interesa» es `not_interested`,
 * no una baja) y lo que no es texto (un audio sin transcribir, una imagen).
 *
 * LA FORMA NORMALIZADA, como la guarda de la transcripción de la voz
 * (`VoiceTranscriptLexicon::fold()`) y con tablas propias y cerradas, porque
 * producción no tiene `ext-intl` (una prueba la compara con NFKD/NFKC de
 * `Normalizer` donde la extensión sí está): el subconjunto de NFKC del
 * alfabeto latino (ancho completo y su espacio, alfanuméricos matemáticos,
 * letras en círculo, ligaduras), minúsculas, sin marcas combinadas (`\p{M}`:
 * la tilde suelta que deja NFD) ni invisibles (`\p{Cf}`: anchura cero, bidi,
 * guion blando, BOM…), sin diacríticos, sin signos ni emojis y con un solo
 * espacio entre palabras. Se busca en todas sus formas ({@see forms()}): con
 * lo invisible quitado («ba»+U+200B+«ja» es «baja»), con lo invisible como
 * espacio (si separaba palabras) y con las letras sueltas unidas («B A J A»).
 * Los homoglifos (cirílico, griego) no se pliegan: quien pide la baja no se
 * esconde.
 */
final class WhatsappSignals
{
    public const TABLES = ['marketing_messages', 'marketing_message_statuses', 'marketing_conversations'];

    /** Los estados de Meta que normaliza W9 (los de `MetaConversationService::recordStatus`). */
    public const META_STATUSES = ['sent', 'delivered', 'read', 'failed'];

    /** La lista CERRADA de bajas SEGURAS: el mensaje entero, ya normalizado ({@see normalize()}). */
    public const OPT_OUT_PHRASES = [
        'baja', 'de baja', 'dar de baja', 'darme de baja', 'dame de baja', 'deme de baja', 'denme de baja', 'me doy de baja', 'quiero darme de baja',
        'stop', 'stop promotions', 'detener promociones', 'unsubscribe', 'desuscribir', 'desuscribirme', 'cancelar suscripcion',
        'no mas', 'no mas mensajes', 'no quiero mas mensajes', 'no quiero recibir mas mensajes', 'no deseo recibir mas mensajes',
        'no me escriban', 'no me escriban mas', 'no me escribas', 'no me escribas mas', 'no me contacten', 'no me contacten mas',
    ];

    /**
     * SC-2 · las expresiones de una POSIBLE baja, sobre la forma normalizada
     * (minúsculas, sin tildes, un espacio entre palabras): basta CONTENER una.
     * Un conjunto CERRADO: los patrones de la revisión de seguridad tal cual
     * (amplios, por prefijo) más sus variantes evidentes, estas más estrechas
     * para no marcar frases normales («me pueden sacar una cita», «no me
     * mandaron nada», «me llegó al spam»).
     */
    public const POSSIBLE_OPT_OUT = [
        '/\bbaja\b/u',                                                                    // «denme de baja», «quiero la baja», «¿qué es la baja?»
        '/\b(stop|unsubscribe)\b/u',
        '/\b(desuscrib|desinscrib|desafili)[a-z]*/u',
        '/\bcancelar (la |mi )?suscripcion\b/u',
        '/\b(detener|detengan)\b/u',
        '/\bno me (vuelvan a |vuelvas a |vuelva a |sigan |sigas |siga )?(escrib|contact|llam)[a-z]*/u',
        '/\bno (quiero|deseo) que me (vuelvan a |sigan )?(escrib|contact|llam)[a-z]*/u',
        '/\bno me (molesten|molestes|moleste|molestar|sigan molestando)\b/u',
        '/\bno (quiero|deseo) (recibir|seguir recibiendo|mas)\b/u',
        '/\bno (me |nos )?(envien|envies|envie|manden|mandes|mande)\b/u',
        '/\bno mas (mensajes|whatsapp|publicidad|promociones|llamadas)\b/u',
        '/\bdejen de\b/u',
        '/\bdeja de (escrib|llam|contact|mand|envi|molest)[a-z]*/u',
        '/\b(saquenme|saqueme|quitenme|quiteme|borrenme|borreme)\b/u',
        '/\bme (saquen|quiten|borren|eliminen) de\b/u',
        '/\b(elimin|borr)(en|ar) mis?\b/u',
    ];

    /** Lo que puede rodear a una baja sin cambiarla (al principio o al final). */
    private const COURTESY = ['muchas gracias', 'gracias', 'por favor', 'porfavor', 'porfa'];

    /**
     * Lo que no se ve y parte una palabra (como la guarda de la voz): los
     * caracteres de formato (`\p{Cf}`: anchura cero, bidi, guion blando,
     * U+2060–U+2064, BOM, etiquetas…) y los rellenos coreanos, que son letras
     * invisibles.
     */
    private const INVISIBLE = '[\p{Cf}\x{115F}\x{1160}\x{3164}\x{FFA0}]';

    /** Ya en minúsculas y sin marcas combinadas: cada letra latina con diacrítico (Latin-1 y Latin Extended-A), a su base. Un conjunto CERRADO. */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ſ' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /** Las ligaduras latinas de compatibilidad (U+FB00–U+FB06), como las deja NFKC. */
    private const LIGATURES = ["\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st'];

    /** Cuántos entrantes de la persona se miran como mucho para una baja anterior (los más recientes). */
    public const OPT_OUT_SCAN = 200;

    /**
     * El saliente de un trabajo: por su id si el trabajo ya lo tiene; si no,
     * por su CORRELACIÓN (el `uuid` del trabajo, que la puerta pone en
     * `correlation_id`): así se concilia un trabajo que quedó `ambiguous`.
     *
     * @return array{id: int, conversationId: int, status: string, metaAccepted: bool, createdAt: DateTimeImmutable, lastErrorCode: int|null}|null
     */
    public function outboundOf(?int $messageId, string $jobUuid): ?array
    {
        $q = DB::table('marketing_messages')->where('direction', 'outbound');
        $fila = $messageId !== null
            ? $q->where('id', $messageId)->first(['id', 'conversation_id', 'status', 'meta_message_id', 'created_at', 'last_error_code'])
            : $q->where('correlation_id', strtolower($jobUuid))->orderBy('id')->first(['id', 'conversation_id', 'status', 'meta_message_id', 'created_at', 'last_error_code']);
        if ($fila === null) {
            return null;
        }
        $creado = FactValues::instant($fila->created_at);

        return $creado === null ? null : [
            'id' => (int) $fila->id,
            'conversationId' => (int) $fila->conversation_id,
            'status' => (string) $fila->status,
            'metaAccepted' => $fila->meta_message_id !== null && $fila->meta_message_id !== '',
            'createdAt' => $creado,
            'lastErrorCode' => $fila->last_error_code !== null ? (int) $fila->last_error_code : null,
        ];
    }

    /** ¿Hay ya un saliente con la correlación de este trabajo? (el canal, si la puerta lanzó). */
    public function hasOutboundFor(string $jobUuid): bool
    {
        return DB::table('marketing_messages')->where('direction', 'outbound')->where('correlation_id', strtolower($jobUuid))->exists();
    }

    /**
     * El historial de estados de Meta de un saliente, en orden de llegada: los
     * que movieron el estado y los que llegaron tarde (`applied = false`).
     *
     * @return list<array{id: int, status: string, applied: bool, at: DateTimeImmutable|null, errorCode: int|null}>
     */
    public function statusesOf(int $messageId): array
    {
        $out = [];
        foreach (DB::table('marketing_message_statuses')->where('message_id', $messageId)->whereIn('status', self::META_STATUSES)->orderBy('id')
            ->get(['id', 'status', 'applied', 'occurred_at', 'created_at', 'error_code']) as $f) {
            $out[] = [
                'id' => (int) $f->id,
                'status' => (string) $f->status,
                'applied' => FactValues::bool($f->applied),
                'at' => FactValues::instant($f->occurred_at) ?? FactValues::instant($f->created_at),
                'errorCode' => $f->error_code !== null ? (int) $f->error_code : null,
            ];
        }

        return $out;
    }

    /**
     * Los ENTRANTES de los prospectos dados por WhatsApp creados después de
     * `$after` (y antes de `$before`, si lo hay), los más antiguos primero.
     * El texto se lee SOLO para saber si es una baja segura (`optOut`) o una
     * posible baja (`possibleOptOut`, que incluye la segura) y no sale de aquí.
     *
     * @param  list<int>  $leadIds
     * @return list<array{id: int, at: DateTimeImmutable, optOut: bool, possibleOptOut: bool}>
     */
    public function inboundBetween(array $leadIds, DateTimeImmutable $after, ?DateTimeImmutable $before, int $limit = 50): array
    {
        if ($leadIds === []) {
            return [];
        }
        $q = DB::table('marketing_messages as m')
            ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.lead_id', $leadIds)
            ->where('c.channel', 'whatsapp')
            ->where('m.direction', 'inbound')
            ->where('m.created_at', '>', FactValues::sql($after));
        if ($before !== null) {
            $q->where('m.created_at', '<', FactValues::sql($before));
        }
        $out = [];
        foreach ($q->orderBy('m.created_at')->orderBy('m.id')->limit(max(1, $limit))->get(['m.id', 'm.created_at', 'm.body']) as $f) {
            $en = FactValues::instant($f->created_at);
            if ($en !== null) {
                $cuerpo = is_string($f->body) ? $f->body : null;
                $out[] = ['id' => (int) $f->id, 'at' => $en, 'optOut' => self::isOptOutText($cuerpo), 'possibleOptOut' => self::isPossibleOptOutText($cuerpo)];
            }
        }

        return $out;
    }

    /**
     * ¿Escribió esta persona ALGUNA VEZ una baja SEGURA por WhatsApp? (sus
     * últimos {@see OPT_OUT_SCAN} entrantes). La baja no caduca (Ley 1581:
     * revocación en cualquier momento).
     *
     * @param  list<int>  $leadIds
     */
    public function everOptedOut(array $leadIds): bool
    {
        foreach ($this->lastInboundBodies($leadIds) as $cuerpo) {
            if (self::isOptOutText(is_string($cuerpo) ? $cuerpo : null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SC-2 · ¿Escribió esta persona ALGUNA VEZ por WhatsApp una baja segura o
     * una POSIBLE baja? (sus últimos {@see OPT_OUT_SCAN} entrantes, también los
     * que no responden a la automatización). El canal no envía mientras sea así,
     * aunque el normalizador todavía no lo haya registrado: una persona la
     * revisa (y, si es una baja, la confirma marcando «no contactar»).
     *
     * @param  list<int>  $leadIds
     */
    public function everPossiblyOptedOut(array $leadIds): bool
    {
        foreach ($this->lastInboundBodies($leadIds) as $cuerpo) {
            if (self::isPossibleOptOutText(is_string($cuerpo) ? $cuerpo : null)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Es este mensaje, ENTERO, una baja SEGURA de la lista cerrada? PURA. Lo que no se puede leer no es una baja segura (sí una posible). */
    public static function isOptOutText(?string $text): bool
    {
        if ($text === null || ! mb_check_encoding($text, 'UTF-8')) {
            return false;
        }
        try {
            foreach (self::forms($text) as $forma) {
                if ($forma !== '' && in_array($forma, self::OPT_OUT_PHRASES, true)) {
                    return true;
                }
            }
        } catch (UnexpectedValueException) {
            // PCRE no pudo leerlo: no se afirma una baja SEGURA; isPossibleOptOutText() la cuenta como posible.
            return false;
        }

        return false;
    }

    /**
     * SC-2 · ¿Es este mensaje una baja segura o CONTIENE una expresión de
     * posible baja ({@see POSSIBLE_OPT_OUT})? PURA y CONSERVADORA: un mensaje
     * vacío o sin texto no lo es; uno que no se puede leer (UTF-8 inválido o
     * PCRE que falla), sí.
     */
    public static function isPossibleOptOutText(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            // No se puede leer: la duda cuenta como posible baja.
            return true;
        }
        try {
            foreach (self::forms($text) as $forma) {
                if ($forma === '') {
                    continue;
                }
                if (in_array($forma, self::OPT_OUT_PHRASES, true)) {
                    return true;
                }
                foreach (self::POSSIBLE_OPT_OUT as $patron) {
                    if (self::matches($patron, $forma)) {
                        return true;
                    }
                }
            }
        } catch (UnexpectedValueException) {
            // PCRE no pudo evaluarlo: la duda cuenta como posible baja (falla cerrado).
            return true;
        }

        return false;
    }

    /**
     * La forma normalizada PRINCIPAL ({@see forms()}, la primera): sin
     * cortesías alrededor; '' si no hay texto o no se puede leer. PURA.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || ! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        try {
            return self::forms($text)[0];
        } catch (UnexpectedValueException) {
            return '';
        }
    }

    /**
     * Las formas NORMALIZADAS en las que se busca, sin repetir y en este
     * orden: lo invisible QUITADO; si lo había, lo invisible como ESPACIO (si
     * separaba palabras, quitarlo las pegaría); y cada una con las letras
     * sueltas UNIDAS («b a j a», «b.a.j.a» → «baja»). Solo AÑADEN formas: lo
     * que veía la primera lo sigue viendo ella.
     *
     * @return non-empty-list<string>
     *
     * @throws UnexpectedValueException si PCRE falla
     */
    private static function forms(string $text): array
    {
        $formas = [self::fold($text)];
        if (self::matches('/'.self::INVISIBLE.'/u', $text)) {
            $formas[] = self::fold(self::replace('/'.self::INVISIBLE.'+/u', ' ', $text));
        }
        foreach ($formas as $forma) {
            $formas[] = self::replaceCallback('/(?<![\p{L}\p{N}])\p{L}(?![\p{L}\p{N}])(?: \p{L}(?![\p{L}\p{N}])){2,}/u',
                static fn (array $m): string => str_replace(' ', '', $m[0]), $forma);
        }

        return array_values(array_unique($formas));
    }

    /**
     * Una forma normalizada: el subconjunto latino de NFKC (como
     * `VoiceTranscriptLexicon::fold()`), minúsculas, sin invisibles ni marcas
     * combinadas, sin diacríticos, sin signos ni emojis, un espacio entre
     * palabras y sin cortesías alrededor.
     *
     * @throws UnexpectedValueException si PCRE falla
     */
    private static function fold(string $text): string
    {
        $t = self::replaceCallback('/[\x{FF01}-\x{FF5E}\x{1D400}-\x{1D6A3}\x{1D7CE}-\x{1D7FF}\x{24B6}-\x{24E9}]/u', static function (array $m): string {
            $c = (int) mb_ord($m[0], 'UTF-8');
            $letra = ($c - 0x1D400) % 52;

            // En orden de punto de código (la expresión solo deja pasar estos cuatro rangos).
            return match (true) {
                $c <= 0x24CF => chr(0x41 + $c - 0x24B6),                          // Ⓐ–Ⓩ
                $c <= 0x24E9 => chr(0x61 + $c - 0x24D0),                          // ⓐ–ⓩ
                $c <= 0xFF5E => (string) mb_chr($c - 0xFEE0, 'UTF-8'),            // ancho completo → ASCII
                $c <= 0x1D6A3 => chr($letra < 26 ? 0x41 + $letra : 0x61 + $letra - 26), // 13 estilos de 52 letras (A–Z, a–z)
                default => chr(0x30 + ($c - 0x1D7CE) % 10),                       // 5 estilos de 10 cifras
            };
        }, strtr($text, ["\u{3000}" => ' '] + self::LIGATURES));
        $t = strtr(self::replace('/'.self::INVISIBLE.'|\p{M}/u', '', mb_strtolower($t, 'UTF-8')), self::FOLD);
        $t = trim(self::replace('/[^\p{L}\p{N}]+/u', ' ', $t));
        // Las cortesías alrededor, las veces que haga falta («por favor, baja, gracias»).
        do {
            $antes = $t;
            foreach (self::COURTESY as $c) {
                if ($t === $c) {
                    return '';
                }
                if (str_starts_with($t, $c.' ')) {
                    $t = substr($t, strlen($c) + 1);
                }
                if (str_ends_with($t, ' '.$c)) {
                    $t = substr($t, 0, -strlen($c) - 1);
                }
            }
        } while ($t !== $antes);

        return $t;
    }

    /**
     * Los cuerpos de los últimos {@see OPT_OUT_SCAN} entrantes de WhatsApp de los prospectos dados (una consulta).
     *
     * @param  list<int>  $leadIds
     * @return Collection<int, mixed>
     */
    private function lastInboundBodies(array $leadIds): Collection
    {
        if ($leadIds === []) {
            return new Collection;
        }

        return DB::table('marketing_messages as m')
            ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.lead_id', $leadIds)
            ->where('c.channel', 'whatsapp')
            ->where('m.direction', 'inbound')
            ->orderByDesc('m.id')
            ->limit(self::OPT_OUT_SCAN)
            ->pluck('m.body');
    }

    /** @throws UnexpectedValueException si PCRE no puede evaluar la expresión */
    private static function matches(string $pattern, string $subject): bool
    {
        $r = preg_match($pattern, $subject);
        if ($r === false) {
            throw new UnexpectedValueException('La palabra de baja no se pudo evaluar: '.preg_last_error_msg());
        }

        return $r === 1;
    }

    /** @throws UnexpectedValueException si PCRE falla */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $r = preg_replace($pattern, $replacement, $subject);
        if ($r === null) {
            throw new UnexpectedValueException('La palabra de baja no se pudo normalizar: '.preg_last_error_msg());
        }

        return $r;
    }

    /**
     * @param  callable(array<int, string>): string  $callback
     *
     * @throws UnexpectedValueException si PCRE falla
     */
    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $r = preg_replace_callback($pattern, $callback, $subject);
        if ($r === null) {
            throw new UnexpectedValueException('La palabra de baja no se pudo normalizar: '.preg_last_error_msg());
        }

        return $r;
    }
}
