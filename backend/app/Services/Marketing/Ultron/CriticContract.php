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

    /*
     * LAS DIMENSIONES QUE VETAN (PT-17, puerta 1, L4). Decisión P-1 A del
     * propietario, 24-11-2026, literal: «El revisor solo podrá provocar
     * fallback ante errores graves, defectos de servidor o incumplimientos
     * reales de seguridad, exactitud o política. Los rechazos injustificados
     * deben evitarse sin eliminar las validaciones críticas».
     *
     * Un `fail` sin fallo duro que señala alguna de estas no es blando
     * ({@see self::isSoftFail()}): sigue llevando al respaldo. Las demás
     * dimensiones (estilo y juicio comercial: `naturalness`,
     * `progressive_disclosure`, `customer_fit`, `question_discipline`…) son
     * blandas. La clasificación es del lead (24-11-2026); cambiarla es una
     * decisión, no un retoque.
     */

    /** Seguridad: el pago, las direcciones y la derivación. */
    public const VETO_SECURITY = ['payment_safety', 'url_safety', 'handoff_leak'];

    /** Exactitud: lo que el texto afirma de los hechos, la app, la membresía, las herramientas y la memoria. */
    public const VETO_EXACTNESS = ['factuality', 'app_factuality', 'membership_factuality', 'tool_consistency', 'memory_consistency'];

    /** Política: la presión sobre la persona. */
    public const VETO_POLICY = ['pressure_control'];

    public const VETO_DIMENSIONS = [...self::VETO_SECURITY, ...self::VETO_EXACTNESS, ...self::VETO_POLICY];

    public const MAX_ISSUES = 8;

    /** Lo que admite el endpoint; el contrato guarda lo mismo, no 160. */
    public const MAX_NOTES = 500;

    /**
     * Cuántas redacciones juzga, como mucho, el revisor de un turno: la
     * primera y hasta dos reescrituras («regenerar antes de sustituir», P-2;
     * antes era una sola y el tope, 2). Un n8n S1 nunca manda 3, así que lo de
     * siempre se guarda igual.
     */
    public const MAX_ATTEMPTS = 3;

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
     * ¿Un rechazo BLANDO? (PT-17, puerta 1, L4; decisión P-1 A del
     * propietario, citada en {@see self::VETO_DIMENSIONS}.) Lo es un `fail`
     * que no declara ningún fallo duro ({@see self::HARD_FAILS}) y que no
     * señala ninguna dimensión de seguridad, exactitud o política
     * ({@see self::VETO_DIMENSIONS}). Falla cerrado: cualquier cosa en
     * `hard_fail` que no sea null ni vacía —del vocabulario o no— lo hace
     * grave, y unos `issues` que no son una lista, o un `issue` que no es una
     * dimensión del vocabulario, no permiten saberlo: tampoco es blando (lo
     * desconocido no autoriza nada; por HTTP el controlador ya lo rechaza).
     * Una lista con {@see self::MAX_ISSUES} o más tampoco: n8n la corta a ocho
     * sin ordenarla (Preparar Commit, `issues…slice(0, 8)`), y las dimensiones
     * de veto van al final del vocabulario. Con la lista llena no se puede
     * saber si el corte se llevó una dimensión de veto (revisión de la puerta
     * 1, 24-11-2026, hallazgo Importante 1).
     *
     * Solo la lee el commit (y su predicción en el laboratorio) en un turno de
     * lista, sobre la lista limpia del turno
     * ({@see UltronDraftDefects::isListFinal()}): ahí un rechazo blando es un
     * aviso (`critic_advisory`) y sale el texto del modelo, que sigue pasando
     * por todas las guardas del commit.
     */
    public static function isSoftFail(array $critic): bool
    {
        $h = $critic['hard_fail'] ?? null;
        $issues = $critic['issues'] ?? [];
        if (($critic['verdict'] ?? 'pass') !== 'fail' || ($h !== null && $h !== '') || ! is_array($issues)
            || count($issues) >= self::MAX_ISSUES) {
            return false;
        }
        foreach ($issues as $i) {
            if (! is_string($i) || ! in_array($i, self::DIMENSIONS, true) || in_array($i, self::VETO_DIMENSIONS, true)) {
                return false;
            }
        }

        return true;
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
            // Como mucho dos reescrituras: el intento es 1, 2 o 3 (el endpoint tampoco admite más).
            'attempt' => max(1, min(self::MAX_ATTEMPTS, (int) ($critic['attempt'] ?? 1))),
            'score' => isset($critic['score']) && is_numeric($critic['score']) ? round((float) $critic['score'], 2) : null,
            'issues' => self::issues($critic['issues'] ?? null),
            'hard_fail' => self::hardFailOf($critic),
            // Sin HTML, sin datos de la persona ni precios; hasta MAX_NOTES, no 160.
            'notes' => isset($critic['notes']) ? MemoryRedactor::agent(MemoryRedactor::lead(strip_tags((string) $critic['notes']), self::MAX_NOTES), self::MAX_NOTES) : null,
        ];
    }
}
