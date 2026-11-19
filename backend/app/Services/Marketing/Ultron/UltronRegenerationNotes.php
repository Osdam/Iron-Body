<?php

namespace App\Services\Marketing\Ultron;

use InvalidArgumentException;

/**
 * LAS NOTAS DE REESCRITURA DEL SERVIDOR (cierre V2, apartado 4, «regenerar
 * antes de sustituir»; plan del arquitecto `plan-arquitecto-a4-regenerar.md`
 * §3 P-5, §4.1, §4.4 y §7.2).
 *
 * Cuando la comprobación del servidor encuentra en un borrador un defecto que
 * el modelo puede corregir reescribiendo, n8n le pide otra redacción con la
 * nota de ese defecto en `critic_notes` y su `issue` en `critic_issues`: los
 * mismos campos que ya usa la reescritura que pide el revisor
 * (`composer_retry.txt`: «Corrige EXACTAMENTE lo senalado en critic_issues y
 * critic_notes»). Nadie la llama todavía (ola 1: pieza sin cablear).
 *
 * EL MECANISMO ES GENÉRICO: un código (o código/detalle) → su `issue`, del
 * vocabulario cerrado del revisor ({@see CriticContract::DIMENSIONS}), su
 * texto fijo y los parámetros que ese texto lleva, cada uno con su tipo.
 * Ampliarlo es una entrada más en {@see self::ENTRIES}, una nota aprobada y su
 * prueba.
 *
 * LOS TEXTOS SON LOS DE §7.2, LITERALES (N-R, N-L, N-P1 a N-P6 y N-B), sin
 * tildes como el resto de los prompts. Son textos fijos PENDIENTES DE LA
 * APROBACIÓN DEL PROPIETARIO (gate 4-H): no se retocan aquí.
 *
 * LOS PARÁMETROS NO LLEVAN TEXTO DE LA PERSONA: marcas de tuteo de la lista
 * cerrada de {@see UltronRegisterDetector::MARCAS}, números, y textos del
 * catálogo (el nombre de un plan o un beneficio) sin llaves, sin saltos de
 * línea y a 60 caracteres. Ninguna nota puede formar un marcador `{{…}}`.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. Un código, un
 * detalle o unos parámetros que no son los de su entrada son un error de quien
 * llama: lanza `InvalidArgumentException`.
 */
final class UltronRegenerationNotes
{
    public const REGISTER_TUTEO = 'register_tuteo';

    public const LENGTH_OVER_CAP = 'length_over_cap';

    public const PLANS_OVERVIEW = 'plans_overview';

    public const BENEFIT_MISATTRIBUTED = 'benefit_misattributed';

    /** Los códigos corregibles, cerrados (P-5). */
    public const CODES = [self::REGISTER_TUTEO, self::LENGTH_OVER_CAP, self::PLANS_OVERVIEW, self::BENEFIT_MISATTRIBUTED];

    /** Los detalles de `plans_overview` (§4.4). */
    public const DETAILS = UltronPlansOverview::DETAILS;

    /** La mezcla cabe en lo que admite `critic.notes` (y en lo que guarda el contrato del revisor). */
    public const MAX_NOTES = CriticContract::MAX_NOTES;

    /** Un texto del catálogo dentro de una nota: como mucho estos caracteres. */
    public const MAX_TEXT_PARAM = 60;

    /** Los tipos de parámetro. */
    private const MARKS = 'marks';

    private const INT = 'int';

    private const TEXT = 'text';

    // ── §7.2, literales ─────────────────────────────────────────────────────

    private const N_R = 'REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea ({MARCAS}). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.';

    private const N_L = 'DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.';

    private const N_P1 = 'FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.';

    private const N_P2 = 'MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.';

    private const N_P3 = 'NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.';

    private const N_P4 = 'MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_{K}. Usa solo esos, entre dobles llaves.';

    private const N_P5 = 'SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia.';

    private const N_P6 = 'SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores.';

    private const N_B = 'BENEFICIO DE OTRO PLAN: el borrador atribuye a {PLAN} un beneficio que en active_plans es de otro plan ("{BENEFICIO}"). A cada plan, solo los benefits de su plan_id.';

    /**
     * La tabla: `código` o `código/detalle` → [issue, texto, [parámetro => tipo]].
     * `M` de N-L es `UltronPromptCompiler::composerTargetChars(N)`: lo calcula
     * quien llama (el mismo margen que pide el texto del tope al redactor).
     */
    private const ENTRIES = [
        self::REGISTER_TUTEO => ['naturalness', self::N_R, ['MARCAS' => self::MARKS]],
        self::LENGTH_OVER_CAP => ['progressive_disclosure', self::N_L, ['L' => self::INT, 'N' => self::INT, 'M' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MISSING => ['relevance', self::N_P1, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MIXED => ['relevance', self::N_P2, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NAME_WRITTEN => ['relevance', self::N_P3, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::UNKNOWN_MARKER => ['relevance', self::N_P4, ['K' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NO_LIST => ['relevance', self::N_P5, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::PRICE_WRITTEN => ['factuality', self::N_P6, []],
        self::BENEFIT_MISATTRIBUTED => ['factuality', self::N_B, ['PLAN' => self::TEXT, 'BENEFICIO' => self::TEXT]],
    ];

    /** Los códigos que llevan detalle (los demás, ninguno). */
    private const CON_DETALLE = [self::PLANS_OVERVIEW];

    /**
     * El `issue` del revisor para un código (y su detalle, si lo lleva).
     *
     * @throws InvalidArgumentException código o detalle desconocidos
     */
    public static function issue(string $code, ?string $detail = null): string
    {
        return self::entrada($code, $detail)[0];
    }

    /**
     * La nota de un código (y su detalle), con sus parámetros: exactamente los
     * que declara su entrada.
     *
     * - `marks`: lista de marcas de tuteo; solo entran las de la lista cerrada
     *   del detector, sin repetir, como mucho {@see UltronRegisterDetector::MAX_MARCAS},
     *   separadas por «, ». Si no queda ninguna, lanza.
     * - `int`: un entero no negativo.
     * - `text`: un texto del catálogo; sin llaves, sin saltos de línea ni
     *   espacios dobles, las comillas dobles por simples (no cierran la cita
     *   de la nota) y a {@see self::MAX_TEXT_PARAM} caracteres. Si queda
     *   vacío, lanza.
     *
     * @param  array<string,mixed>  $params
     *
     * @throws InvalidArgumentException
     */
    public static function note(string $code, ?string $detail = null, array $params = []): string
    {
        [, $plantilla, $tipos] = self::entrada($code, $detail);
        $faltan = array_diff_key($tipos, $params);
        $sobran = array_diff_key($params, $tipos);
        if ($faltan !== [] || $sobran !== []) {
            throw new InvalidArgumentException("La nota de {$code} no lleva esos parámetros.");
        }

        $sustituciones = [];
        foreach ($tipos as $nombre => $tipo) {
            $sustituciones['{'.$nombre.'}'] = match ($tipo) {
                self::MARKS => self::marcas($params[$nombre]),
                self::INT => self::entero($params[$nombre]),
                self::TEXT => self::texto($params[$nombre]),
            };
        }

        return strtr($plantilla, $sustituciones);
    }

    /**
     * Las notas en orden, unidas por un espacio, hasta {@see self::MAX_NOTES}
     * caracteres POR NOTAS ENTERAS: una que no cabe se deja fuera entera y se
     * prueba la siguiente (las notas vienen por orden de prioridad). Las
     * vacías no cuentan.
     *
     * @param  list<string>  $notes
     */
    public static function merge(array $notes): string
    {
        $out = '';
        foreach ($notes as $nota) {
            $nota = trim($nota);
            if ($nota === '') {
                continue;
            }
            $con = $out === '' ? $nota : $out.' '.$nota;
            if (mb_strlen($con) <= self::MAX_NOTES) {
                $out = $con;
            }
        }

        return $out;
    }

    /**
     * Las entradas de la tabla, para recorrerlas: [código, detalle, nombres de
     * sus parámetros, texto con sus huecos].
     *
     * @return list<array{0:string,1:?string,2:list<string>,3:string}>
     */
    public static function entries(): array
    {
        $out = [];
        foreach (self::ENTRIES as $clave => [, $plantilla, $tipos]) {
            [$code, $detail] = array_pad(explode('/', $clave, 2), 2, null);
            $out[] = [$code, $detail, array_keys($tipos), $plantilla];
        }

        return $out;
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:array<string,string>} */
    private static function entrada(string $code, ?string $detail): array
    {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException('Código de reescritura desconocido.');
        }
        $conDetalle = in_array($code, self::CON_DETALLE, true);
        if ($conDetalle !== ($detail !== null)) {
            throw new InvalidArgumentException("El código {$code} ".($conDetalle ? 'lleva' : 'no lleva').' detalle.');
        }
        $clave = $detail === null ? $code : $code.'/'.$detail;
        if (! isset(self::ENTRIES[$clave])) {
            throw new InvalidArgumentException("Detalle desconocido para {$code}.");
        }

        return self::ENTRIES[$clave];
    }

    private static function marcas(mixed $valor): string
    {
        $marcas = [];
        foreach (is_array($valor) ? $valor : [] as $m) {
            if (is_string($m) && in_array($m, UltronRegisterDetector::MARCAS, true) && ! in_array($m, $marcas, true)) {
                $marcas[] = $m;
            }
        }
        if ($marcas === []) {
            throw new InvalidArgumentException('Las marcas de tuteo no son de la lista cerrada.');
        }

        return implode(', ', array_slice($marcas, 0, UltronRegisterDetector::MAX_MARCAS));
    }

    private static function entero(mixed $valor): string
    {
        if (! is_int($valor) || $valor < 0) {
            throw new InvalidArgumentException('Un parámetro numérico de la nota no es un entero no negativo.');
        }

        return (string) $valor;
    }

    private static function texto(mixed $valor): string
    {
        if (! is_string($valor)) {
            throw new InvalidArgumentException('Un parámetro de texto de la nota no es texto.');
        }
        $limpio = str_replace(['{', '}', '"'], ['', '', "'"], $valor);
        $limpio = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $limpio));
        $limpio = rtrim(mb_substr($limpio, 0, self::MAX_TEXT_PARAM));
        if ($limpio === '') {
            throw new InvalidArgumentException('Un parámetro de texto de la nota queda vacío.');
        }

        return $limpio;
    }
}
