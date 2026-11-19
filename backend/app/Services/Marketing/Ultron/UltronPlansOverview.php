<?php

namespace App\Services\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
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
 *    precio.
 *  - {@see self::item()} y {@see self::expand()}: lo que lee la persona.
 *  - {@see self::verify()}: si la lista del borrador vale, o por qué no
 *    (los seis detalles de §4.4).
 *
 * Nadie la llama todavía para la lista del modelo (ola 1: piezas sin
 * cablear); `respaldoDePrecios()` sí, para su selección.
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
     * marcador, id, nombre y duración, en el orden de `po`. SIN PRECIO: el
     * modelo nunca ve una cifra.
     *
     * @param  list<int>  $po  la lista firmada
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return list<array{marker:string,plan_id:int,name:string,duration_days:int}>
     *
     * @throws InvalidArgumentException si un id de la lista no está en el catálogo (quien llama la sacó de él)
     */
    public static function items(array $po, array $activePlans): array
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
            $items[] = [
                'marker' => self::marker($i + 1),
                'plan_id' => (int) $id,
                'name' => trim((string) ($plan['name'] ?? '')),
                'duration_days' => (int) ($plan['duration_days'] ?? 0),
            ];
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
     */
    public static function item(Plan $plan): string
    {
        $nombre = trim((string) $plan->name);
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
     * lista en el orden de §4.4: `mixed`, `name_written`, `missing`.
     *
     * @param  list<int>  $po  la lista firmada (vacía sin lista)
     * @param  array<int,array<string,mixed>>  $activePlans  el catálogo: los nombres de los planes de la lista
     */
    public static function verify(string $raw, array $po, array $activePlans): ?string
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
        $deUnPlan = [UltronDraftPlaceholders::PRICE, UltronDraftPlaceholders::NAME, UltronDraftPlaceholders::DURATION];
        if ($validos !== [] && (array_intersect($nombres, $deUnPlan) !== [] || count($validos) !== count(array_unique($validos)))) {
            return self::MIXED;
        }
        $deLaListaEnElCatalogo = array_values(array_filter($activePlans, fn (array $p) => in_array((int) $p['id'], $po, true)));
        if (CommercialTurnPolicy::plansNamedIn($raw, $deLaListaEnElCatalogo) !== []) {
            return self::NAME_WRITTEN;
        }
        if (count(array_unique($validos)) < self::MIN_MARKERS) {
            return self::MISSING;
        }

        return null;
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
