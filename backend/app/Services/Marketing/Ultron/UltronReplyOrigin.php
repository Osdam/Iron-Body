<?php

namespace App\Services\Marketing\Ultron;

/**
 * ¿DE DÓNDE SALIÓ EL TEXTO DE ESTA RESPUESTA? (cierre V2, apartado 4,
 * «regenerar antes de sustituir», ola 6; plan del arquitecto
 * `plan-arquitecto-a4-regenerar.md` §3 P-7 y §6 «Ola 6»; condición 5 del
 * propietario: distinguir respuestas del modelo, regeneradas y respaldos).
 *
 * No hay un campo de origen guardado: se DERIVA de la metadata de la fila de
 * `marketing_ai_actions`, con las MISMAS reglas y en el MISMO orden que la
 * consulta de la línea base (`ops/comprobacion-produccion/origen-respuestas.sql`,
 * apartado C), para que las filas históricas, sin `metadata.regeneration`, se
 * clasifiquen igual y el porcentaje se pueda comparar:
 *  1. con la clave `fallback_mode` → RESPALDO del servidor: el revisor rechazó
 *     todos los intentos (`critic.verdict = fail`), la rendición (`recovery`:
 *     el commit rechazó el turno o el revisor no contestó) o, sin ninguna de
 *     las dos, sin causa conocida;
 *  2. si no, con `policy_violations` no vacía Y `policy_discarded_draft` →
 *     RESPALDO de la política del commit (con los códigos de las violaciones);
 *  3. si no, el MODELO; recortado si la longitud (o, desde H4-9, el registro)
 *     le quitó la pregunta final.
 * Las acciones `*_recovered` (la respuesta repetida o la reventa, que el commit
 * sustituye por su último recurso) son respaldo del último recurso; la consulta
 * de la línea base no las cuenta (no son `reply`), y el informe las mete en el
 * reparto como respaldo y las deja fuera solo de la cifra de la línea base
 * (correcciones, C4 a; I-4 del revisor).
 *
 * DEL MODELO, CUÁNTAS REDACCIONES Y QUIÉN PIDIÓ CADA REESCRITURA:
 *  - con la traza (`metadata.regeneration`, ola 4), lo que dice: borradores,
 *    revisores, comprobaciones, reescrituras con su autor (`server` o
 *    `critic`) y sus códigos, y los tokens;
 *  - sin traza (filas históricas, flujo S1), los borradores son el intento del
 *    revisor (`critic.attempt`, 1 o 2) y, en S1, solo el revisor podía pedir
 *    la reescritura; con 3 sin traza no se adivina el autor (`unknown`);
 *  - sin traza ni revisor (la hora de la visita, la rendición, filas viejas),
 *    no se sabe (`attempt = unknown`).
 *
 * LA HORA DE LA VISITA (`metadata.visit_hour`) la escribe Laravel desde la
 * fila del CRM, pero la consulta de la línea base la cuenta como del modelo
 * (no tiene `fallback_mode`). Aquí se respeta esa regla y se MARCA aparte
 * (`server_text = visit_hour`), para que el informe la diga y se pueda
 * descontar sin cambiar la comparación con la línea base.
 *
 * Y LO MISMO CON LAS SUSTITUCIONES DEL SERVIDOR DENTRO DEL CAMINO DEL MODELO
 * (correcciones, C4 b; I-4 del revisor): el último recurso de la realineación
 * en un turno de cobro (`plan_price_realigned.fallback`), el texto curado del
 * cobro que el borrador negaba, el último recurso de una excusa de precio que
 * dejaba el texto vacío, la red de los marcadores (C1) —las tres, en
 * `metadata.server_replaced`, que el commit escribe solo con versión del
 * Studio— y el último recurso del desbordamiento del punto de control
 * (`length.overflow`). `server_text` dice cuál: la última del camino, que es la
 * que puso el texto que salió. El origen no cambia (la comparación con la
 * línea base, tampoco); el informe las descuenta del 90 %.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. NO DEVUELVE TEXTO:
 * solo valores de vocabularios cerrados (los códigos corregibles, las
 * dimensiones del revisor, las causas de aquí), códigos de violación y
 * etiquetas de la rendición con forma de código, y números en su rango.
 */
final class UltronReplyOrigin
{
    public const MODEL = 'model';

    public const FALLBACK = 'fallback';

    /** Del modelo: al primer intento, tras una o dos reescrituras, o no se sabe. */
    public const ATTEMPT_FIRST = 'first';

    public const ATTEMPT_REGENERATED = 'regenerated';

    public const ATTEMPT_UNKNOWN = 'unknown';

    /** Por qué salió un respaldo, en este orden en el informe. */
    public const REASON_CRITIC = 'critic_rejected';

    public const REASON_RECOVERY = 'recovery';

    public const REASON_POLICY = 'policy';

    public const REASON_LAST_RESORT = 'last_resort';

    public const REASON_UNKNOWN = 'unknown';

    public const REASONS = [self::REASON_CRITIC, self::REASON_RECOVERY, self::REASON_POLICY, self::REASON_LAST_RESORT, self::REASON_UNKNOWN];

    /** Quién pidió una reescritura. */
    public const BY_SERVER = 'server';

    public const BY_CRITIC = 'critic';

    public const BY_UNKNOWN = 'unknown';

    public const BYS = [self::BY_SERVER, self::BY_CRITIC, self::BY_UNKNOWN];

    /** Texto del servidor que la línea base cuenta como del modelo. */
    public const SERVER_VISIT_HOUR = 'visit_hour';

    /** El último recurso de la realineación del precio en un turno de cobro (`plan_price_realigned.fallback`). */
    public const SERVER_PRICE_REALIGNED = 'price_realigned_fallback';

    /** El texto curado del cobro, cuando el borrador negaba el enlace de pago (`metadata.server_replaced`). */
    public const SERVER_PAYMENT_LINK = 'payment_link_message';

    /** El último recurso de una excusa de precio que dejaba el texto vacío (`metadata.server_replaced`). */
    public const SERVER_PRICE_EXCUSE = 'price_excuse_emptied';

    /** El último recurso de la red de los marcadores del punto de control (C1, `metadata.server_replaced`). */
    public const SERVER_MARKER_LEAK = 'marker_leak';

    /** El último recurso del desbordamiento del punto de control (`length.overflow`). */
    public const SERVER_LENGTH_OVERFLOW = 'length_overflow';

    /** El texto del servidor en el camino del modelo, en el orden del camino (manda el último). */
    public const SERVER_TEXTS = [self::SERVER_VISIT_HOUR, self::SERVER_PRICE_REALIGNED, self::SERVER_PAYMENT_LINK, self::SERVER_PRICE_EXCUSE, self::SERVER_MARKER_LEAK, self::SERVER_LENGTH_OVERFLOW];

    /** Las que el commit anota en `metadata.server_replaced` (las otras dos tienen su marca de antes). */
    public const SERVER_REPLACED = [self::SERVER_PAYMENT_LINK, self::SERVER_PRICE_EXCUSE, self::SERVER_MARKER_LEAK];

    /** Un código de violación del commit (`policy_greeting_sells`, `memory_turn_wrong_commitment`…): con esa forma, o no sale. */
    private const CODIGO = '/\A[a-z][a-z0-9_]{0,63}\z/';

    /** La etiqueta de la rendición, con la forma que admite el commit (`recovery.reason`), o no sale. */
    private const ETIQUETA = '/\A[A-Za-z0-9_.:-]{1,64}\z/';

    /** Como en la traza (`UltronRegenerationTrace`). */
    private const MAX_TOKENS = 2_000_000;

    /**
     * @param  array<string,mixed>  $metadata  `marketing_ai_actions.metadata`
     * @param  string  $actionType  `marketing_ai_actions.action_type`
     * @return array{origin:string,attempt:?string,drafts:?int,critics:?int,checks:?int,rewrites:list<array{by:string,codes:list<string>,issues:list<string>}>,fallback_reason:?string,policy_codes:list<string>,recovery_reason:?string,trimmed:bool,server_text:?string,traced:bool,tokens:?array{input:?int,output:?int}}
     */
    public static function classify(array $metadata, string $actionType = 'reply'): array
    {
        $traza = is_array($metadata['regeneration'] ?? null) ? $metadata['regeneration'] : null;
        $critic = is_array($metadata['critic'] ?? null) ? $metadata['critic'] : null;

        $intentoDelRevisor = $critic === null ? null : self::entero($critic['attempt'] ?? 1, 1, CriticContract::MAX_ATTEMPTS);
        $drafts = ($traza === null ? null : self::entero($traza['drafts'] ?? null, 1, UltronDraftCheck::MAX_DRAFTS)) ?? $intentoDelRevisor;
        // Sin traza, en S1 cada borrador lo juzgó su revisor.
        $critics = $traza !== null ? self::entero($traza['critics'] ?? null, 0, CriticContract::MAX_ATTEMPTS) : $intentoDelRevisor;
        $checks = $traza === null ? null : self::entero($traza['checks'] ?? null, 0, 2);

        $rewrites = $traza !== null ? self::reescrituras($traza['rewrites'] ?? null) : self::historicas($drafts);

        $violaciones = is_array($metadata['policy_violations'] ?? null) && array_is_list($metadata['policy_violations']) ? $metadata['policy_violations'] : [];
        [$origin, $reason] = match (true) {
            str_ends_with($actionType, '_recovered') => [self::FALLBACK, self::REASON_LAST_RESORT],
            array_key_exists('fallback_mode', $metadata) => [self::FALLBACK, match (true) {
                is_array($metadata['recovery'] ?? null) => self::REASON_RECOVERY,
                CriticContract::isFail($critic ?? []) => self::REASON_CRITIC,
                default => self::REASON_UNKNOWN,
            }],
            $violaciones !== [] && array_key_exists('policy_discarded_draft', $metadata) => [self::FALLBACK, self::REASON_POLICY],
            default => [self::MODEL, null],
        };

        return [
            'origin' => $origin,
            'attempt' => $origin !== self::MODEL ? null : match (true) {
                $drafts === null => self::ATTEMPT_UNKNOWN,
                $drafts === 1 => self::ATTEMPT_FIRST,
                default => self::ATTEMPT_REGENERATED,
            },
            'drafts' => $drafts,
            'critics' => $critics,
            'checks' => $checks,
            'rewrites' => $rewrites,
            'fallback_reason' => $reason,
            'policy_codes' => $reason === self::REASON_POLICY ? self::codigos($violaciones) : [],
            'recovery_reason' => $reason === self::REASON_RECOVERY ? self::conForma($metadata['recovery']['reason'] ?? null, self::ETIQUETA) : null,
            'trimmed' => $origin === self::MODEL && (($metadata['length']['action'] ?? null) === 'trimmed' || ($metadata['register']['action'] ?? null) === 'trimmed'),
            'server_text' => $origin === self::MODEL ? self::textoDelServidor($metadata) : null,
            'traced' => $traza !== null,
            'tokens' => $traza === null || ! is_array($traza['tokens'] ?? null) ? null : [
                'input' => self::entero($traza['tokens']['input'] ?? null, 0, self::MAX_TOKENS),
                'output' => self::entero($traza['tokens']['output'] ?? null, 0, self::MAX_TOKENS),
            ],
        ];
    }

    /**
     * Las llamadas al modelo de un turno: el estratega, cada redacción y cada
     * revisor (plan §6, «Ola 6»). Null si no se sabe cuántas redacciones o
     * cuántos revisores hubo.
     *
     * @param  array{drafts:?int,critics:?int}  $classified  lo que devuelve {@see self::classify()}
     */
    public static function modelCalls(array $classified): ?int
    {
        return $classified['drafts'] === null || $classified['critics'] === null ? null : 1 + $classified['drafts'] + $classified['critics'];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Qué texto del servidor salió en el camino del modelo, o null: de las
     * marcas de la fila, la última del camino ({@see self::SERVER_TEXTS}). Solo
     * valores del vocabulario.
     */
    private static function textoDelServidor(array $metadata): ?string
    {
        $anotadas = is_array($metadata['server_replaced'] ?? null) ? $metadata['server_replaced'] : [];
        $hay = [
            self::SERVER_VISIT_HOUR => array_key_exists('visit_hour', $metadata),
            self::SERVER_PRICE_REALIGNED => is_array($metadata['plan_price_realigned'] ?? null) && ($metadata['plan_price_realigned']['fallback'] ?? null) === true,
            self::SERVER_PAYMENT_LINK => in_array(self::SERVER_PAYMENT_LINK, $anotadas, true),
            self::SERVER_PRICE_EXCUSE => in_array(self::SERVER_PRICE_EXCUSE, $anotadas, true),
            self::SERVER_MARKER_LEAK => in_array(self::SERVER_MARKER_LEAK, $anotadas, true),
            self::SERVER_LENGTH_OVERFLOW => is_array($metadata['length'] ?? null) && ($metadata['length']['overflow'] ?? null) === true,
        ];
        $ultima = null;
        foreach (self::SERVER_TEXTS as $codigo) {
            if ($hay[$codigo]) {
                $ultima = $codigo;
            }
        }

        return $ultima;
    }

    /** @return list<array{by:string,codes:list<string>,issues:list<string>}> */
    private static function reescrituras(mixed $valor): array
    {
        $out = [];
        foreach (is_array($valor) && array_is_list($valor) ? $valor : [] as $r) {
            if (! is_array($r) || ! in_array($r['by'] ?? null, [self::BY_SERVER, self::BY_CRITIC], true)) {
                continue;
            }
            $codigos = [];
            foreach (is_array($r['codes'] ?? null) ? $r['codes'] : [] as $c) {
                if (is_string($c) && in_array($c, UltronRegenerationNotes::CODES, true) && ! in_array($c, $codigos, true)) {
                    $codigos[] = $c;
                }
            }
            $out[] = ['by' => $r['by'], 'codes' => $codigos, 'issues' => CriticContract::issues($r['issues'] ?? null)];
        }

        return array_slice($out, 0, UltronDraftCheck::MAX_DRAFTS - 1);
    }

    /**
     * Sin traza: una reescritura por cada borrador de más. En S1 solo podía
     * pedirla el revisor; un tercer borrador sin traza no se atribuye.
     *
     * @return list<array{by:string,codes:list<string>,issues:list<string>}>
     */
    private static function historicas(?int $drafts): array
    {
        if ($drafts === null || $drafts < 2) {
            return [];
        }
        $by = $drafts <= UltronDraftCheck::LEGACY_MAX_DRAFTS ? self::BY_CRITIC : self::BY_UNKNOWN;

        return array_fill(0, $drafts - 1, ['by' => $by, 'codes' => [], 'issues' => []]);
    }

    /** @return list<string> */
    private static function codigos(array $valores): array
    {
        $out = [];
        foreach ($valores as $v) {
            $c = self::conForma($v, self::CODIGO);
            if ($c !== null && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private static function conForma(mixed $valor, string $forma): ?string
    {
        return is_string($valor) && preg_match($forma, $valor) === 1 ? $valor : null;
    }

    private static function entero(mixed $valor, int $min, int $max): ?int
    {
        return is_int($valor) && $valor >= $min && $valor <= $max ? $valor : null;
    }
}
