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

    public const ALLOWED = [self::PRICE, self::NAME, self::DURATION];

    /** Cualquier `{{ algo }}` del texto, con o sin espacios. */
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

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
     * @return string[]
     */
    public function unknown(string $draft): array
    {
        return array_values(array_diff($this->found($draft), self::ALLOWED));
    }

    /** ¿El texto pide que se cotice un plan? */
    public function needsPlan(string $draft): bool
    {
        return array_intersect($this->found($draft), self::ALLOWED) !== [];
    }

    /**
     * Sustituye los marcadores por los datos REALES del plan.
     *
     * Se llama DESPUÉS de validar el borrador. El precio sale de `Plan::price`
     * leído en este instante, no del que hubiera cuando ULTRON decidió: entre
     * una cosa y otra pueden haber cambiado la tarifa.
     */
    public function resolve(string $draft, ?Plan $plan): string
    {
        if ($plan === null) {
            return $draft;
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $m) => match ($m[1]) {
                self::PRICE => $this->replies->formatCop((float) $plan->price),
                self::NAME => (string) $plan->name,
                self::DURATION => $this->duration($plan),
                default => $m[0],
            },
            $draft,
        );
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
