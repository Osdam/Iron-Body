<?php

namespace App\Services\Marketing\Ultron;

/**
 * Lo que el Critic puede decir, en vocabulario cerrado.
 *
 * El veredicto es una propuesta más: Laravel vuelve a comprobar el contenido
 * con sus propias guardias. Pero para poder MEDIR al Critic —si actúa, qué
 * rechaza, cuántas veces reintenta— hace falta que hable en claves fijas, no
 * en prosa. Aquí están las dimensiones que evalúa y los fallos duros que
 * obligan a reintentar.
 */
final class CriticContract
{
    /** Dimensiones de calidad (issues blandos). */
    public const DIMENSIONS = [
        'relevance', 'empathy', 'naturalness', 'context_use', 'sales_judgment', 'pressure_control',
        'next_step', 'question_discipline', 'repetition', 'phase_alignment', 'reference_resolution',
        'novelty', 'customer_fit', 'progressive_disclosure', 'factuality', 'tool_consistency',
        'memory_consistency', 'payment_safety', 'url_safety', 'app_factuality', 'handoff_leak',
        'membership_factuality',
    ];

    /** Fallos duros: con cualquiera de estos el veredicto es fail y hay reintento. */
    public const HARD_FAILS = [
        'invented_price', 'invented_plan', 'non_sellable_plan', 'invented_class', 'invented_payment_status',
        'invented_url', 'unauthorized_handoff', 'request_card_data', 'contradiction', 'major_reference_failure',
        'low_novelty', 'customer_misfit', 'pressure', 'progressive_disclosure', 'identity_lie',
        'invented_membership_fact',
    ];

    public const MAX_ISSUES = 8;

    /** Lo que admite el endpoint; el contrato guarda lo mismo, no 160. */
    public const MAX_NOTES = 500;

    /**
     * Por HTTP el controller ya rechaza lo desconocido (fail-closed, 422): este
     * filtro es la defensa en profundidad para quien llame sin pasar por él.
     *
     * @return string[] sólo claves conocidas, sin duplicados
     */
    public static function issues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $i) {
            if (is_string($i) && in_array($i, self::DIMENSIONS, true) && ! in_array($i, $out, true)) {
                $out[] = $i;
            }
        }

        return array_slice($out, 0, self::MAX_ISSUES);
    }

    /**
     * ¿Hubo veredicto? n8n emite todas las claves y las que no aplican llegan
     * en null: un bloque vacío o todo en null es «nadie juzgó», no un pass.
     */
    public static function judged(array $critic): bool
    {
        return array_filter($critic, fn ($v) => $v !== null) !== [];
    }

    /** El fallo duro declarado, si es uno del vocabulario. */
    public static function hardFailOf(array $critic): ?string
    {
        $h = $critic['hard_fail'] ?? null;

        return is_string($h) && in_array($h, self::HARD_FAILS, true) ? $h : null;
    }

    /**
     * Un veredicto es fail si lo dice, o si declara un fallo duro aunque diga
     * pass: la salida estructurada de un modelo puede contradecirse, y ante la
     * contradicción se toma el lado que no envía. Honrar el hard_fail solo mueve
     * el flujo hacia el fallback curado, nunca hacia el envío. Y un código que
     * no está en el vocabulario también cierra: por HTTP el controller ya lo
     * rechaza (422), pero en esta capa lo desconocido no autoriza nada.
     */
    public static function isFail(array $critic): bool
    {
        $h = $critic['hard_fail'] ?? null;

        return ($critic['verdict'] ?? 'pass') === 'fail' || (is_string($h) && $h !== '');
    }

    /**
     * El bloque del veredicto tal como se persiste en la acción, en los dos
     * caminos que juzgan un borrador (envío y fallo): también cuando aprueba,
     * para poder auditar al Critic y no sólo sus fallos. Los turnos bloqueados
     * antes de juzgar (inelegible, superado) no llevan veredicto; y si no vino
     * ningún bloque critic, quien llama no debe persistir nada: un pass que
     * nadie emitió no es un dato, es ruido en la medición.
     *
     * @param  array<string,mixed>  $critic
     * @return array<string,mixed>
     */
    public static function forMetadata(array $critic): array
    {
        return [
            'verdict' => self::isFail($critic) ? 'fail' : 'pass',
            // Solo hay un reintento: el intento es 1 o 2 (el endpoint tampoco admite más).
            'attempt' => max(1, min(2, (int) ($critic['attempt'] ?? 1))),
            'score' => isset($critic['score']) && is_numeric($critic['score']) ? round((float) $critic['score'], 2) : null,
            'issues' => self::issues($critic['issues'] ?? null),
            'hard_fail' => self::hardFailOf($critic),
            // Sin HTML, sin datos de la persona ni precios; hasta MAX_NOTES, no 160.
            'notes' => isset($critic['notes']) ? MemoryRedactor::agent(MemoryRedactor::lead(strip_tags((string) $critic['notes']), self::MAX_NOTES), self::MAX_NOTES) : null,
        ];
    }
}
