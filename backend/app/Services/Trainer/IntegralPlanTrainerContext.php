<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;

/**
 * Comprueba el plan YA VALIDADO contra lo que el entrenador indicó para él, y
 * hace los ajustes que no necesitan otra llamada al modelo. Es la misma para
 * todos los niveles de potencia: la potencia decide la profundidad; el
 * contexto, las características reales del socio.
 *
 * CON `{}` NO HACE NADA. Las apps hasta la 2.0.9 no envían contexto, y su plan
 * sale exactamente como antes.
 *
 * Con contexto:
 *  - DÍAS: la rutina tiene exactamente los días pedidos (tope 6, ver
 *    MemberContextAssembler::MAX_PLAN_DAYS). Si no, NO se guarda: fallo
 *    reintentable. No se recorta ni se rellena una rutina para que «parezca»
 *    de 6 días.
 *  - NIVEL: si lo indicó el entrenador, la rutina lleva ese nivel.
 *  - DURACIÓN: cada sesión se estima ({@see SessionTimeBudget}, con el descanso
 *    real de la app) y, si no cabe, se ajusta de lo más suave a lo más visible,
 *    parando en cuanto cabe. Se dice en el análisis.
 *  - ALERGIAS Y RESTRICCIONES: lo que el entrenador escribe se traduce a las
 *    restricciones que conoce el léxico (PlanSafetyLexicon: maní, frutos secos,
 *    lácteos, lactosa, gluten, huevo, mariscos y pescado, cerdo, carne roja,
 *    vegetariano, vegano…). Ninguna COMIDA puede nombrar un alimento de esas
 *    restricciones; si lo hace, NO se guarda: fallo reintentable. Lo que el
 *    léxico no reconoce (p. ej. «alergia a la fresa») NO se puede comprobar
 *    automáticamente: el análisis lo dice para que el entrenador revise las
 *    comidas antes de publicar. Tampoco se revisan las recomendaciones ni las
 *    notas, donde nombrar un alimento para evitarlo no es servirlo.
 *
 * Deja un informe (recibido, aplicado, ajustes) que va a la corrida de IA.
 */
class IntegralPlanTrainerContext
{
    /** Por debajo de esto ya no es una sesión: se dice, no se sigue quitando. */
    public const MIN_EXERCISES_WHEN_FITTING = 3;

    public function __construct(private readonly PlanSafetyLexicon $lexicon) {}

    /**
     * @param  array<string,mixed>  $plan  la salida de IntegralPlanValidator
     * @param  array<string,mixed>  $contexto  MemberContextAssembler::withTrainerInput()
     * @return array{plan: array<string,mixed>, report: ?array<string,mixed>}
     *
     * @throws IntegralPlanException
     */
    public function apply(array $plan, array $contexto): array
    {
        $recibido = $contexto['sources']['trainer_input'] ?? [];
        if ($recibido === []) {
            return ['plan' => $plan, 'report' => null];
        }

        $training = (array) ($contexto['training'] ?? []);
        $nivel = in_array($training['training_level'] ?? null, ['principiante', 'intermedio', 'avanzado'], true)
            ? $training['training_level'] : null;
        $pedidos = is_int($training['days_per_week'] ?? null) ? $training['days_per_week'] : null;
        $aplicados = $pedidos === null ? null : min(MemberContextAssembler::MAX_PLAN_DAYS, $pedidos);
        $minutos = is_int($training['session_minutes'] ?? null) ? $training['session_minutes'] : null;
        $dias = array_values((array) ($plan['routine']['days'] ?? []));
        $ajustes = [];

        // 1. Días: exactos o nada.
        if ($aplicados !== null && count($dias) !== $aplicados) {
            throw IntegralPlanException::contextMismatch(
                'la rutina trae '.count($dias).' '.(count($dias) === 1 ? 'día' : 'días')." y el entrenador pidió {$aplicados}",
            );
        }
        if ($pedidos !== null && $pedidos > $aplicados) {
            $ajustes[] = "Disponibilidad: se indicaron {$pedidos} días; el plan admite hasta {$aplicados} sesiones por semana y deja un día de descanso.";
        }

        // 2. Nivel: el que indicó el entrenador.
        if ($nivel !== null && in_array('training_level', $recibido, true)) {
            $plan['routine']['level'] = $nivel;
        }

        // 3. Duración: cabe o se ajusta, y se dice.
        $estimados = [];
        if ($minutos !== null && $dias !== []) {
            $cambios = ['rest' => 0, 'sets' => 0, 'removed' => 0];
            $noCaben = [];
            foreach ($dias as $i => $dia) {
                $f = SessionTimeBudget::fit(
                    array_values((array) ($dia['exercises'] ?? [])),
                    $minutos,
                    self::MIN_EXERCISES_WHEN_FITTING,
                );
                $dias[$i]['exercises'] = $f['exercises'];
                $estimados[] = ['day' => $dia['name'] ?? 'Día '.($i + 1), 'before' => $f['before'], 'after' => $f['after']];
                foreach ($f['changes'] as $k => $n) {
                    $cambios[$k] += $n;
                }
                if (! $f['fits']) {
                    $noCaben[] = $dia['name'] ?? 'Día '.($i + 1);
                }
            }
            $plan['routine']['days'] = $dias;

            $maximo = max(array_column($estimados, 'after'));
            if (array_sum($cambios) > 0) {
                $que = array_filter([
                    $cambios['rest'] > 0 ? 'descansos' : null,
                    $cambios['sets'] > 0 ? 'series' : null,
                    $cambios['removed'] > 0 ? "{$cambios['removed']} ".($cambios['removed'] === 1 ? 'ejercicio retirado' : 'ejercicios retirados') : null,
                ]);
                $ajustes[] = 'Duración: se ajustaron '.implode(', ', $que)." para que cada sesión quepa en {$minutos} min (máximo estimado: ".$this->min($maximo).' min con calentamiento).';
            }
            if ($noCaben !== []) {
                $ajustes[] = 'Duración: '.implode(', ', $noCaben)." sigue por encima de {$minutos} min incluso ajustada; revísala antes de publicar.";
            }
        }

        // 4. Alergias y restricciones que escribió el entrenador. El mensaje es
        // para el entrenador (lo enseña la app), no una orden de reparación.
        $claves = [];
        $avisos = [];
        if (in_array('restrictions', $recibido, true)) {
            $texto = (string) ($contexto['nutrition_constraints']['restrictions'] ?? '');
            $restricciones = $this->lexicon->restrictionsIn([$texto]);
            $claves = array_column($restricciones, 'key');
            foreach ($claves === [] ? [] : (array) ($plan['nutrition']['meals'] ?? []) as $m) {
                $choques = $this->lexicon->conflicts(trim(($m['label'] ?? '').' '.($m['description'] ?? '')), $claves);
                if ($choques !== []) {
                    $c = reset($choques);
                    throw IntegralPlanException::contextMismatch(
                        'la comida «'.($m['label'] ?? 'sin nombre')."» nombra «{$c['found']}», que choca con «{$c['label']}»",
                    );
                }
            }
            // Decir exactamente qué se comprobó: el léxico no conoce todos los
            // alimentos, y una alergia que no reconoce no puede quedar como
            // «verificada» sin que nadie lo sepa.
            if (! $this->dicesNinguna($texto)) {
                $avisos[] = $claves === []
                    ? 'Alergias y restricciones: lo indicado no se puede comprobar automáticamente; revisa las comidas antes de publicar.'
                    : 'Alergias y restricciones: las comidas se comprobaron contra «'.implode('», «', array_column($restricciones, 'label'))
                        .'». Cualquier otra que se haya indicado no se comprueba automáticamente: revísala antes de publicar.';
            }
        }

        $notas = array_merge($ajustes, $avisos);
        if ($notas !== [] && is_array($plan['analysis'] ?? null)) {
            $previo = trim((string) ($plan['analysis']['limitations'] ?? ''));
            $plan['analysis']['limitations'] = trim($previo.($previo === '' ? '' : ' ').implode(' ', $notas));
        }

        return ['plan' => $plan, 'report' => [
            'received' => $recibido,
            'applied' => array_filter([
                'days_per_week' => $aplicados,
                'session_minutes' => $minutos,
                'training_level' => $nivel,
                'objective' => $contexto['goal']['objective'] ?? null,
                'restriction_keys' => $claves === [] ? null : $claves,
            ], fn ($v) => $v !== null),
            'routine_days' => count($dias),
            'session_estimates' => $estimados,
            'adjustments' => $ajustes,
            'notices' => $avisos,
        ]];
    }

    /**
     * Si el texto ENTERO dice que no hay nada: «Ninguna», «Ninguna reportada»,
     * «No», «N/A», «Sin restricciones», «No tiene alergias»… Frase completa a
     * propósito: «No toma lácteos» empieza por «no» y SÍ es una restricción.
     */
    private function dicesNinguna(string $texto): bool
    {
        $t = trim($this->lexicon->normalize($texto), ' .,;:');

        return $t === '' || (bool) preg_match(
            '/^(?:ningun[ao]?|nada|no|n a|na|no aplica'
            .'|sin (?:restricciones|restriccion|alergias|alergia)'
            .'|no (?:tiene|reporta|refiere|presenta)(?: (?:alergias|alergia|restricciones|ninguna))?'
            .')(?: (?:reportad[ao]s?|conocid[ao]s?|registrad[ao]s?|alimentaria?s?))?$/u',
            $t,
        );
    }

    private function min(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
