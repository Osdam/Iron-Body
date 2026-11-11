<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * LA ÚNICA LECTURA DEL MOTOR SOBRE EL STUDIO (contrato H5 §A4 y §A9).
 *
 * El Studio publica una declaración firmada de cómo está configurado ULTRON
 * (la cadena `ultron_studio_publications`, de solo inserción). El motor la lee
 * aquí, una vez por commit, y SELLA el turno con lo que encontró en
 * `marketing_ai_actions.metadata.studio`. Nada de lo que se lee aquí cambia lo
 * que el commit hace (§A1, opción A): ni una puerta, ni un texto, ni un 403.
 *
 * LA FRONTERA, VERIFICABLE (`UltronEngineIsolationTest`): esta es la única
 * clase del motor que nombra una tabla del Studio, y la nombra una sola vez
 * (`TABLE`). No importa nada de `App\Services\UltronStudio` ni de
 * `App\Models\UltronStudio`, no lee la configuración del Studio y no escribe:
 * en el commit, una sentencia `SELECT` de la cabeza (`max(seq)`, por el índice
 * único), y solo con `MARKETING_ULTRON_STUDIO_CONFIG` distinta de `off`.
 * `describe()`, que llama el Studio y no el commit, la lee con cualquier llave.
 * Desde la Enmienda M-1 (M1-6) la invoca además `decide`, por
 * `Prompts\UltronPromptOffer` y `partFor()`: la misma sentencia, solo con las
 * dos llaves encendidas y solo para la conversación canaria.
 *
 * NUNCA LANZA (§A4.3, paso 7). Un turno de ULTRON no puede morir por esto: un
 * 500 del commit no deja fila ni saliente, el vigía lo cuenta como turno mudo y
 * en la conversación canaria acciona el freno. Así que cualquier `Throwable` al
 * leer o verificar acaba en un sello `fallback` (`table_missing` si la tabla no
 * existe; `read_failed` en cualquier otro caso) y una línea en el log con el
 * motivo y la CLASE del error, nunca su mensaje (puede llevar SQL). Dos
 * detalles más protegen el turno:
 *  - Si hay una transacción abierta (hoy el commit no abre ninguna antes de
 *    `persist()`; las pruebas sí, con RefreshDatabase), la lectura va en un
 *    punto de guardado: en PostgreSQL una sentencia fallida deja abortada la
 *    transacción entera, y el resto del commit fallaría con 25P02.
 *  - De `effective_json` se traen como mucho `MAX_EFFECTIVE_JSON` + 1
 *    caracteres, para que una fila enorme escrita a mano no agote la memoria
 *    del proceso (eso es un error fatal, que ningún `catch` atrapa). Una
 *    declaración real ocupa menos de 1 KB; una truncada no cuadra con su huella
 *    y sale `hash_mismatch`.
 *
 * SIN CACHÉ, A PROPÓSITO (§A4.8): revertir en el Studio es insertar una entrada
 * nueva, y el commit siguiente ya tiene que verla. La misma política que la
 * pausa ({@see MarketingAgentSwitch}).
 *
 * LA FIRMA (§A4.1) tiene una sola implementación, {@see self::signatureFor()},
 * que el Studio llama al publicar y este lector al verificar. Se verifica sobre
 * los BYTES guardados, sin recanonicalizar (el motor no puede importar el JSON
 * canónico del Studio sin romper la frontera); después se decodifica en
 * estricto: claves exactas y tipos exactos, o `malformed`.
 */
final class UltronPublishedConfig
{
    /** La llave de entorno (contrato §A13). `canary` y `all` leen; todo lo demás es `off`. */
    public const OFF = 'off';

    public const CANARY = 'canary';

    public const ALL = 'all';

    /**
     * El contrato de la declaración (H5). Es también el prefijo del texto
     * firmado, para `/1` y para `/2` (§B.4: «firma … los de `/1`»).
     */
    public const CONTRACT = 'ultron-engine-config/1';

    /**
     * Enmienda M-1 (plan de motores §B.4): `/1` más, en cada parte no nula,
     * los valores gobernados (cuatro con el compilador 1; con el 2, además las
     * frases prohibidas de las reglas S-NNN) y la huella del paquete de
     * prompts compilado. Aditivo: el sello `metadata.studio` sale igual con
     * los dos; solo `Prompts\UltronPromptOffer` lee lo nuevo, por
     * {@see self::partFor()}.
     */
    public const CONTRACT_V2 = 'ultron-engine-config/2';

    /** Los contratos que sabe leer este motor. */
    public const CONTRACTS = [self::CONTRACT, self::CONTRACT_V2];

    /** Versión de la forma del sello (`metadata.studio.v`). */
    public const STAMP_VERSION = 1;

    /** Estados del sello (contrato §A4.4). En la opción A ninguno cambia lo que hace el commit. */
    public const APPLIED = 'applied';

    public const LEGACY = 'legacy';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const FALLBACK = 'fallback';

    /** Motivos de `fallback`. */
    public const CONTRACT_UNKNOWN = 'contract_unknown';

    public const HASH_MISMATCH = 'hash_mismatch';

    public const SIGNATURE_INVALID = 'signature_invalid';

    public const MALFORMED = 'malformed';

    public const TABLE_MISSING = 'table_missing';

    public const READ_FAILED = 'read_failed';

    /** Las dos partes de una entrada. */
    public const PART_CANARY = 'canary';

    public const PART_REST = 'rest';

    /** Tope, en caracteres, de una declaración legible. Una real ocupa menos de 1 KB. */
    public const MAX_EFFECTIVE_JSON = 16384;

    /** La tabla. Es la única referencia del motor al Studio y va aquí una sola vez. */
    private const TABLE = 'ultron_studio_publications';

    /**
     * La etiqueta de la subclave de firma (§A4.1), distinta de la clave con la
     * que se firma el `decide_token`: una firma de un dominio no vale en el otro.
     */
    private const KEY_LABEL = 'ultron-studio-publication';

    /** Las claves exactas de una entrada (`effective_json`). */
    private const ENTRY_KEYS = ['canary', 'contract', 'entry', 'issuedAt', 'rest', 'seq'];

    /** Las claves exactas de una parte (`canary` o `rest`). */
    private const PART_KEYS = ['approvedBy', 'catalogVersion', 'contentHash', 'declared', 'publishedBy', 'version'];

    /** En `/2`, las de `/1` más los gobernados y la huella del paquete (§B.4). */
    private const PART_KEYS_V2 = [...self::PART_KEYS, 'governed', 'prompts'];

    /** `/2`: los cuatro valores gobernados y su tipo exacto. */
    private const GOVERNED = [
        'persona.emojis' => 'boolean',
        'persona.maxChars' => 'integer',
        'persona.tone' => 'string',
        'strategy.goal' => 'string',
    ];

    /**
     * `/2` firmada con el compilador 2 o posterior (reglas S-NNN en WhatsApp):
     * `governed` lleva además esta quinta clave, una LISTA de textos (las
     * frases prohibidas). Con el compilador 1, exactamente las cuatro de
     * siempre. Los VALORES (cuántas, qué caracteres) los valida quien compila.
     */
    private const GOVERNED_PHRASES = 'rules.forbiddenPhrases';

    private const PHRASES_SINCE_COMPILER = 2;

    /** `/2`: la huella del paquete compilado, la base y la versión del compilador. */
    private const PROMPTS = [
        'base' => 'string',
        'bundleHash' => 'string',
        'compiler' => 'integer',
    ];

    /** Los cuatro valores `must_match` (§A1) y su tipo exacto. */
    private const DECLARED = [
        'governance.canaryOnly' => 'boolean',
        'governance.enabled' => 'boolean',
        'intent.driver' => 'string',
        'rules.paymentLinksEnabled' => 'boolean',
    ];

    /** Los campos de una parte que no son `declared`, y su tipo exacto. */
    private const PART_TYPES = [
        'approvedBy' => 'integer',
        'catalogVersion' => 'string',
        'contentHash' => 'string',
        'publishedBy' => 'integer',
        'version' => 'string',
    ];

    /** La profundidad de una entrada es 4 (entrada → parte → declared → valor). */
    private const JSON_DEPTH = 8;

    /**
     * El sello de este turno (contrato §A4.3 y §A4.5), o null con la llave en
     * `off`: entonces no hay ninguna consulta y la fila queda como antes del
     * Hito 5. Nunca lanza.
     *
     * @return array{v:int,env:string,state:string,entry:?string,seq:?int,part:?string,version:?string,contentHash:?string,reason:?string}|null
     */
    public function forConversation(int $conversationId): ?array
    {
        $env = self::scope();
        if ($env === self::OFF) {
            return null;
        }

        return $this->lookup($conversationId, $env)[0];
    }

    /**
     * LA PARTE QUE RIGE PARA ESTA CONVERSACIÓN, con lo que `/2` añade (plan de
     * motores §B.5, paso 4). La misma lectura y la misma resolución que
     * {@see self::forConversation()} —una sentencia, la misma escalera de
     * verificación, los mismos motivos—, y además el contrato de la fila y, si
     * la parte aplicada es `/2`, sus `governed` y `prompts` tal como se
     * firmaron (los VALORES no se validan aquí: los comprueba quien compila).
     * La llama solo `Prompts\UltronPromptOffer`, en `decide` (Enmienda M-1, M1-6).
     *
     * Null con la llave en `off`: entonces no hay ninguna consulta. Nunca lanza.
     *
     * @return array{env:string,state:string,reason:?string,contract:?string,entry:?string,seq:?int,part:?string,version:?string,contentHash:?string,governed:?array<string,mixed>,prompts:?array<string,mixed>}|null
     */
    public function partFor(int $conversationId): ?array
    {
        $env = self::scope();
        if ($env === self::OFF) {
            return null;
        }

        [$sello, $parte, $contrato] = $this->lookup($conversationId, $env, 'decide');
        $v2 = $sello['state'] === self::APPLIED && $contrato === self::CONTRACT_V2 && $parte instanceof stdClass;

        return [
            'env' => $sello['env'],
            'state' => $sello['state'],
            'reason' => $sello['reason'],
            'contract' => $contrato,
            'entry' => $sello['entry'],
            'seq' => $sello['seq'],
            'part' => $sello['part'],
            'version' => $sello['version'],
            'contentHash' => $sello['contentHash'],
            'governed' => $v2 ? get_object_vars($parte->governed) : null,
            'prompts' => $v2 ? get_object_vars($parte->prompts) : null,
        ];
    }

    /**
     * Lo que el Studio enseña de la cabeza (contrato §A4.9), sin conversación y
     * sin el id del canario: solo si existe. Las comprobaciones se calculan cada
     * una por su lado, para que el Studio distinga una huella alterada de una
     * firma que ya no vale (la `APP_KEY` cambió); `parts` solo cuenta lo que el
     * motor aplicaría, es decir, con la cabeza verificada entera.
     *
     * A diferencia de {@see self::forConversation()}, un fallo de lectura SE
     * PROPAGA: el Studio lo enseña como lectura no disponible en vez de como
     * «sin publicaciones» (el mismo criterio que sus otras lecturas del motor,
     * revisión de seguridad del Hito G, I-1). Lee la cabeza con cualquier
     * llave: `env` dice si el motor la está leyendo.
     *
     * @return array{env:string,head:array{entry:?string,seq:?int,contract:?string,hashOk:bool,signatureOk:bool,parseOk:bool}|null,parts:array{canary:bool,rest:bool},canaryConfigured:bool}
     */
    public function describe(): array
    {
        $fila = $this->head();
        $head = null;
        $parts = [self::PART_CANARY => false, self::PART_REST => false];

        if ($fila !== null) {
            $contrato = is_string($fila->engine_contract ?? null) ? $fila->engine_contract : null;
            $hashOk = self::hashOk($fila);
            $firmaOk = self::signatureOk($fila);
            $entrada = self::parse($fila);

            $head = [
                'entry' => is_string($fila->uuid ?? null) ? $fila->uuid : null,
                'seq' => self::entero($fila->seq ?? null),
                'contract' => $contrato,
                'hashOk' => $hashOk,
                'signatureOk' => $firmaOk,
                'parseOk' => $entrada !== null,
            ];

            if (in_array($contrato, self::CONTRACTS, true) && $hashOk && $firmaOk && $entrada !== null) {
                $parts = [
                    self::PART_CANARY => $entrada->canary !== null,
                    self::PART_REST => $entrada->rest !== null,
                ];
            }
        }

        return [
            'env' => self::scope(),
            'head' => $head,
            'parts' => $parts,
            'canaryConfigured' => config('marketing.ultron.canary_conversation_id') !== null,
        ];
    }

    /**
     * LA FIRMA (contrato §A4.1), su única implementación:
     *
     *   signature = hex(HMAC-SHA256(k, "ultron-engine-config/1\n" . effective_json))
     *   k         = HMAC-SHA256(clave = APP_KEY, mensaje = "ultron-studio-publication")
     *
     * `k` son los 32 bytes crudos. La subclave sigue la lectura que fija el
     * fixture del contrato (`tests/fixtures/ultron-studio/publication-cases.json`,
     * decisión S-2): el secreto es la clave del HMAC, como en todos los
     * `hash_hmac` del código, y la etiqueta separa esta firma de la del
     * `decide_token`. La `APP_KEY` va decodificada de base64 cuando lleva el
     * prefijo `base64:`, igual que la lee Laravel. Sin `APP_KEY` no hay firma:
     * lanza en vez de firmar con una clave vacía.
     *
     * Pura respecto de la base: no lee ni escribe nada; solo la `APP_KEY`.
     * Devuelve 64 caracteres hexadecimales en minúscula.
     */
    public static function signatureFor(string $effectiveJson): string
    {
        return hash_hmac('sha256', self::CONTRACT."\n".$effectiveJson, self::subkey());
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * La lectura y la resolución, una vez (contrato §A4.3, pasos 2 a 7). Nunca
     * lanza: cualquier `Throwable` es un sello `fallback`.
     *
     * @param  string|null  $via  quién lee, solo para el log (`decide`); el commit no lo dice
     * @return array{0:array<string,mixed>,1:?stdClass,2:?string} [sello, parte aplicada o null, contrato de la fila verificada o null]
     */
    private function lookup(int $conversationId, string $env, ?string $via = null): array
    {
        try {
            $fila = $this->head();
            if ($fila === null) {
                return [self::stamp($env, self::LEGACY), null, null];
            }

            [$motivo, $entrada] = $this->verified($fila);
            if ($entrada === null) {
                return [$this->fallback($env, $motivo, $fila, via: $via), null, null];
            }

            return self::resolve($env, $fila, $entrada, $conversationId);
        } catch (Throwable $e) {
            return [$this->fallback(
                $env,
                $e instanceof QueryException && self::tablaInexistente($e) ? self::TABLE_MISSING : self::READ_FAILED,
                error: $e,
                via: $via,
            ), null, null];
        }
    }

    /**
     * `off`, `canary` o `all`. Cualquier otra cosa en la configuración es `off`.
     * Pública desde la Enmienda M-1: `Prompts\UltronPromptOffer` mira la llave
     * con este mismo criterio antes de pedir nada (§B.5, paso 2).
     */
    public static function scope(): string
    {
        $valor = config('marketing.ultron.studio_config');
        $valor = is_string($valor) ? strtolower(trim($valor)) : '';

        return $valor === self::CANARY || $valor === self::ALL ? $valor : self::OFF;
    }

    /** La cabeza de la cadena: UNA sentencia, por el índice único de `seq`. */
    private function head(): ?stdClass
    {
        $db = DB::connection();
        $leer = static fn () => $db->table(self::TABLE)
            ->select(['uuid', 'seq', 'engine_contract', 'effective_hash', 'signature'])
            ->selectRaw('substr(effective_json, 1, '.(self::MAX_EFFECTIVE_JSON + 1).') as effective_json')
            ->orderByDesc('seq')
            ->limit(1)
            ->first();

        // Con una transacción abierta, en un punto de guardado: si la lectura
        // falla, se deshace solo ella y la transacción de fuera sigue viva.
        return $db->transactionLevel() > 0 ? $db->transaction($leer) : $leer();
    }

    /**
     * Las cuatro comprobaciones de §A4.3 (paso 4), en su orden; la primera que
     * falla da el motivo.
     *
     * @return array{0:?string,1:?stdClass} [motivo, entrada verificada]
     */
    private function verified(stdClass $fila): array
    {
        if (! in_array($fila->engine_contract ?? null, self::CONTRACTS, true)) {
            return [self::CONTRACT_UNKNOWN, null];
        }
        if (! self::hashOk($fila)) {
            return [self::HASH_MISMATCH, null];
        }
        if (! self::signatureOk($fila)) {
            return [self::SIGNATURE_INVALID, null];
        }

        $entrada = self::parse($fila);

        return $entrada === null ? [self::MALFORMED, null] : [null, $entrada];
    }

    private static function hashOk(stdClass $fila): bool
    {
        $json = $fila->effective_json ?? null;
        $huella = $fila->effective_hash ?? null;

        return is_string($json) && is_string($huella) && hash_equals(hash('sha256', $json), $huella);
    }

    /** Los bytes guardados, tal cual: sin decodificar ni volver a codificar antes de firmar. */
    private static function signatureOk(stdClass $fila): bool
    {
        $json = $fila->effective_json ?? null;
        $firma = $fila->signature ?? null;

        return is_string($json) && is_string($firma) && hash_equals(self::signatureFor($json), $firma);
    }

    /**
     * Decodificación ESTRICTA (contrato §A4.2): un objeto con las claves exactas
     * en sus tres niveles y los tipos exactos; el contrato, la entrada y el
     * `seq` del texto firmado, iguales a las columnas de la fila (si no, una
     * entrada firmada se podría copiar en otra fila). Las listas no pasan por
     * objetos ni al revés, porque se decodifica a objetos. Los VALORES no se
     * validan (el formato del uuid, de la huella o de `issuedAt`): eso lo
     * garantiza quien firma (fixture del contrato, R-5). Una clave de más o de
     * menos, o un tipo distinto, es null (`malformed`).
     */
    private static function parse(stdClass $fila): ?stdClass
    {
        $json = $fila->effective_json ?? null;
        if (! is_string($json)) {
            return null;
        }

        try {
            $entrada = json_decode($json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }

        if (! $entrada instanceof stdClass || ! self::clavesExactas($entrada, self::ENTRY_KEYS)) {
            return null;
        }
        if (! is_string($entrada->contract) || $entrada->contract !== ($fila->engine_contract ?? null)
            || ! is_string($entrada->entry)
            || ! is_int($entrada->seq)
            || ! is_string($entrada->issuedAt)
            || ! self::parteValida($entrada->canary, $entrada->contract)
            || ! self::parteValida($entrada->rest, $entrada->contract)) {
            return null;
        }

        // La fila y el texto firmado tienen que hablar de la misma entrada.
        // PostgreSQL devuelve el `uuid` en minúsculas: se compara sin mayúsculas.
        $uuid = $fila->uuid ?? null;
        if (! is_string($uuid) || strtolower($uuid) !== strtolower($entrada->entry) || self::entero($fila->seq ?? null) !== $entrada->seq) {
            return null;
        }

        return $entrada;
    }

    /**
     * Una parte es null o un objeto con las claves y los tipos exactos (también
     * los de `declared`). En `/2`, además `governed` y `prompts`, también con
     * sus claves y tipos exactos (§B.4); las de `governed` dependen del
     * compilador firmado ({@see self::gobernadoValido()}).
     */
    private static function parteValida(mixed $parte, string $contrato): bool
    {
        if ($parte === null) {
            return true;
        }
        $v2 = $contrato === self::CONTRACT_V2;
        if (! $parte instanceof stdClass || ! self::clavesExactas($parte, $v2 ? self::PART_KEYS_V2 : self::PART_KEYS)) {
            return false;
        }
        if ($v2 && (! self::objetoTipado($parte->prompts, self::PROMPTS) || ! self::gobernadoValido($parte->governed, $parte->prompts->compiler))) {
            return false;
        }
        foreach (self::PART_TYPES as $clave => $tipo) {
            if (gettype($parte->{$clave}) !== $tipo) {
                return false;
            }
        }
        if (! $parte->declared instanceof stdClass || ! self::clavesExactas($parte->declared, array_keys(self::DECLARED))) {
            return false;
        }
        foreach (self::DECLARED as $clave => $tipo) {
            if (gettype($parte->declared->{$clave}) !== $tipo) {
                return false;
            }
        }

        return true;
    }

    /**
     * La parte que rige para esta conversación (contrato §A4.3, pasos 5 y 6):
     * la canaria recibe `canary` y, si es null, `rest`; las demás, `rest` solo
     * con la llave en `all`. «Es la canaria» es el mismo criterio que la puerta
     * del canario del commit.
     *
     * @return array{0:array<string,mixed>,1:?stdClass,2:string} [sello, parte aplicada o null, contrato de la entrada]
     */
    private static function resolve(string $env, stdClass $fila, stdClass $entrada, int $conversationId): array
    {
        $canario = config('marketing.ultron.canary_conversation_id');

        if ($canario !== null && (int) $canario === $conversationId) {
            $parte = $entrada->canary !== null ? self::PART_CANARY : self::PART_REST;
        } elseif ($env === self::ALL) {
            $parte = self::PART_REST;
        } else {
            return [self::stamp($env, self::OUT_OF_SCOPE, $fila), null, $entrada->contract];
        }

        return $entrada->{$parte} === null
            ? [self::stamp($env, self::LEGACY, $fila), null, $entrada->contract]
            : [self::stamp($env, self::APPLIED, $fila, $entrada, $parte), $entrada->{$parte}, $entrada->contract];
    }

    // ── Sello ───────────────────────────────────────────────────────────────

    /**
     * La forma del sello (contrato §A4.5): siempre las nueve claves, en ese
     * orden. `entry` y `seq` son las columnas `uuid` y `seq` de la FILA leída,
     * también en un `fallback` con fila, para que el sello diga qué fila está
     * mal; sin fila, o sin haber podido leerla, son null (fixture del contrato,
     * R-2 y R-3). `part`, `version` y `contentHash` son los de la parte
     * aplicada, y solo en `applied`. Sin etiquetas, sin nombres y sin ids de
     * conversación (la fila de la acción ya tiene el suyo).
     */
    private static function stamp(string $env, string $state, ?stdClass $fila = null, ?stdClass $entrada = null, ?string $parte = null, ?string $reason = null): array
    {
        $aplicada = $parte === null ? null : $entrada?->{$parte};
        $uuid = $fila?->uuid ?? null;

        return [
            'v' => self::STAMP_VERSION,
            'env' => $env,
            'state' => $state,
            'entry' => is_string($uuid) ? $uuid : null,
            'seq' => self::entero($fila?->seq ?? null),
            'part' => $aplicada === null ? null : $parte,
            'version' => $aplicada?->version,
            'contentHash' => $aplicada?->contentHash,
            'reason' => $reason,
        ];
    }

    private function fallback(string $env, string $motivo, ?stdClass $fila = null, ?Throwable $error = null, ?string $via = null): array
    {
        try {
            ChannelLog::warning('ultron.published_config.fallback', array_filter([
                'env' => $env,
                'reason' => $motivo,
                'error_class' => $error === null ? null : class_basename($error),
                'via' => $via,
            ], fn ($v) => $v !== null));
        } catch (Throwable) {
            // Tragado a propósito: observar no puede cambiar lo observado, y un
            // log que no se puede escribir no es motivo para dejar un turno mudo.
            // ChannelLog ya absorbe sus propios fallos de escritura; esto cubre
            // lo que va antes (el redactor del contexto).
        }

        return self::stamp($env, self::FALLBACK, $fila, reason: $motivo);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La subclave de §A4.1 (32 bytes crudos): HMAC con la `APP_KEY` como clave
     * y la etiqueta como mensaje (ver {@see self::signatureFor()}). La `APP_KEY`
     * se lee como la lee Laravel (`EncryptionServiceProvider::parseKey`).
     */
    private static function subkey(): string
    {
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7));
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se firman ni se verifican publicaciones.');
        }

        return hash_hmac('sha256', self::KEY_LABEL, $clave, true);
    }

    /**
     * Un objeto con exactamente esas claves y esos tipos (`gettype`).
     *
     * @param  array<string,string>  $tipos  clave → tipo
     */
    private static function objetoTipado(mixed $objeto, array $tipos): bool
    {
        if (! $objeto instanceof stdClass || ! self::clavesExactas($objeto, array_keys($tipos))) {
            return false;
        }
        foreach ($tipos as $clave => $tipo) {
            if (gettype($objeto->{$clave}) !== $tipo) {
                return false;
            }
        }

        return true;
    }

    /**
     * `governed` de una parte `/2`, ESTRICTO y ligado al compilador firmado
     * (ya comprobado entero): con el 1, las cuatro claves de siempre; desde el
     * 2, esas cuatro y {@see self::GOVERNED_PHRASES}, una lista (no un objeto)
     * de textos. Una forma de un compilador con otra forma es `malformed`.
     */
    private static function gobernadoValido(mixed $governed, int $compilador): bool
    {
        if ($compilador < self::PHRASES_SINCE_COMPILER) {
            return self::objetoTipado($governed, self::GOVERNED);
        }
        if (! self::objetoTipado($governed, self::GOVERNED + [self::GOVERNED_PHRASES => 'array'])) {
            return false;
        }
        $frases = $governed->{self::GOVERNED_PHRASES};
        if (! array_is_list($frases)) {
            return false;
        }
        foreach ($frases as $frase) {
            if (! is_string($frase)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $claves */
    private static function clavesExactas(stdClass $objeto, array $claves): bool
    {
        $tiene = array_keys(get_object_vars($objeto));
        sort($tiene);
        sort($claves);

        return $tiene === $claves;
    }

    /** Un entero de la base (los dos drivers pueden devolverlo como cadena), o null. */
    private static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
    }

    /** El mismo criterio que {@see MarketingAgentSwitch}: PostgreSQL 42P01, MySQL 42S02 y SQLite «no such table». */
    private static function tablaInexistente(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['42P01', '42S02'], true)
            || str_contains($e->getMessage(), 'no such table');
    }
}
