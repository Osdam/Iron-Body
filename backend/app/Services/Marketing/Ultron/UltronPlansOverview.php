<?php

namespace App\Services\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;
use InvalidArgumentException;

/**
 * LA LISTA DE PLANES QUE REDACTA EL MODELO (cierre V2, apartado 4,
 * «regenerar antes de sustituir»; plan del arquitecto
 * `plan-arquitecto-a4-regenerar.md` §3 P-3, §4.4 y §7.3; decisión L-2 del
 * lead).
 *
 * A «¿qué planes tienen y cuánto cuestan?» el modelo no puede contestar con
 * cifras (nunca ve un precio) ni con un solo marcador de precio (se resuelve
 * contra un plan). Con esto el modelo redacta la respuesta y nombra cada plan
 * con un marcador POSICIONAL, `{{PLAN_1}}` a `{{PLAN_3}}`, que apunta a la
 * lista que eligió el servidor y firmó en el token (`po`): Laravel pone el
 * nombre y el precio leídos del catálogo en ese momento. «Solo planes
 * ofrecidos» se cumple por construcción: el número apunta a la lista firmada,
 * no a un id que escriba el modelo. El de dos puntos (`{{PLAN:<id>}}`) no
 * casa con el patrón de los marcadores y saldría literal (H10).
 *
 * LAS PIEZAS:
 *  - {@see self::select()}: QUÉ planes. Es la selección de
 *    `UltronCommitService::respaldoDePrecios()` (la lista fija), extraída tal
 *    cual para que las dos listas, la fija y la del modelo, elijan los mismos
 *    (pendiente m-8 de H4-12 (f), solo la selección).
 *  - {@see self::markers()} y {@see self::items()}: lo que ve el modelo, sin
 *    precio (con los beneficios de cada plan junto a su marcador).
 *  - {@see self::item()} y {@see self::expand()}: lo que lee la persona.
 *  - {@see self::verify()}: si la lista del borrador vale, o por qué no
 *    (los seis detalles de §4.4).
 *
 * La llaman `decide` (la lista que ve el modelo, firmada en `po`), el commit
 * (expandirla y juzgarla), la comprobación `ai/check` (medirla y señalar sus
 * defectos) y `respaldoDePrecios()` (su selección).
 *
 * LA LISTA LA COMPONE LARAVEL (PT-17, puerta 2, L1; decisiones P-2 A y
 * «Ranura A» del propietario, 24-11-2026). Con el compilador 8 (`sp.k` ≥ 8,
 * {@see self::serverList()}) el modelo ya no escribe la lista: escribe la
 * prosa y marca con la ranura `{{LISTA_DE_PLANES}}` dónde va (forma B), o no
 * la marca y el servidor la pone antes de la pregunta final. La lista sale
 * entera, en el orden firmado de `po`, con los nombres y los precios de las
 * filas de `Plan` leídas en ese momento ({@see self::compose()},
 * {@see self::place()} y {@see self::render()}, el único punto que usan
 * `ai/check`, el commit y el laboratorio). La forma A (`{{PLAN_k}}`) sigue
 * valiendo, pero estricta: todos los planes y en su orden
 * ({@see self::verify()}). Legacy y los tokens de antes (`k` ≤ 7) no entran
 * en nada de esto.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. El catálogo
 * (`activePlans()`) y los `Plan` los pasa quien llama. Sin `\p{…}` en las
 * expresiones: el PCRE de producción (10.39) no las compila todas.
 */
final class UltronPlansOverview
{
    /** Como mucho cuántos planes: el mismo límite que la lista fija y que el revisor. */
    public const MAX_PLANS = 3;

    /** Una lista válida nombra al menos dos planes distintos de la lista. */
    public const MIN_MARKERS = 2;

    /**
     * El patrón de los marcadores, el MISMO que `UltronDraftPlaceholders`
     * (privado allí): lo que ese validador no ve como marcador, esto tampoco.
     */
    public const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** Un nombre de marcador que pretende ser de la lista: `PLAN_` y cifras, con cualquier caja (`plan_1`, `PLAN_01`, `PLAN_4`). */
    private const PARECE_DE_LA_LISTA = '/\APLAN_[0-9]+\z/i';

    // ── Los seis detalles de `plans_overview` (§4.4), en el orden de la tabla ──

    /** La lista se pidió y el borrador nombra menos de dos planes distintos de ella. */
    public const MISSING = 'missing';

    /** Marcadores de la lista junto a `PLAN_NAME`, `PLAN_PRICE` o `PLAN_DURATION`, o uno repetido. */
    public const MIXED = 'mixed';

    /** El nombre de un plan de la lista escrito a mano (su marcador ya lo pone). */
    public const NAME_WRITTEN = 'name_written';

    /** Un `PLAN_k` que no es de la lista (`k` > n, `PLAN_0`, `PLAN_01`, `plan_1`). */
    public const UNKNOWN_MARKER = 'unknown_marker';

    /** Un `PLAN_k` en un turno sin lista (o de cobro: quien llama pasa la lista vacía). */
    public const NO_LIST = 'no_list';

    /** Con lista, una cifra o un precio escrito por el modelo. */
    public const PRICE_WRITTEN = 'price_written';

    public const DETAILS = [self::MISSING, self::MIXED, self::NAME_WRITTEN, self::UNKNOWN_MARKER, self::NO_LIST, self::PRICE_WRITTEN];

    // ── La lista que compone Laravel (PT-17, puerta 2, L1) ─────────────────

    /**
     * LA RANURA (forma B): `{{LISTA_DE_PLANES}}`, con esta caja exacta y una
     * sola vez. La admite la lista blanca de marcadores solo en un turno con
     * la lista y con `k` ≥ {@see self::SERVER_LIST_SINCE_COMPILER}
     * (`UltronDraftPlaceholders::allowed()`); fuera de ese turno es un 422.
     */
    public const SLOT = 'LISTA_DE_PLANES';

    /** Desde qué compilador (`sp.k`) la lista la compone Laravel ({@see self::serverList()}). */
    public const SERVER_LIST_SINCE_COMPILER = 8;

    /** Forma A: la lista con sus marcadores `{{PLAN_k}}`. */
    public const SHAPE_MARKERS = 'markers';

    /** Forma B: la ranura, o ninguna marca de lista. */
    public const SHAPE_SERVER = 'server';

    /** El separador de la lista: el de la lista fija (`UltronReplyFitter::composePricing()`). */
    public const SEPARATOR = '; ';

    /*
     * Los encabezados de la lista fija (`UltronCommitService::respaldoDePrecios()`)
     * sin «Claro. », aprobados por el propietario (H0): con ellos empieza la
     * frase de la lista que el servidor pone sin ranura ({@see self::header()}).
     */
    public const HEADER_ALL_MONTHLY = 'Planes de un mes: ';

    public const HEADER_SOME_MONTHLY = 'Algunos planes de un mes: ';

    public const HEADER_ALL = 'Nuestros planes: ';

    public const HEADER_SOME = 'Algunos de nuestros planes: ';

    /**
     * EL INTERRUPTOR DE §6 (revisión final de la puerta 2, I1-c y menor 3):
     * ¿pone el servidor la lista en un borrador de forma B SIN ranura (antes de
     * la pregunta final)? Por defecto, sí. Apagado (false), ese borrador no
     * recibe la lista ({@see self::render()}: `placed` = false) y es `missing`
     * ({@see self::verify()}): `ai/check` pide la ranura y el commit, en el
     * turno de lista, da la lista fija. Se apaga cambiando esta constante (y
     * desplegando): el riesgo 1 de §6 es que el revisor juzga un borrador sin
     * la lista que pondrá el servidor.
     */
    public const INSERT_WITHOUT_SLOT = true;

    /**
     * Lo que se aplica: {@see self::INSERT_WITHOUT_SLOT}. Variable solo para
     * que las pruebas ejerzan el interruptor apagado (lo cambian y lo
     * restauran); el código de la aplicación no la escribe.
     */
    public static bool $insertWithoutSlot = self::INSERT_WITHOUT_SLOT;

    /** La ranura, con o sin espacios dentro de las llaves (como el resto de marcadores) y con su caja exacta. */
    private const RANURA = '/\{\{\s*LISTA_DE_PLANES\s*\}\}/';

    /** La ranura con cualquier caja: lo que se quita antes de buscar su nombre suelto. */
    private const RANURA_CON_CUALQUIER_CAJA = '/\{\{\s*LISTA_DE_PLANES\s*\}\}/i';

    /** El nombre de la ranura en cualquier sitio y con cualquier caja (suelto, con una llave o entre dos). */
    private const NOMBRE_DE_LA_RANURA = '/LISTA_DE_PLANES/i';

    /**
     * La ranura con una llave pegada delante o detrás (`{{{LISTA_DE_PLANES}}}`;
     * revisión de seguridad de la puerta 2, M1): la lista saldría entre llaves
     * sueltas.
     */
    private const RANURA_CON_LLAVE_PEGADA = '/\{\s*\{\{\s*LISTA_DE_PLANES\s*\}\}|\{\{\s*LISTA_DE_PLANES\s*\}\}\s*\}/';

    /**
     * La ranura con, como mucho, UNA llave pegada a cada lado (revisión final
     * de la puerta 2, menor 1; sonda S4): {@see self::place()} la sustituye
     * entera, para que la lista no salga entre llaves sueltas donde nadie la
     * para (con herramientas). El espacio de alrededor solo se lo queda con la
     * llave: sin ella, el resto del borrador queda byte a byte. Sigue siendo
     * `mixed` ({@see self::verify()}, M1).
     */
    private const RANURA_Y_SUS_LLAVES = '/(?:\{\s*)?\{\{\s*LISTA_DE_PLANES\s*\}\}(?:\s*\})?/';

    /** Una llave pegada a cualquier marcador (`{{{PLAN_1}}}`; M1): también sería un resto. */
    private const LLAVE_PEGADA_A_UN_MARCADOR = '/\{\s*\{\{|\}\}\s*\}/';

    /**
     * Lo que un texto de la lista nunca lleva (revisión de seguridad de la
     * puerta 2, M2): saltos de línea (`\r`, `\n`, U+000B, U+000C, U+0085,
     * U+2028 y U+2029) y los controles de dirección (U+202A a U+202E y
     * U+2066 a U+2069), que cambian lo que se lee. Con `/u` y `\x{…}`, sin
     * `\p{…}`: el PCRE de producción es el 10.39.
     */
    private const CONTROLES_EN_UN_TEXTO = '/[\r\n\x{0B}\x{0C}\x{85}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    // ── Qué planes ──────────────────────────────────────────────────────────

    /**
     * El catálogo del que se elige: los vendibles sin los que la política da
     * por RECHAZADOS en este turno (`forbidden_plan_ids`), salvo el que la
     * persona acaba de nombrar (`hard_required_plan`): nombrarlo lo reabre.
     * En el orden de `activePlans()`.
     *
     * @param  array<string,mixed>  $policy  la del turno ({@see CommercialTurnPolicy::decide()})
     * @param  array<int,array<string,mixed>>  $activePlans  `activePlans()`: cada plan con `id` y `duration_days`
     * @return list<array<string,mixed>>
     */
    public static function candidates(array $policy, array $activePlans): array
    {
        $nombrado = self::nombrado($policy);
        $rechazados = array_map('intval', (array) ($policy['forbidden_plan_ids'] ?? []));

        return array_values(array_filter(
            $activePlans,
            fn (array $p) => (int) $p['id'] === $nombrado || ! in_array((int) $p['id'], $rechazados, true),
        ));
    }

    /**
     * QUÉ PLANES, sin ningún id ni nombre escrito aquí: el que nombró la
     * persona, si nombró uno; después los vendibles de un mes (28 a 31 días,
     * el criterio de `defaultMonthlyPlan()`) en el orden de `activePlans()`;
     * como mucho tres. Sin ninguno de un mes, los tres primeros. Nunca los
     * rechazados ({@see self::candidates()}).
     *
     * Extraída TAL CUAL de `UltronCommitService::respaldoDePrecios()`
     * (`97b59195`, líneas 5449-5466): lo fija una prueba de paridad contra
     * esa copia sobre una matriz de catálogos.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return list<int> los ids, en orden
     */
    public static function select(array $policy, array $activePlans): array
    {
        $nombrado = self::nombrado($policy);
        $catalogo = self::candidates($policy, $activePlans);
        $ids = array_map(fn (array $p) => (int) $p['id'], $catalogo);
        $deUnMes = fn (array $p) => self::isMonthly((int) $p['duration_days']);
        $hayDeUnMes = array_filter($catalogo, $deUnMes) !== [];

        $elegidos = $nombrado !== null && in_array($nombrado, $ids, true) ? [$nombrado] : [];
        foreach ($catalogo as $p) {
            if (count($elegidos) < self::MAX_PLANS && ($deUnMes($p) || ! $hayDeUnMes) && ! in_array((int) $p['id'], $elegidos, true)) {
                $elegidos[] = (int) $p['id'];
            }
        }

        return $elegidos;
    }

    /** Un plan de un mes: de 28 a 31 días (el criterio de `defaultMonthlyPlan()` y de la lista fija). */
    public static function isMonthly(int $days): bool
    {
        return $days >= 28 && $days <= 31;
    }

    // ── La lista del turno ──────────────────────────────────────────────────

    /**
     * La lista firmada (`po`) si tiene la forma que emite `decide`: de
     * {@see self::MIN_MARKERS} a {@see self::MAX_PLANS} id enteros positivos y
     * distintos, en lista; si no, null. Está firmada, así que nadie la
     * reescribe por el camino; aun así, otra forma no se interpreta. La usan el
     * commit y la comprobación (`ai/check`, ola 4), para que lean lo mismo
     * (extraída tal cual de `UltronCommitService::listaFirmada()`).
     *
     * @return list<int>|null
     */
    public static function signedList(mixed $po): ?array
    {
        $valida = is_array($po) && array_is_list($po)
            && count($po) >= self::MIN_MARKERS && count($po) <= self::MAX_PLANS
            && array_filter($po, fn (mixed $id): bool => is_int($id) && $id > 0) === $po
            && count(array_unique($po)) === count($po);

        return $valida ? $po : null;
    }

    /**
     * ¿Es un turno de cobro? El enlace de pago pedido o una intención de cobro
     * (P-3, cambio 5): en un turno de cobro la lista NO VALE y sus marcadores
     * son desconocidos; el dinero nunca va pegado a una lista de tres precios.
     * Se lee de la PROPUESTA (el validador solo puede quitarle a la intención
     * lo de cobro, nunca dárselo). La usan el commit (paso 8.ter) y la
     * comprobación (extraída tal cual del commit).
     *
     * @param  array<int,mixed>  $tools  las herramientas que pide el borrador
     */
    public static function isMoneyTurn(string $intent, array $tools): bool
    {
        return in_array(SalesIntents::TOOL_PAYMENT_LINK_SEND, $tools, true)
            || in_array($intent, [SalesIntents::HIGH_INTENT_CLOSE, SalesIntents::PAYMENT_LINK_REQUEST], true);
    }

    /**
     * ¿La lista la compone Laravel en este turno? Solo con versión del Studio
     * (`sp.m` = `studio`) compilada con el compilador
     * {@see self::SERVER_LIST_SINCE_COMPILER} o posterior (`sp.k`, que firma
     * `decide`). Legacy, la línea base y cualquier token con `k` ≤ 7: no, y
     * todo sigue como antes de la puerta 2.
     *
     * @param  array<string,mixed>  $sp  el `sp` firmado en el token
     */
    public static function serverList(array $sp): bool
    {
        $k = $sp['k'] ?? null;

        return ($sp['m'] ?? null) === UltronPromptStamp::MODE_STUDIO && is_int($k) && $k >= self::SERVER_LIST_SINCE_COMPILER;
    }

    /**
     * La forma de la lista de un borrador SIN expandir:
     * {@see self::SHAPE_MARKERS} si algún marcador pretende ser de la lista
     * (`PLAN_` y cifras, con cualquier caja: {@see self::PARECE_DE_LA_LISTA});
     * si no, {@see self::SHAPE_SERVER} (la ranura o ninguna marca).
     */
    public static function shape(string $raw): string
    {
        preg_match_all(self::PATTERN, $raw, $m);
        foreach ($m[1] ?? [] as $nombre) {
            if (preg_match(self::PARECE_DE_LA_LISTA, $nombre) === 1) {
                return self::SHAPE_MARKERS;
            }
        }

        return self::SHAPE_SERVER;
    }

    /**
     * ¿Trae el borrador SIN expandir la ranura ({@see self::SLOT}, con su caja
     * exacta, con o sin espacios dentro de las llaves)? Lo mismo que anota el
     * commit en `metadata.plans_overview.slot`. La usa
     * {@see UltronDraftDefects::isListFinal()} (revisión final, I1-b).
     */
    public static function hasSlot(string $raw): bool
    {
        return preg_match(self::RANURA, $raw) === 1;
    }

    // ── Lo que ve el modelo ─────────────────────────────────────────────────

    /** `{{PLAN_k}}`, de 1 a {@see self::MAX_PLANS}. */
    public static function marker(int $k): string
    {
        if ($k < 1 || $k > self::MAX_PLANS) {
            throw new InvalidArgumentException('El marcador de la lista va de PLAN_1 a PLAN_'.self::MAX_PLANS.'.');
        }

        return '{{PLAN_'.$k.'}}';
    }

    /**
     * Los marcadores de una lista de `n` planes, en orden.
     *
     * @return list<string>
     */
    public static function markers(int $n): array
    {
        if ($n < 0 || $n > self::MAX_PLANS) {
            throw new InvalidArgumentException('Una lista lleva de 0 a '.self::MAX_PLANS.' planes.');
        }

        return array_map(self::marker(...), $n === 0 ? [] : range(1, $n));
    }

    /**
     * La lista tal como la ve el modelo (`plans_overview.plans` de `decide`):
     * marcador, id, nombre, duración y beneficios, en el orden de `po`. SIN
     * PRECIO: el modelo nunca ve una cifra.
     *
     * LOS BENEFICIOS, JUNTO A SU MARCADOR (22-11-2026, corrección (3) del turno
     * 2 de la tercera prueba real): `PLAN_3` es una POSICIÓN, pero
     * `active_plans` y la memoria hablan de los planes por su id; en la prueba,
     * el modelo pegó detrás de `{{PLAN_3}}` (el Élite, id 8) los beneficios del
     * Plan Valera (id 3). Aquí van los de SU plan, los MISMOS y con el MISMO
     * filtro con que viajan en `active_plans` (`UltronDecideService::
     * plansWithoutPrice()`): la lista de `activePlans()` tal cual (ya limpia,
     * `Plan::benefitsArray()`), sin recortar en número ni en longitud, y sin la
     * clave si está vacía. No viaja nada que el modelo no reciba ya.
     *
     * SIN MARCADORES con la lista del servidor (PT-17, puerta 2, L1; causa C3
     * del diagnóstico): con `k` ≥ 8 el modelo ve un solo sistema, la ranura,
     * y cada plan sin su `marker` (`$withMarkers` = false). No viaja nada nuevo.
     *
     * @param  list<int>  $po  la lista firmada
     * @param  array<int,array<string,mixed>>  $activePlans
     * @param  bool  $withMarkers  false con la lista del servidor ({@see self::serverList()})
     * @return list<array{marker?:string,plan_id:int,name:string,duration_days:int,benefits?:list<string>}>
     *
     * @throws InvalidArgumentException si un id de la lista no está en el catálogo (quien llama la sacó de él)
     */
    public static function items(array $po, array $activePlans, bool $withMarkers = true): array
    {
        $porId = [];
        foreach ($activePlans as $p) {
            $porId[(int) $p['id']] = $p;
        }
        $items = [];
        foreach (array_values($po) as $i => $id) {
            $plan = $porId[(int) $id] ?? null;
            if ($plan === null) {
                throw new InvalidArgumentException('Un plan de la lista no está en el catálogo.');
            }
            $items[] = ($withMarkers ? ['marker' => self::marker($i + 1)] : []) + [
                'plan_id' => (int) $id,
                'name' => trim((string) ($plan['name'] ?? '')),
                'duration_days' => (int) ($plan['duration_days'] ?? 0),
            ] + (is_array($plan['benefits'] ?? null) && $plan['benefits'] !== [] ? ['benefits' => $plan['benefits']] : []);
        }

        return $items;
    }

    // ── Lo que lee la persona ───────────────────────────────────────────────

    /**
     * El texto de UN plan, para sustituir su marcador (§7.3, formato
     * recomendado, L-2): «Nombre ($65.000 COP)»; si el plan no es de 28 a 31
     * días, «Nombre (7 días, $45.000 COP)», con «1 día» en singular (F5) y las
     * MISMAS palabras de duración que la lista fija («tres meses», «un año»…,
     * las de `UltronDraftPlaceholders`). El precio, con `formatCop()`, el
     * formateador de la lista fija. Si el propietario prefiere el formato de
     * la lista fija («Nombre, $65.000 COP»), es este método.
     *
     * Lee los atributos del `Plan` que pasa quien llama (leído en ese
     * momento: el precio puede haber cambiado desde `decide`); no consulta.
     *
     * El nombre, SIN LLAVES (correcciones de «regenerar antes de sustituir»,
     * C1; R-1 de seguridad): un `{{PLAN_PRICE}}` o un `{{APP_LINKS}}` escrito
     * en el nombre en el CRM no se reexpande con lo de otro plan ni con los
     * enlaces. Lo que quede con forma de marcador lo para la red del commit.
     */
    public static function item(Plan $plan): string
    {
        $nombre = UltronDraftPlaceholders::nameWithoutBraces((string) $plan->name);
        $precio = (new SalesConversationReplyService)->formatCop((float) $plan->price);
        $dias = (int) $plan->duration_days;
        if (self::isMonthly($dias)) {
            return $nombre.' ('.$precio.')';
        }
        $duracion = $dias === 1 ? '1 día' : (new UltronDraftPlaceholders)->resolve('{{'.UltronDraftPlaceholders::DURATION.'}}', $plan);

        return $nombre.' ('.$duracion.', '.$precio.')';
    }

    /**
     * Sustituye cada `{{PLAN_k}}` de la lista (con o sin espacios dentro de
     * las llaves, como el resto de marcadores) por el texto de su plan. Lo que
     * no es de la lista (`PLAN_4` con tres, `PLAN_0`, `PLAN_01`, `plan_1`, los
     * demás marcadores) y un plan sin texto quedan LITERALES: los juzgan el
     * validador de marcadores y {@see self::verify()}, no esto.
     *
     * @param  array<int,string>  $textos  plan_id → su texto ({@see self::item()})
     * @param  list<int>  $po  la lista firmada
     * @return array{text:string,used:list<int>} el texto y los planes sustituidos, por orden de aparición y sin repetir
     */
    public static function expand(string $draft, array $textos, array $po): array
    {
        $po = array_values(array_map('intval', $po));
        $usados = [];
        $texto = (string) preg_replace_callback(self::PATTERN, function (array $m) use ($textos, $po, &$usados): string {
            $k = self::posicion($m[1], count($po));
            if ($k === null || ! isset($textos[$po[$k - 1]])) {
                return $m[0];
            }
            $id = $po[$k - 1];
            if (! in_array($id, $usados, true)) {
                $usados[] = $id;
            }

            return $textos[$id];
        }, $draft);

        return ['text' => $texto, 'used' => $usados];
    }

    /**
     * LA LISTA ENTERA, COMPUESTA POR LARAVEL (PT-17, puerta 2, L1; P-2 A):
     * el texto de cada plan de `po` ({@see self::item()}: «Nombre ($65.000
     * COP)», o «Nombre (7 días, $45.000 COP)» si no es de un mes), en el
     * orden firmado de `po` y unidos por {@see self::SEPARATOR}.
     *
     * TODO O NADA: si falta el texto de CUALQUIER plan de `po` (ya no se
     * vende), null; nunca una lista parcial. Y null también si un texto es
     * inseguro ({@see self::unsafeListText()}: forma de marcador, como un
     * nombre del CRM con `PLAN_1`, un salto de línea o un control de
     * dirección): la lista no puede llevar nada que la red del commit no
     * dejaría salir ni que cambie lo que se lee. Lo que hay, sale de `$textos`
     * tal cual: ni se añade ni se quita nada salvo el separador.
     *
     * PURA: las mismas entradas dan los mismos bytes, en cualquier orden de
     * claves de `$textos`.
     *
     * @param  list<int>  $po  la lista firmada, con la forma de {@see self::signedList()}
     * @param  array<int,string>  $textos  plan_id → su texto, leído ahora y solo de planes vendibles
     *
     * @throws InvalidArgumentException si `$po` no tiene la forma de la lista firmada
     */
    public static function compose(array $po, array $textos): ?string
    {
        if (self::signedList($po) === null) {
            throw new InvalidArgumentException('La lista del turno no tiene la forma de la lista firmada.');
        }
        $partes = [];
        foreach ($po as $id) {
            $texto = $textos[$id] ?? null;
            if (! is_string($texto) || trim($texto) === '' || self::unsafeListText($texto)) {
                return null;
            }
            $partes[] = $texto;
        }
        $lista = implode(self::SEPARATOR, $partes);

        return self::unsafeListText($lista) ? null : $lista;
    }

    /**
     * ¿Un texto de la lista no puede salir? Si tiene forma de marcador
     * ({@see UltronDraftPlaceholders::looksLikeMarker()}) o un salto de línea
     * o un control de dirección ({@see self::CONTROLES_EN_UN_TEXTO}). Lo usa
     * {@see self::compose()} y el commit, para decir qué plan lo tiene (por su
     * id, sin el nombre).
     */
    public static function unsafeListText(string $texto): bool
    {
        // Falla cerrado: un texto que no es UTF-8 válido (`preg_match` da false) tampoco sale en la lista.
        return UltronDraftPlaceholders::looksLikeMarker($texto) || preg_match(self::CONTROLES_EN_UN_TEXTO, $texto) !== 0;
    }

    /**
     * EL NOMBRE SIN LOS CONTROLES DE {@see self::CONTROLES_EN_UN_TEXTO}
     * (revisión final de la puerta 2, menor 2; sonda S7), para la lista fija,
     * que no suelta un plan por su nombre: los saltos de línea pasan a un
     * espacio (como `MarketingKnowledgeBaseService::asData()`) y los controles
     * de dirección se quitan; después, sin espacios dobles ni en los bordes.
     * Un texto sin ninguno sale tal cual, byte a byte. Un texto que no es
     * UTF-8 válido se sanea antes (`mb_scrub`): la expresión no puede fallar.
     */
    public static function withoutControls(string $texto): string
    {
        $valido = mb_check_encoding($texto, 'UTF-8') ? $texto : mb_scrub($texto, 'UTF-8');
        if (preg_match(self::CONTROLES_EN_UN_TEXTO, $valido) !== 1) {
            return $valido;
        }
        $sinSaltos = preg_replace('/[\r\n\x{0B}\x{0C}\x{85}\x{2028}\x{2029}]/u', ' ', $valido) ?? $valido;
        $sinDireccion = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $sinSaltos) ?? $sinSaltos;

        return trim((string) preg_replace('/ {2,}/', ' ', $sinDireccion));
    }

    /**
     * El encabezado de la frase de la lista que se inserta sin ranura: los de
     * la lista fija sin «Claro. ». Si todos los planes de `po` son de un mes,
     * {@see self::HEADER_SOME_MONTHLY} si hay otro de un mes vendible fuera de
     * `po` y, si no, {@see self::HEADER_ALL_MONTHLY}; si no, {@see self::HEADER_SOME}
     * si hay cualquier vendible fuera de `po` y, si no, {@see self::HEADER_ALL}.
     *
     * Mira `$activePlans` (el catálogo vendible) y no la política, que en el
     * punto del commit donde se compone aún no está calculada: así siempre
     * dice la verdad, aunque a veces diga «Algunos» de más (un plan rechazado
     * cuenta como fuera de la lista).
     *
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $activePlans  `activePlans()`: cada plan con `id` y `duration_days`
     */
    public static function header(array $po, array $activePlans): string
    {
        $po = array_values(array_map('intval', $po));
        $deUnMes = [];
        foreach ($activePlans as $p) {
            $deUnMes[(int) $p['id']] = self::isMonthly((int) ($p['duration_days'] ?? 0));
        }
        $fuera = array_values(array_filter(array_keys($deUnMes), fn (int $id): bool => ! in_array($id, $po, true)));
        $todosDeUnMes = $po !== [] && array_filter($po, fn (int $id): bool => ! ($deUnMes[$id] ?? false)) === [];
        if ($todosDeUnMes) {
            return array_filter($fuera, fn (int $id): bool => $deUnMes[$id]) !== [] ? self::HEADER_SOME_MONTHLY : self::HEADER_ALL_MONTHLY;
        }

        return $fuera !== [] ? self::HEADER_SOME : self::HEADER_ALL;
    }

    /**
     * Pone la lista en el borrador.
     *  - Con UNA ranura ({@see self::SLOT}, con o sin espacios dentro de las
     *    llaves), la sustituye por la lista y deja el resto byte a byte
     *    (`slot` = true). Con una llave pegada a un lado o a los dos
     *    (`{{{LISTA_DE_PLANES}}}`), la llave se va con la ranura (menor 1:
     *    {@see self::RANURA_Y_SUS_LLAVES}); `verify()` lo sigue dando por
     *    `mixed`.
     *  - Con más de una, null: no se elige.
     *  - Sin ranura, añade la frase «encabezado + lista + .»: justo antes de la
     *    última frase ({@see UltronReplyFitter::sentences()}) si esa frase
     *    acaba en «?»; si no, al final, separada por un espacio (`slot` =
     *    false). Las frases del modelo no cambian ni de texto ni de orden:
     *    quitando la frase insertada (y su espacio) queda el borrador tal cual.
     *
     * @return array{text:string,slot:bool}|null
     */
    public static function place(string $raw, string $lista, string $header): ?array
    {
        $ranuras = preg_match_all(self::RANURA, $raw);
        if ($ranuras > 1) {
            return null;
        }
        if ($ranuras === 1) {
            // Con un callback: un precio («$65.000») no se lee como una referencia del reemplazo.
            return ['text' => (string) preg_replace_callback(self::RANURA_Y_SUS_LLAVES, static fn (): string => $lista, $raw, 1), 'slot' => true];
        }

        $frase = $header.$lista.'.';
        if (trim($raw) === '') {
            return ['text' => $frase, 'slot' => false];
        }
        $frases = UltronReplyFitter::sentences($raw);
        $ultima = $frases === [] ? null : $frases[count($frases) - 1][0];
        if ($ultima !== null && str_ends_with(trim($ultima), '?')) {
            $antes = '';
            foreach (array_slice($frases, 0, -1) as [$texto, $separador]) {
                $antes .= $texto.$separador;
            }
            $donde = (strlen($raw) - strlen(ltrim($raw))) + strlen($antes);
            if (substr($raw, $donde, strlen($ultima)) === $ultima) {
                return ['text' => substr($raw, 0, $donde).$frase.' '.substr($raw, $donde), 'slot' => false];
            }
        }
        $cuerpo = rtrim($raw);

        return ['text' => $cuerpo.' '.$frase.substr($raw, strlen($cuerpo)), 'slot' => false];
    }

    /**
     * EL ÚNICO PUNTO CON EL QUE `ai/check`, EL COMMIT Y EL LABORATORIO PONEN LA
     * LISTA EN EL BORRADOR (PT-17, puerta 2, L1): los tres ven el mismo texto.
     *  - Sin lista (`po` vacía), el borrador tal cual.
     *  - Sin la lista del servidor (`$serverList` false: Legacy o `k` ≤ 7), o
     *    con forma A, la expansión de siempre ({@see self::expand()}), con
     *    `placed` = al menos dos planes sustituidos.
     *  - Con forma B, la lista entera ({@see self::compose()}) puesta en su
     *    sitio ({@see self::place()}), con `used` = `po`. Si no se puede
     *    componer (falta un plan) o colocar (más de una ranura), el borrador
     *    tal cual y `placed` = false: quien llama no lo deja salir. Y lo mismo
     *    sin ranura con el interruptor de §6 apagado
     *    ({@see self::INSERT_WITHOUT_SLOT}; I1-c).
     *
     * @param  list<int>  $po  la lista del turno (vacía sin lista o en un turno de cobro)
     * @param  array<int,string>  $textos  plan_id → su texto ({@see self::item()}), leído ahora
     * @param  array<int,array<string,mixed>>  $activePlans  `activePlans()`, para el encabezado
     * @return array{text:string,shape:string,slot:bool,used:list<int>,placed:bool}
     */
    public static function render(string $raw, array $po, array $textos, array $activePlans, bool $serverList): array
    {
        $po = array_values(array_map('intval', $po));
        $forma = self::shape($raw);
        if ($po === []) {
            return ['text' => $raw, 'shape' => $forma, 'slot' => false, 'used' => [], 'placed' => false];
        }
        if (! $serverList || $forma === self::SHAPE_MARKERS) {
            $r = self::expand($raw, $textos, $po);

            return ['text' => $r['text'], 'shape' => $forma, 'slot' => false, 'used' => $r['used'], 'placed' => count($r['used']) >= self::MIN_MARKERS];
        }
        $lista = self::compose($po, $textos);
        $sinRanuraApagado = ! self::$insertWithoutSlot && ! self::hasSlot($raw);
        $puesta = $lista === null || $sinRanuraApagado ? null : self::place($raw, $lista, self::header($po, $activePlans));
        if ($puesta === null) {
            return ['text' => $raw, 'shape' => $forma, 'slot' => preg_match(self::RANURA, $raw) === 1, 'used' => $po, 'placed' => false];
        }

        return ['text' => $puesta['text'], 'shape' => $forma, 'slot' => $puesta['slot'], 'used' => $po, 'placed' => true];
    }

    // ── ¿Vale la lista del borrador? ────────────────────────────────────────

    /**
     * El detalle de por qué la lista del borrador (SIN expandir) no vale, o
     * null si vale. Con lista, una lista válida nombra al menos dos planes
     * distintos de ella, solo con sus marcadores, cada uno una vez, sin
     * `PLAN_NAME`/`PLAN_PRICE`/`PLAN_DURATION`, sin escribir a mano el nombre
     * de un plan de la lista y sin cifras. Sin lista (`po` vacía: no se
     * ofreció o es un turno de cobro), solo es defecto un `PLAN_k`.
     *
     * Que los planes sigan vendibles AHORA (H14) no es de esto: el modelo no
     * puede arreglarlo reescribiendo; lo decide el commit.
     *
     * UNO solo, en este orden de precedencia (decisión de `backend`, el plan
     * no la fija): primero lo que en el commit acaba en un 422 y en la
     * rendición —`unknown_marker` (marcador desconocido) y `price_written`
     * (el validador tira cualquier cifra)—; después la estructura de la
     * lista en el orden de §4.4: `mixed`, `name_written`, `missing`. Con una
     * excepción (correcciones, C2; I-2 del revisor): si el borrador no lleva
     * NINGÚN marcador de la lista, `missing` antes que `name_written`. Es el
     * borrador típico del turno de precios (un plan a mano con
     * `{{PLAN_PRICE}}`): lo que le falta es la lista, y quitarle el nombre
     * dejaba otro borrador que volvía a dar `missing`.
     *
     * Y SI HABLA DE UN SOLO PLAN, FALTA LA LISTA (PT-17, puerta 1, L2; C2 del
     * diagnóstico del 24-11-2026): contados los planes de la lista que nombra,
     * por su marcador o a mano, si son menos de dos —«el Plan Valera … todo
     * por {{PLAN_1}}», el nombre y el marcador del MISMO plan, o «el
     * {{PLAN_1}} por {{PLAN_PRICE}}»—, `missing`, justo después del marcador
     * desconocido y de la cifra (que siguen mandando) y antes que `mixed` y
     * `name_written`. En la medición del compilador 7, diez borradores así
     * recibieron solo «NOMBRE DUPLICADO» y uno «MEZCLADO», nunca «falta la
     * lista», y el siguiente siguió con un plan. Con dos planes distintos o
     * más, lo de siempre: el repetido o mezclado es `mixed`, y uno a mano
     * («{{PLAN_1}} y el Plan Mensual») es `name_written`. Solo cambia el
     * detalle (y con él la nota): todos son un defecto, igual que antes.
     *
     * CON LA LISTA DEL SERVIDOR (`$serverList`, `k` ≥ 8; PT-17, puerta 2, L1),
     * en este orden de precedencia:
     *  1. Sin lista (`po` vacía): la ranura, una variante suya (otra caja, o
     *     su nombre suelto) o un `PLAN_k` → `no_list`.
     *  2. Un `PLAN_k` fuera de 1..n, o la ranura con otra caja → `unknown_marker`.
     *  3. Cualquier cifra → `price_written`.
     *  4. La ranura y algún `PLAN_k` a la vez, la ranura repetida, la ranura
     *     junto a `PLAN_NAME`, `PLAN_PRICE` o `PLAN_DURATION`, su nombre
     *     suelto fuera de llaves, o una llave pegada a la ranura
     *     (`{{{LISTA_DE_PLANES}}}`, M1) → `mixed`.
     *  5. Forma A, estricta (P-2: ni omitir ni reordenar): lo de siempre (con
     *     su precedencia, también la de la puerta 1) y, si eso la daba por
     *     buena, todos los planes de `po` (si faltan, `missing`) y en su orden
     *     1..n (si no, `mixed`). Así una lista que ya no valía da el mismo
     *     detalle que antes, y solo cambian las que valían.
     *  6. Forma B: con `PLAN_NAME`, `PLAN_PRICE` o `PLAN_DURATION` (cotiza un
     *     plan en vez de dar la lista) → `missing`; sin la ranura y con el
     *     interruptor de §6 apagado ({@see self::INSERT_WITHOUT_SLOT}; I1-c)
     *     → `missing`; el nombre escrito a mano de un plan de la lista, o de
     *     un plan del catálogo que NO SE VENDE ahora (de `$knownPlans`, el que
     *     no está en `$activePlans`, que es `Plan::sellable()`; revisión final,
     *     I1-a acotado por el lead: ofrecer lo que no se vende, el Élite
     *     desactivado de la sonda S3), con la misma lectura que la puerta 1
     *     ({@see CommercialTurnPolicy::plansNamedIn()}) → `name_written`; si
     *     no, null. Uno que SE VENDE, aunque no sea de la lista, se puede
     *     nombrar: es verdad (la sonda S2), como en la forma A. Un nombre que
     *     no es del catálogo (uno inventado) no lo ve: lo juzga el revisor.
     *  7. Y una lista que por lo demás vale (5 o 6) pero deja restos con forma
     *     de marcador fuera de las llaves (`PLAN_1` suelto, `{PLAN_1}`) →
     *     `mixed` ({@see self::dejaRestosDeMarcador()}).
     * Sin `$serverList`, lo de hoy, byte a byte.
     *
     * @param  list<int>  $po  la lista firmada (vacía sin lista)
     * @param  array<int,array<string,mixed>>  $activePlans  el catálogo VENDIBLE (`activePlans()`): los nombres de los planes de la lista y, con `$knownPlans`, cuáles se venden
     * @param  bool  $serverList  la lista la compone Laravel ({@see self::serverList()})
     * @param  array<int,array<string,mixed>>|null  $knownPlans  con la lista del servidor, los planes que conoce el servidor, se vendan o no (id y nombre; `MarketingKnowledgeBaseService::knownPlans()`): para la regla 6 cuentan los que no están en `$activePlans`; sin ellos, solo los de la lista
     */
    public static function verify(string $raw, array $po, array $activePlans, bool $serverList = false, ?array $knownPlans = null): ?string
    {
        return $serverList ? self::verificaConLaListaDelServidor($raw, $po, $activePlans, $knownPlans ?? []) : self::verificaLosMarcadores($raw, $po, $activePlans);
    }

    /**
     * {@see self::verify()} con la lista del servidor (`k` ≥ 8), en el orden
     * de precedencia que dice allí.
     *
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $activePlans
     * @param  array<int,array<string,mixed>>  $knownPlans
     */
    private static function verificaConLaListaDelServidor(string $raw, array $po, array $activePlans, array $knownPlans): ?string
    {
        $po = array_values(array_map('intval', $po));
        preg_match_all(self::PATTERN, $raw, $m);
        $nombres = $m[1] ?? [];
        $deLaLista = array_values(array_filter($nombres, fn (string $n): bool => preg_match(self::PARECE_DE_LA_LISTA, $n) === 1));
        $ranuras = count(array_filter($nombres, fn (string $n): bool => $n === self::SLOT));
        $otraCaja = count(array_filter($nombres, fn (string $n): bool => $n !== self::SLOT && strcasecmp($n, self::SLOT) === 0));
        $suelto = preg_match(self::NOMBRE_DE_LA_RANURA, (string) preg_replace(self::RANURA_CON_CUALQUIER_CAJA, '', $raw)) === 1;

        // 1. Sin lista.
        if ($po === []) {
            return $ranuras + $otraCaja + count($deLaLista) > 0 || $suelto ? self::NO_LIST : null;
        }
        // 2. Un marcador de la lista que no existe, o la ranura con otra caja.
        if ($otraCaja > 0) {
            return self::UNKNOWN_MARKER;
        }
        foreach ($deLaLista as $n) {
            if (self::posicion($n, count($po)) === null) {
                return self::UNKNOWN_MARKER;
            }
        }
        // 3. Una cifra.
        if (SalesAgentDecisionSchema::containsPrice($raw)) {
            return self::PRICE_WRITTEN;
        }
        // 4. Las dos formas a la vez, la ranura repetida o junto a los de un plan, o su nombre suelto.
        $deUnPlan = array_intersect($nombres, [UltronDraftPlaceholders::PRICE, UltronDraftPlaceholders::NAME, UltronDraftPlaceholders::DURATION]) !== [];
        if (($ranuras > 0 && $deLaLista !== []) || $ranuras > 1 || ($ranuras > 0 && $deUnPlan) || $suelto
            || preg_match(self::RANURA_CON_LLAVE_PEGADA, $raw) === 1) {
            return self::MIXED;
        }
        // 5. Forma A, estricta.
        if ($deLaLista !== []) {
            $deSiempre = self::verificaLosMarcadores($raw, $po, $activePlans);
            if ($deSiempre !== null) {
                return $deSiempre;
            }
            $posiciones = array_map(fn (string $n): int => (int) self::posicion($n, count($po)), $deLaLista);
            if (count(array_unique($posiciones)) < count($po)) {
                return self::MISSING;
            }
            if ($posiciones !== range(1, count($po))) {
                return self::MIXED;
            }

            return self::dejaRestosDeMarcador($raw) ? self::MIXED : null;
        }
        // 6. Forma B.
        if ($deUnPlan) {
            return self::MISSING;
        }
        // Sin la ranura y sin la inserción (I1-c), lo que falta es la lista: antes que un nombre a mano, como en la puerta 1 (C2).
        if ($ranuras === 0 && ! self::$insertWithoutSlot) {
            return self::MISSING;
        }
        // A mano, ni uno de la lista (su texto lo pone el servidor) ni uno que no se vende (I1-a acotado: ofrecer lo que no se vende). Uno que se vende, sí: es verdad.
        $vendibles = array_map(static fn (array $p): int => (int) $p['id'], $activePlans);
        $deLaListaEnElCatalogo = array_values(array_filter($activePlans, fn (array $p): bool => in_array((int) $p['id'], $po, true)));
        $queNoSeVenden = array_values(array_filter($knownPlans, static fn (array $p): bool => ! in_array((int) $p['id'], $vendibles, true)));
        if (CommercialTurnPolicy::plansNamedIn($raw, [...$deLaListaEnElCatalogo, ...$queNoSeVenden]) !== []) {
            return self::NAME_WRITTEN;
        }

        return self::dejaRestosDeMarcador($raw) ? self::MIXED : null;
    }

    /**
     * ¿Le queda al borrador algo con forma de marcador FUERA de los marcadores
     * bien escritos (`{{NOMBRE}}`)? Un `PLAN_1` sin llaves, `{PLAN_1}` con
     * una, `{{PLAN 1}}`, llaves sueltas o una llave pegada a un marcador
     * (`{{{PLAN_1}}}`, M1, que saldría entre llaves): la red del commit no lo dejaría
     * salir ({@see UltronDraftPlaceholders::looksLikeMarker()}) y el texto
     * entero se cambiaría por el último recurso. Con la lista del servidor, una
     * lista que por lo demás vale y deja esos restos es `mixed` (PT-17, puerta
     * 2, L1; las sondas S4 y S5 del revisor): `ai/check` pide reescribirla y,
     * en el turno del panorama, sale la lista fija, como con el compilador 7.
     * Solo se mira cuando todo lo demás vale: no cambia ningún otro detalle.
     */
    private static function dejaRestosDeMarcador(string $raw): bool
    {
        return preg_match(self::LLAVE_PEGADA_A_UN_MARCADOR, $raw) === 1
            || UltronDraftPlaceholders::looksLikeMarker((string) preg_replace(self::PATTERN, '', $raw));
    }

    /**
     * {@see self::verify()} sin la lista del servidor: la de siempre (con la
     * forma A y la regla de la puerta 1), sin cambiar un byte.
     *
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $activePlans
     */
    private static function verificaLosMarcadores(string $raw, array $po, array $activePlans): ?string
    {
        $po = array_values(array_map('intval', $po));
        preg_match_all(self::PATTERN, $raw, $m);
        $nombres = $m[1] ?? [];
        $deLaLista = array_values(array_filter($nombres, fn (string $n) => preg_match(self::PARECE_DE_LA_LISTA, $n) === 1));

        if ($po === []) {
            return $deLaLista === [] ? null : self::NO_LIST;
        }

        $validos = [];
        foreach ($deLaLista as $n) {
            $k = self::posicion($n, count($po));
            if ($k === null) {
                return self::UNKNOWN_MARKER;
            }
            $validos[] = $k;
        }
        if (SalesAgentDecisionSchema::containsPrice($raw)) {
            return self::PRICE_WRITTEN;
        }
        $deLaListaEnElCatalogo = array_values(array_filter($activePlans, fn (array $p) => in_array((int) $p['id'], $po, true)));
        $aMano = array_map('intval', CommercialTurnPolicy::plansNamedIn($raw, $deLaListaEnElCatalogo));
        // Los planes de la lista de los que habla, por su marcador o a mano: con menos de dos, lo que falta es la lista (PT-17, L2).
        $planes = array_unique([...array_map(fn (int $k): int => $po[$k - 1], $validos), ...$aMano]);
        if (count($planes) < self::MIN_MARKERS) {
            return self::MISSING;
        }
        $deUnPlan = [UltronDraftPlaceholders::PRICE, UltronDraftPlaceholders::NAME, UltronDraftPlaceholders::DURATION];
        if ($validos !== [] && (array_intersect($nombres, $deUnPlan) !== [] || count($validos) !== count(array_unique($validos)))) {
            return self::MIXED;
        }
        if ($validos === []) {
            return self::MISSING;
        }

        return $aMano === [] ? null : self::NAME_WRITTEN;
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** El plan que nombró la persona en este turno, o null (la misma lectura que la lista fija). */
    private static function nombrado(array $policy): ?int
    {
        return ($policy['hard_required_plan'] ?? null) === null ? null : (int) $policy['hard_required_plan'];
    }

    /** La posición `k` de un nombre de marcador si es EXACTAMENTE `PLAN_k` con 1 ≤ k ≤ n; si no, null. */
    private static function posicion(string $nombre, int $n): ?int
    {
        if (preg_match('/\APLAN_([1-9][0-9]*)\z/', $nombre, $m) !== 1) {
            return null;
        }
        $k = (int) $m[1];

        return $k <= $n && $k <= self::MAX_PLANS ? $k : null;
    }
}
