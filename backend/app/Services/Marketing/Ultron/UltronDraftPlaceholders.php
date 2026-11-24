<?php

namespace App\Services\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\SalesConversationReplyService;

/**
 * Cómo entra un precio real en un texto que escribió una máquina que nunca vio
 * ese precio.
 *
 * El problema tiene dos mitades que se contradicen si se resuelven a la vez:
 *
 *  - Ninguna cifra escrita por el modelo puede salir. El validador ya tira
 *    cualquier `reply` con algo que parezca un precio, y así debe seguir.
 *  - Pero el agente tiene que poder decir cuánto vale el plan, con el número
 *    correcto, leído de la base de datos en el momento de escribirlo.
 *
 * Se resuelve con el ORDEN, no con una excepción a la regla:
 *
 *      draft con {{PLAN_PRICE}}  →  validar (no hay cifras, pasa)
 *                                →  sustituir por Plan::price
 *                                →  enviar
 *
 * Si se sustituyera antes de validar, la propia expresión regular mataría el
 * precio legítimo. Y si se relajara la regla para dejar pasar «precios
 * buenos», habría que distinguir un precio real de uno inventado mirando el
 * texto, que es exactamente lo que no se puede hacer.
 *
 * La lista de marcadores es cerrada. Cualquier otro `{{...}}` se rechaza: un
 * marcador que no reconocemos es una plantilla que el modelo se inventó, y
 * sustituirla a ciegas sería darle un mecanismo para escribir lo que quiera.
 */
final class UltronDraftPlaceholders
{
    public const PRICE = 'PLAN_PRICE';

    public const NAME = 'PLAN_NAME';

    public const DURATION = 'PLAN_DURATION';

    /**
     * Los enlaces oficiales de la app, por el mismo mecanismo y por la misma
     * razón: el modelo NUNCA escribe una URL, pero la respuesta sí tiene que
     * poder llevarlas.
     *
     * Antes salían en un mensaje aparte que despachaba Laravel después de la
     * respuesta, y la persona recibía dos globos seguidos: uno diciendo «te
     * paso los enlaces» y otro con los enlaces. Con el marcador es UN mensaje,
     * y las URLs las sigue escribiendo Laravel.
     */
    public const APP_LINKS = 'APP_LINKS';

    /** Los que exigen un plan resuelto para poder sustituirse. */
    private const PLAN_MARKERS = [self::PRICE, self::NAME, self::DURATION];

    public const ALLOWED = [self::PRICE, self::NAME, self::DURATION, self::APP_LINKS];

    /** Cualquier `{{ algo }}` del texto, con o sin espacios. */
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * LO QUE TIENE FORMA DE MARCADOR en un texto que va a salir (correcciones
     * de «regenerar antes de sustituir», C1; R-1 de seguridad e I-1 del
     * revisor): `{{` o `}}`; una llave seguida de `PLAN_`; `PLAN_1`, `PLAN_2`…
     * sueltos; y `PLAN_PRICE`, `PLAN_NAME`, `PLAN_DURATION` o `APP_LINKS`
     * sueltos. Sin distinguir mayúsculas: nada de eso es español que lea una
     * persona. Una llave suelta en un texto («{lunes a viernes}») no lo es.
     * Sin `\p{…}` (PCRE 10.39) y sin cuantificadores anidados.
     *
     * Y la ranura de la lista, `LISTA_DE_PLANES`, con llaves o sin ellas
     * (PT-17, puerta 2, L1): la ranura nunca puede salir por WhatsApp.
     *
     * SIN `\b` (revisión de seguridad de la puerta 2, B1): el `_` es un
     * carácter de palabra, así que con `\b` no saltaban `_LISTA_DE_PLANES_`
     * (la cursiva de WhatsApp), `laLISTA_DE_PLANES`, `LISTA_DE_PLANES2` ni
     * `_PLAN_1_`. Ninguna de estas palabras con guion bajo es español que
     * lea una persona: dentro de otra palabra también es un marcador.
     */
    private const FORMA_DE_MARCADOR = '/\{\{|\}\}|\{\s*PLAN_|PLAN_(?:[0-9]+|PRICE|NAME|DURATION)|APP_LINKS|LISTA_DE_PLANES/i';

    /** Para quitarlo: cualquier llave y cualquier `PLAN_…`, `APP_LINKS` o `LISTA_DE_PLANES`, también dentro de otra palabra (B1). */
    private const RESTOS_DE_MARCADOR = '/[{}]+|PLAN_[A-Z0-9_]*|APP_LINKS|LISTA_DE_PLANES/i';

    public function __construct(
        private readonly SalesConversationReplyService $replies = new SalesConversationReplyService,
    ) {}

    /**
     * Marcadores presentes en el texto, en orden de aparición y sin repetir.
     *
     * @return string[]
     */
    public function found(string $draft): array
    {
        preg_match_all(self::PATTERN, $draft, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Los que no reconocemos.
     *
     * Con la lista de planes del turno («regenerar antes de sustituir», ola
     * 3; plan del arquitecto §3 P-3, cambio 1), `{{PLAN_1}}` a `{{PLAN_n}}`
     * también valen: `n` es cuántos planes firmó `decide` en la lista (`po`),
     * como mucho {@see UltronPlansOverview::MAX_PLANS}. Sin lista (`n` = 0, la
     * llamada de siempre) o en un turno de cobro (quien llama pasa 0), son
     * marcadores desconocidos, como cualquier otro inventado. Los sustituye
     * {@see UltronPlansOverview::expand()}, no {@see self::resolve()}.
     *
     * Y con la lista del servidor (`$conRanura`: `k` ≥ 8; PT-17, puerta 2,
     * L1), la ranura `{{LISTA_DE_PLANES}}` también vale, con su caja exacta y
     * SOLO con lista: sin ella, o con otra caja, es un marcador desconocido.
     *
     * @param  int  $planesDeLaLista  cuántos planes tiene la lista firmada del turno; 0 sin lista
     * @param  bool  $conRanura  la lista la compone Laravel ({@see UltronPlansOverview::serverList()})
     * @return string[]
     */
    public function unknown(string $draft, int $planesDeLaLista = 0, bool $conRanura = false): array
    {
        return array_values(array_diff($this->found($draft), $this->allowed($planesDeLaLista, $conRanura)));
    }

    /**
     * Los marcadores admitidos en un turno con `n` planes en la lista: la
     * lista blanca de siempre y, solo con lista, `PLAN_1`…`PLAN_n` y, si la
     * compone Laravel (`$conRanura`), la ranura {@see UltronPlansOverview::SLOT}.
     * Sin lista, exactamente {@see self::ALLOWED}.
     *
     * @return string[]
     */
    public function allowed(int $planesDeLaLista = 0, bool $conRanura = false): array
    {
        $n = min(max($planesDeLaLista, 0), UltronPlansOverview::MAX_PLANS);
        if ($n === 0) {
            return self::ALLOWED;
        }

        return [...self::ALLOWED, ...array_map(fn (int $k): string => 'PLAN_'.$k, range(1, $n)), ...($conRanura ? [UltronPlansOverview::SLOT] : [])];
    }

    /**
     * ¿El texto pide que se cotice un plan?
     *
     * Mira SOLO los marcadores de plan, no toda la lista blanca. Si mirara
     * `ALLOWED`, una pregunta sobre la app sin plan ninguno —«¿cómo descargo la
     * app?»— moriría con `plan_required_for_placeholder`, que es un 422 y, por
     * tanto, un turno mudo.
     */
    public function needsPlan(string $draft): bool
    {
        return array_intersect($this->found($draft), self::PLAN_MARKERS) !== [];
    }

    /** ¿El borrador pide los enlaces de la app en su propio texto? */
    public function wantsAppLinks(string $draft): bool
    {
        return in_array(self::APP_LINKS, $this->found($draft), true);
    }

    /**
     * Pone los enlaces oficiales donde el modelo dejó el marcador.
     *
     * Se llama DESPUÉS de todos los guards, igual que el precio: el borrador se
     * valida sin URL —y por eso `containsUrl()` sigue siendo absoluto— y Laravel
     * escribe la URL al final. Si no hay marcador, el texto se devuelve tal cual
     * y el que decide qué hacer es quien llama.
     */
    public function resolveAppLinks(string $draft, string $reemplazo): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $m) => $m[1] === self::APP_LINKS ? $reemplazo : $m[0],
            $draft,
        );
    }

    /**
     * Sustituye los marcadores por los datos REALES del plan.
     *
     * Se llama DESPUÉS de validar el borrador. El precio sale de `Plan::price`
     * leído en este instante, no del que hubiera cuando ULTRON decidió: entre
     * una cosa y otra pueden haber cambiado la tarifa.
     *
     * Con `$nombreSinLlaves` (un turno con versión del Studio; C1), el nombre
     * pierde las llaves ({@see self::nameWithoutBraces()}): un nombre con
     * llaves en el CRM no puede volverse un marcador que otra pasada sustituya
     * (los enlaces de la app) ni salir como tal. Sin él, el nombre tal cual:
     * Legacy no cambia ni un byte.
     */
    public function resolve(string $draft, ?Plan $plan, bool $nombreSinLlaves = false): string
    {
        if ($plan === null) {
            return $draft;
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $m) => match ($m[1]) {
                self::PRICE => $this->replies->formatCop((float) $plan->price),
                self::NAME => $nombreSinLlaves ? self::nameWithoutBraces((string) $plan->name) : (string) $plan->name,
                self::DURATION => $this->duration($plan),
                default => $m[0],
            },
            $draft,
        );
    }

    /**
     * El nombre de un plan sin llaves, sin espacios dobles ni en los bordes
     * (C1). Lo usan la lista del modelo ({@see UltronPlansOverview::item()}) y
     * `{{PLAN_NAME}}` en un turno del Studio. Un nombre hecho solo de llaves
     * queda vacío.
     */
    public static function nameWithoutBraces(string $nombre): string
    {
        return trim((string) preg_replace('/ {2,}/', ' ', str_replace(['{', '}'], '', $nombre)));
    }

    /**
     * ¿Lleva el texto algo con forma de marcador? ({@see self::FORMA_DE_MARCADOR}).
     * La red del commit (`antesDeEnviar()`, C1) no deja salir un texto así.
     */
    public static function looksLikeMarker(string $texto): bool
    {
        return preg_match(self::FORMA_DE_MARCADOR, $texto) === 1;
    }

    /**
     * El texto sin nada con forma de marcador: sin llaves y sin palabras
     * `PLAN_…` ni `APP_LINKS`, sin espacios dobles. Para cuando la red no
     * tiene último recurso que mandar (todos los tumba el guard: hoy no se
     * alcanza), antes que mandar un marcador o callar.
     */
    public static function withoutMarkers(string $texto): string
    {
        return trim((string) preg_replace('/[ \t]{2,}/', ' ', (string) preg_replace(self::RESTOS_DE_MARCADOR, '', $texto)));
    }

    /** «30 días» / «1 mes»: se dice como lo diría una persona. */
    private function duration(Plan $plan): string
    {
        $dias = (int) $plan->duration_days;

        return match (true) {
            $dias <= 0 => 'sin vencimiento',
            $dias >= 28 && $dias <= 31 => 'un mes',
            $dias >= 88 && $dias <= 95 => 'tres meses',
            $dias >= 175 && $dias <= 190 => 'seis meses',
            $dias >= 360 && $dias <= 370 => 'un año',
            default => $dias.' días',
        };
    }
}
