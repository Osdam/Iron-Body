<?php

namespace App\Services\Trainer;

use App\Models\IronAiUserProfile;
use App\Models\Member;
use App\Models\NutritionGoal;
use App\Models\NutritionGuide;
use App\Models\ProfessionalAssessment;
use App\Services\Nutrition\NutritionGoalCalculatorService;
use Carbon\Carbon;

/**
 * Todo lo que Iron Body ya sabe de un socio, en una sola estructura.
 *
 * POR QUÉ EXISTE. El entrenador tecleaba a mano datos que el sistema ya tenía:
 * la edad estando `birth_date`, el objetivo estando en el perfil, las
 * restricciones estando en la guía anterior. Y peso, estatura, grasa y masa
 * muscular se pedían DOS veces —una en la valoración y otra en la guía— como si
 * fueran datos distintos. Aquí se resuelve una vez, desde su fuente
 * autoritativa, y ese mismo resultado sirve para precargar el formulario y,
 * más adelante, para alimentar a Iron IA.
 *
 * CADA DATO TIENE UNA SOLA FUENTE, y está declarada:
 *
 *   peso, estatura, grasa, masa muscular → última valoración enviada
 *   edad                                 → members.birth_date
 *   sexo, objetivo, nivel, lesiones      → perfil del socio
 *   restricciones, suplementos           → última guía publicada
 *   metabolismo basal                    → NutritionGoalCalculatorService
 *
 * NO INVENTA NADA. Lo que no tiene fuente sale `null` y el entrenador lo
 * rellena. Un valor sugerido de origen desconocido es peor que un campo vacío:
 * el vacío se ve, el inventado se firma.
 *
 * NO SE PERSISTE. Se arma al vuelo cada vez. Guardar una copia crearía un
 * tercer sitio donde vive el peso del socio, y con eso el tercer sitio donde
 * puede quedar desactualizado.
 */
class MemberContextAssembler
{
    /** Sesiones por semana que el plan sabe estructurar (IntegralPlanStrategy::SPLITS). */
    public const MAX_PLAN_DAYS = 6;

    public function __construct(
        private readonly NutritionGuideService $guides,
        private readonly NutritionGoalCalculatorService $calculator,
        private readonly PlanSafetyLexicon $lexicon,
    ) {}

    /**
     * El contexto completo, agrupado como lo pide la pantalla y como lo pedirá
     * el generador de planes.
     *
     * @return array<string,mixed>
     */
    public function for(Member $member): array
    {
        return $this->forAssessment($member, $this->guides->lastAssessmentFor($member));
    }

    /**
     * {@see for()} con la valoración YA elegida. El plan integral la elige una
     * vez al empezar y usa ESA misma instancia para el prompt y para guardar:
     * si entra otra valoración mientras responde el modelo, el plan no queda
     * partido entre dos valoraciones.
     *
     * @return array<string,mixed>
     */
    public function forAssessment(Member $member, ?ProfessionalAssessment $assessment): array
    {
        $guide = $this->lastPublishedGuideFor($member);

        $composition = $this->bodyComposition($member, $assessment, $guide);

        return [
            'member' => $this->memberBlock($member),
            'body_composition' => $composition,
            'measurements' => $this->measurements($assessment),
            'goal' => $this->goal($member, $guide),
            'training' => $this->training($member),
            'nutrition_constraints' => $this->nutritionConstraints($member, $guide),
            'trainer_notes' => $this->trainerNotes($assessment),
            // De dónde salió cada cosa. La pantalla lo usa para decir «viene de
            // la valoración del 3 de marzo» en vez de mostrar un número sin
            // explicación, y quien audite un plan puede rehacer el camino.
            'sources' => [
                'assessment_id' => $assessment?->id,
                'assessment_uuid' => $assessment?->uuid,
                'assessment_at' => optional($assessment?->submitted_at)->toIso8601String(),
                'guide_id' => $guide?->id,
                'guide_uuid' => $guide?->uuid,
                'guide_at' => optional($guide?->published_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * Lo que el entrenador escribió en «Contexto para el plan», encima de lo
     * que Iron Body ya sabe. SOLO para esta generación: no se escribe en el
     * perfil del socio ni en su guía (no nace una segunda fuente de verdad).
     *
     * Precedencia: lo que el entrenador envía ahora manda sobre el perfil y la
     * última guía publicada; lo que no envía (la app omite los vacíos) sigue
     * saliendo de donde salía. Con `$input = []` el contexto es IDÉNTICO al de
     * antes: las apps hasta la 2.0.9 envían `{}`.
     *
     * Las claves nuevas (`training.days_per_week`, `training.session_minutes`,
     * `trainer_notes.plan_note`) solo existen si llegaron, para que una
     * petición vacía no cambie ni un byte de lo que ve el modelo.
     *
     * @param  array<string,mixed>  $contexto  lo que devolvió {@see for()}
     * @param  array<string,mixed>  $input  GenerateIntegralPlanRequest::trainerInput()
     * @return array<string,mixed>
     */
    public function withTrainerInput(array $contexto, array $input): array
    {
        if ($input === []) {
            return $contexto;
        }

        $texto = fn (string $k) => $this->text($input[$k] ?? null);

        if (($v = $texto('objective')) !== null) {
            $contexto['goal']['objective'] = $v;
        }
        if (($v = $this->trainingLevel($input['training_level'] ?? null)) !== null) {
            $contexto['training']['training_level'] = $v;
        }
        if (($v = $texto('injuries')) !== null) {
            $contexto['training']['injuries'] = $v;
            $contexto['nutrition_constraints']['injuries'] = $v;
        }
        if (($v = $texto('restrictions')) !== null) {
            $contexto['nutrition_constraints']['restrictions'] = $v;
        }
        if (is_int($input['days_per_week'] ?? null)) {
            $contexto['training']['days_per_week'] = $input['days_per_week'];
        }
        if (is_int($input['session_minutes'] ?? null)) {
            $contexto['training']['session_minutes'] = $input['session_minutes'];
        }
        if (($v = $texto('observations')) !== null) {
            // Aparte de las notas de la valoración: aquellas son de la medición,
            // esta es del plan de hoy. Las dos llegan; ninguna pisa a la otra.
            $contexto['trainer_notes']['plan_note'] = $v;
        }

        // Propuesta de recuperación (docs/iron-ia/RECUPERACION-RUTINAS.md): la
        // pone SOLO el backend, nunca la petición HTTP (GenerateIntegralPlanRequest
        // deja pasar únicamente las 7 claves del formulario). Los días que lleve
        // son una propuesta sin confirmar, no lo que dijo el entrenador.
        $campana = is_string($input[self::RECOVERY_KEY] ?? null) ? trim($input[self::RECOVERY_KEY]) : '';
        if ($campana !== '') {
            $contexto['sources']['recovery'] = $campana;
            // Regeneración pedida por el entrenador con lo que confirmó con el
            // socio: esos días ya NO son una propuesta, son su disponibilidad.
            if (($input[self::RECOVERY_CONFIRMED_KEY] ?? false) === true) {
                $contexto['sources']['recovery_confirmed'] = true;
            }
        }

        $contexto['sources']['trainer_input'] = array_values(array_diff(array_keys($input), [self::RECOVERY_KEY, self::RECOVERY_CONFIRMED_KEY]));

        return $contexto;
    }

    /** Clave interna con la que el backend marca una propuesta de recuperación. */
    public const RECOVERY_KEY = 'recovery_campaign';

    /** Clave interna: la regeneración lleva lo que el entrenador confirmó con el socio. */
    public const RECOVERY_CONFIRMED_KEY = 'recovery_confirmed';

    /**
     * ¿Los días del plan los pidió quien genera (el entrenador, o una propuesta
     * de recuperación) en vez de salir de la meta o de un supuesto? Entonces se
     * cumplen exactos y la rutina sencilla recibe el reparto muscular.
     *
     * @param  array<string,mixed>  $brief  {@see planBrief()}
     */
    public static function daysFromRequest(array $brief): bool
    {
        return in_array($brief['days_source'] ?? null, ['trainer', 'recovery_proposal'], true);
    }

    /**
     * Contexto COMPARTIDO del plan por niveles (docs/iron-ia/POTENCIAS-IRON-IA.md §4).
     *
     * Lo usan la nutrición y la rutina, así que hay UN objetivo interpretado y
     * no dos que se contradigan. Se arma en PHP, siempre igual para los mismos
     * datos, a partir de {@see for()} y de lo que `for()` no lee: la meta
     * nutricional activa, el perfil de Iron IA y `is_minor`.
     *
     * NO INVENTA. Cada cosa que no consta y hace falta para planificar se
     * resuelve con un SUPUESTO que queda escrito en `assumptions` y llega al
     * entrenador en la guía: «no consta la disponibilidad; se asumen 3 días».
     * Una meta de calorías solo existe si hay meta guardada o si la calculadora
     * tiene peso, estatura y edad medidos; si no, no hay cifra, hay pautas.
     *
     * No se persiste (igual que `for()`), y no lleva nombre, teléfono ni
     * documento: nada de esto sale hacia el modelo con datos que no cambian el
     * plan.
     *
     * @param  array<string,mixed>  $contexto  lo que devolvió {@see for()}
     * @return array<string,mixed>
     */
    public function planBrief(Member $member, array $contexto): array
    {
        $meta = NutritionGoal::query()
            ->where('member_id', $member->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();
        $perfil = IronAiUserProfile::query()->where('member_id', $member->getKey())->first();

        return $this->buildBrief($contexto, $meta, $perfil);
    }

    /**
     * La parte PURA de {@see planBrief()}: no lee la base. Existe para que
     * `iron-ia:evaluate` arme el mismo contexto con perfiles ficticios (modelos
     * sin guardar) sin tocar ninguna tabla de socios.
     *
     * @param  array<string,mixed>  $contexto  con la forma de {@see for()}
     * @return array<string,mixed>
     */
    public function buildBrief(array $contexto, ?NutritionGoal $meta, ?IronAiUserProfile $perfil): array
    {
        $supuestos = [];

        $edad = $contexto['member']['age_years'] ?? null;
        $menor = (bool) ($contexto['member']['is_minor'] ?? false) || ($edad !== null && $edad < 18);

        // Nivel: el perfil del socio y, si no dice nada, el de Iron IA o el de
        // la meta. Sin ninguno, principiante: es el supuesto que no expone.
        $nivel = $this->trainingLevel($contexto['training']['training_level'] ?? null)
            ?? $this->trainingLevel($perfil?->training_level)
            ?? $this->trainingLevel($meta?->experience_level);
        if ($nivel === null) {
            $nivel = 'principiante';
            $supuestos[] = 'No consta el nivel de entrenamiento: se planifica como principiante.';
        }

        // Días: lo que el entrenador indica para ESTE plan manda; si no lo
        // indica, la meta nutricional del socio; si tampoco, un supuesto
        // DECLARADO. El tope de 6 no es un valor fijo inventado: la división
        // de la rutina (RoutineMuscleOrganizer::SPLITS) llega hasta 6 sesiones.
        $diasEntrenador = $contexto['training']['days_per_week'] ?? null;
        $diasMeta = $meta?->training_days_per_week;
        $recuperacion = $contexto['sources']['recovery'] ?? null;
        $confirmada = ($contexto['sources']['recovery_confirmed'] ?? false) === true;
        if (is_int($diasEntrenador) && $diasEntrenador >= 1 && is_string($recuperacion) && ! $confirmada) {
            // Propuesta de recuperación: los días NO están confirmados. No se
            // registran como disponibilidad del socio en ningún sitio; solo
            // orientan esta propuesta, que el entrenador revisa antes de publicar.
            $dias = min(self::MAX_PLAN_DAYS, $diasEntrenador);
            $fuenteDias = 'recovery_proposal';
            $supuestos[] = "Propuesta de recuperación: se proponen {$dias} días SIN CONFIRMAR. Antes de publicar, confirma con el socio su disponibilidad real, los minutos por sesión, su nivel, lesiones y objetivo.";
        } elseif (is_int($diasEntrenador) && $diasEntrenador >= 1) {
            $dias = min(self::MAX_PLAN_DAYS, $diasEntrenador);
            $fuenteDias = 'trainer';
            if ($diasEntrenador > self::MAX_PLAN_DAYS) {
                $supuestos[] = "El entrenador indicó {$diasEntrenador} días; el plan admite hasta ".self::MAX_PLAN_DAYS.' sesiones por semana: la rutina usa '.self::MAX_PLAN_DAYS.' y deja un día de descanso.';
            }
        } elseif (is_int($diasMeta) && $diasMeta >= 1) {
            $dias = min(6, $diasMeta);
            $fuenteDias = 'nutrition_goal';
            if ($diasMeta > 6) {
                $supuestos[] = "La meta registra {$diasMeta} días de entrenamiento; la rutina usa 6 y deja un día de descanso.";
            }
        } else {
            $dias = match ($nivel) {
                'principiante' => 3,
                default => 4,
            };
            $fuenteDias = 'assumed';
            $supuestos[] = "No consta la disponibilidad semanal: se asumen {$dias} días de entrenamiento. Ajustar con el socio.";
        }

        $objetivoTexto = $contexto['goal']['objective'] ?? $perfil?->primary_goal ?? null;
        $objetivo = $this->objectiveKey($objetivoTexto, $meta?->objective);

        $restriccionesTexto = array_values(array_filter([
            $contexto['nutrition_constraints']['restrictions'] ?? null,
            $perfil?->nutrition_style,
            $perfil?->preferences_summary,
        ], fn ($t) => is_string($t) && trim($t) !== ''));
        $restricciones = $this->lexicon->restrictionsIn($restriccionesTexto);

        $senales = $this->riskSignals($contexto, [$perfil?->injuries_summary, $perfil?->preferences_summary]);
        $seguro = $senales !== [];

        $metas = $seguro ? null : $this->targets($meta, $contexto, $objetivo, $nivel, $dias, $supuestos);
        if ($seguro) {
            $supuestos[] = 'Hay señales que piden valoración profesional ('
                .implode(', ', array_column($senales, 'label'))
                .'): no se fijan metas de calorías ni se prescribe; es un plan general que debe revisar un profesional de la salud.';
        }

        $minutos = $contexto['training']['session_minutes'] ?? null;

        return [
            'objective' => $objetivoTexto,
            'objective_key' => $objetivo,
            'training_level' => $nivel,
            'days_available' => $dias,
            'days_source' => $fuenteDias,
            'days_requested' => is_int($diasEntrenador) ? $diasEntrenador : null,
            // Minutos por sesión: solo si el entrenador los indicó. No hay
            // supuesto: sin dato, la rutina no se recorta por tiempo.
            'session_minutes' => is_int($minutos) ? $minutos : null,
            'targets' => $metas,
            'restrictions' => $restricciones,
            'restrictions_text' => $restriccionesTexto,
            // No existe un campo de alergias: que no conste no significa que no
            // haya. La guía lo dice así y el entrenador lo confirma.
            'allergies' => 'no_registradas',
            'preferences' => array_filter([
                'nutrition_style' => $this->text($perfil?->nutrition_style),
                'summary' => $this->text($perfil?->preferences_summary),
                'secondary_goal' => $this->text($perfil?->secondary_goal),
            ], fn ($v) => $v !== null),
            'injuries' => $contexto['training']['injuries'] ?? $this->text($perfil?->injuries_summary),
            'is_minor' => $menor,
            'age_years' => $edad,
            'gender' => $contexto['member']['gender'] ?? null,
            'clinical_signals' => $senales,
            'safety_mode' => $seguro,
            'assumptions' => $supuestos,
        ];
    }

    /**
     * Señales que piden revisión profesional: menor de edad (marca o edad) y
     * lo clínico que aparezca en las notas de la valoración, las lesiones, las
     * restricciones o la descripción del objetivo (más `$extra`, p. ej. el
     * perfil de Iron IA). Sin señales, lista vacía.
     *
     * La usan el plan por niveles (modo seguro) y el nivel 1, que con alguna
     * señal retira los suplementos y añade el aviso sin cambiar su prompt.
     *
     * @param  array<string,mixed>  $contexto  con la forma de {@see for()}
     * @param  list<string|null>  $extra
     * @return list<array{key: string, label: string}>
     */
    public function riskSignals(array $contexto, array $extra = []): array
    {
        $senales = $this->lexicon->clinicalSignalsIn(array_merge([
            $contexto['trainer_notes']['observations'] ?? null,
            $contexto['trainer_notes']['recommendations'] ?? null,
            $contexto['trainer_notes']['plan_note'] ?? null,
            $contexto['training']['injuries'] ?? null,
            $contexto['nutrition_constraints']['restrictions'] ?? null,
            $contexto['goal']['objective_description'] ?? null,
        ], $extra));

        $edad = $contexto['member']['age_years'] ?? null;
        if ((bool) ($contexto['member']['is_minor'] ?? false) || ($edad !== null && $edad < 18)) {
            array_unshift($senales, ['key' => 'minor', 'label' => 'Menor de edad']);
        }

        return $senales;
    }

    /**
     * Metas diarias. Primero la meta GUARDADA (es la que el socio ya ve en su
     * app); si no hay, la calculadora existente con peso, estatura y edad
     * MEDIDOS y la actividad que se deduce de los días disponibles. Sin esos
     * tres datos, null: no hay cifra que dar.
     *
     * @param  array<string,mixed>  $contexto
     * @param  list<string>  $supuestos
     * @return array{kcal: int, protein_g: int, carbs_g: int, fat_g: int, source: string}|null
     */
    private function targets(?NutritionGoal $meta, array $contexto, string $objetivo, string $nivel, int $dias, array &$supuestos): ?array
    {
        if ($meta && $meta->daily_calories > 0 && $meta->protein_g > 0) {
            if (($meta->status ?? null) === 'needs_recalculation') {
                $supuestos[] = 'La meta nutricional guardada está marcada para recalcular: se usa tal cual hasta que se actualice.';
            }

            return [
                'kcal' => (int) $meta->daily_calories,
                'protein_g' => (int) $meta->protein_g,
                'carbs_g' => (int) $meta->carbs_g,
                'fat_g' => (int) $meta->fat_g,
                'source' => 'nutrition_goal',
            ];
        }

        $comp = $contexto['body_composition'] ?? [];
        $peso = $comp['weight_kg'] ?? null;
        $talla = $comp['height_cm'] ?? null;
        $edad = $comp['age_years'] ?? null;
        if ($peso === null || $talla === null || $edad === null) {
            $faltan = array_keys(array_filter(['peso' => $peso, 'estatura' => $talla, 'edad' => $edad], fn ($v) => $v === null));
            $supuestos[] = 'No hay meta nutricional guardada y faltan datos medidos ('.implode(', ', $faltan)
                .'): no se fija una cifra de calorías; las cantidades son porciones orientativas.';

            return null;
        }

        try {
            // La misma tabla días → actividad que usa el módulo de nutrición.
            $actividad = (string) (((array) config('nutrition.goal_calculator.training_days_to_activity'))[$dias] ?? 'light');
            $r = $this->calculator->calculate([
                'weight_kg' => (float) $peso,
                'height_cm' => (float) $talla,
                'age' => (int) $edad,
                'metabolic_sex' => $this->metabolicSex($contexto['member']['gender'] ?? null),
                'objective' => $objetivo,
                'experience_level' => match ($nivel) {
                    'avanzado' => 'advanced', 'intermedio' => 'intermediate', default => 'beginner',
                },
                'activity_level' => $actividad,
                // Ritmo conservador: la meta es un supuesto, no una pauta firmada.
                'pace' => 'conservative',
            ]);
        } catch (\Throwable) {
            // Sin cifra fiable no se da cifra: las pautas siguen sirviendo.
            $supuestos[] = 'No se pudo estimar una meta de calorías con los datos registrados: se dan porciones orientativas.';

            return null;
        }

        $supuestos[] = sprintf(
            'No hay meta nutricional guardada: se estima con la calculadora del gimnasio (%s kcal, actividad %s, ritmo conservador) a partir del peso, la estatura y la edad registrados.',
            number_format((int) $r['daily_calories'], 0, ',', '.'),
            $actividad,
        );

        return [
            'kcal' => (int) $r['daily_calories'],
            'protein_g' => (int) $r['protein_g'],
            'carbs_g' => (int) $r['carbs_g'],
            'fat_g' => (int) $r['fat_g'],
            'source' => 'calculator',
        ];
    }

    private function trainingLevel(?string $v): ?string
    {
        $t = $this->lexicon->normalize((string) $v);

        return match (true) {
            $t === '' => null,
            str_contains($t, 'princip') || str_contains($t, 'novat') || str_contains($t, 'inicia') || str_contains($t, 'begin') => 'principiante',
            str_contains($t, 'interm') => 'intermedio',
            str_contains($t, 'avanz') || str_contains($t, 'advanc') || str_contains($t, 'experto') => 'avanzado',
            default => null,
        };
    }

    /**
     * El objetivo en el vocabulario de la calculadora.
     *
     * Revisor 5: «ganar peso» o «ganar masa sin ganar grasa» se leían como
     * pérdida porque «peso» y «grasa» se miraban antes que el verbo. Ahora
     * manda el VERBO: ganar/aumentar/subir frente a bajar/perder/reducir…; si
     * hay de los dos («bajar grasa y ganar músculo»), el primero que aparece.
     * Sin verbo, el sustantivo: masa/músculo/hipertrofia → ganancia; grasa o
     * peso → pérdida.
     */
    private function objectiveKey(?string $texto, ?string $deLaMeta): string
    {
        $t = $this->lexicon->normalize((string) $texto);
        if ($t === '') {
            return $this->calculator->normalizeObjective((string) $deLaMeta);
        }

        $gana = preg_match('/(?<![a-z])(ganar|gana|aumentar|aumenta|subir|sube|incrementar)(?![a-z])/', $t, $g, PREG_OFFSET_CAPTURE) ? $g[0][1] : null;
        $pierde = preg_match('/(?<![a-z])(bajar|baja|perder|pierde|reducir|reduce|adelgaz[a-z]*|quemar|definir|definicion)(?![a-z])/', $t, $p, PREG_OFFSET_CAPTURE) ? $p[0][1] : null;

        $clave = match (true) {
            (bool) preg_match('/(?<![a-z])(fuerza|potencia)(?![a-z])/', $t) && $pierde === null && ! preg_match('/masa|musculo|peso/', $t) => 'strength',
            $gana !== null && ($pierde === null || $gana < $pierde) => 'muscle_gain',
            $pierde !== null => 'fat_loss',
            (bool) preg_match('/hipertrof|masa|musculo|volumen/', $t) => 'muscle_gain',
            (bool) preg_match('/grasa|peso/', $t) => 'fat_loss',
            (bool) preg_match('/resisten|cardio|maraton|correr|ciclis/', $t) => 'endurance',
            default => null,
        };

        return $clave ?? $this->calculator->normalizeObjective((string) $deLaMeta);
    }

    // ── Bloques ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function memberBlock(Member $member): array
    {
        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'age_years' => $this->ageFrom($member->birth_date),
            'gender' => $member->gender ?: null,
            'is_minor' => (bool) $member->is_minor,
        ];
    }

    /**
     * Composición corporal. Los cuatro campos compartidos salen de la ÚLTIMA
     * VALORACIÓN, que es donde se miden; la guía nutricional los copiaba, y por
     * eso no es fuente. Los tres propios de la guía —grasa visceral,
     * metabolismo y edad— salen de la última guía si los trae.
     *
     * @return array<string,mixed>
     */
    private function bodyComposition(
        Member $member,
        ?ProfessionalAssessment $assessment,
        ?NutritionGuide $guide,
    ): array {
        $out = [
            'weight_kg' => $this->decimal($assessment?->weight_kg),
            'height_cm' => $this->decimal($assessment?->height_cm),
            'body_fat_pct' => $this->decimal($assessment?->body_fat_pct),
            'muscle_mass_pct' => $this->decimal($assessment?->muscle_mass_pct),
            'visceral_fat' => $this->decimal($guide?->visceral_fat),
            'age_years' => $this->ageFrom($member->birth_date) ?? $guide?->age_years,
        ];

        $out['basal_kcal'] = $this->basalKcal($member, $out) ?? $guide?->basal_kcal;

        return $out;
    }

    /**
     * Metabolismo basal, con la fórmula que YA usa el módulo de nutrición
     * (Mifflin-St Jeor, en NutritionGoalCalculatorService). No se escribe otra:
     * dos fórmulas darían dos cifras distintas para la misma persona y nadie
     * sabría cuál mirar.
     *
     * Solo se calcula con los tres datos que necesita. Sin ellos, null.
     *
     * @param  array<string,mixed>  $composition
     */
    private function basalKcal(Member $member, array $composition): ?int
    {
        $peso = $composition['weight_kg'];
        $talla = $composition['height_cm'];
        $edad = $composition['age_years'];

        if ($peso === null || $talla === null || $edad === null) {
            return null;
        }

        try {
            $r = $this->calculator->calculate([
                'weight_kg' => $peso,
                'height_cm' => $talla,
                'age' => $edad,
                'metabolic_sex' => $this->metabolicSex($member->gender),
                'objective' => 'general_wellness',
            ]);

            return isset($r['bmr']) ? (int) $r['bmr'] : null;
        } catch (\Throwable) {
            // Una sugerencia que falla no puede tumbar la pantalla: el
            // entrenador escribe el valor y sigue trabajando.
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function measurements(?ProfessionalAssessment $assessment): array
    {
        return [
            'waist_cm' => $this->decimal($assessment?->waist_cm),
            'hip_cm' => $this->decimal($assessment?->hip_cm),
            'chest_cm' => $this->decimal($assessment?->chest_cm),
            'arm_cm' => $this->decimal($assessment?->arm_cm),
            'leg_cm' => $this->decimal($assessment?->leg_cm),
        ];
    }

    /**
     * Objetivo. Manda el PERFIL, que es lo que el socio declaró de sí mismo; la
     * última guía sirve de respaldo cuando el perfil no dice nada.
     *
     * @return array<string,mixed>
     */
    private function goal(Member $member, ?NutritionGuide $guide): array
    {
        return [
            'objective' => $this->text($member->goal) ?? $this->text($guide?->objective),
            'objective_description' => $this->text($guide?->objective_description),
            'training_stage' => $this->text($guide?->training_stage),
        ];
    }

    /** @return array<string,mixed> */
    private function training(Member $member): array
    {
        return [
            'training_level' => $this->text($member->training_level),
            'injuries' => $this->text($member->injuries),
        ];
    }

    /**
     * Lo que condiciona la alimentación. Sale de la última guía publicada
     * porque es donde se acordó; el perfil solo aporta las lesiones.
     *
     * @return array<string,mixed>
     */
    private function nutritionConstraints(Member $member, ?NutritionGuide $guide): array
    {
        return [
            'restrictions' => $this->text($guide?->restrictions),
            'supplements' => $this->text($guide?->supplements),
            'injuries' => $this->text($member->injuries),
        ];
    }

    /** @return array<string,mixed> */
    private function trainerNotes(?ProfessionalAssessment $assessment): array
    {
        return [
            'observations' => $this->text($assessment?->observations),
            'recommendations' => $this->text($assessment?->recommendations),
        ];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function lastPublishedGuideFor(Member $member): ?NutritionGuide
    {
        return NutritionGuide::query()
            ->forMember((int) $member->getKey())
            ->visibleToMember()
            ->orderByDesc('published_at')
            ->orderByDesc('version')
            ->first();
    }

    /** Edad cumplida hoy. Una fecha futura o ilegible no produce una edad. */
    private function ageFrom(mixed $birthDate): ?int
    {
        if (empty($birthDate)) {
            return null;
        }

        try {
            $nacimiento = $birthDate instanceof Carbon ? $birthDate : Carbon::parse((string) $birthDate);
        } catch (\Throwable) {
            return null;
        }

        if ($nacimiento->isFuture()) {
            return null;
        }

        $edad = $nacimiento->age;

        return ($edad >= 0 && $edad <= 120) ? $edad : null;
    }

    /** `gender` del perfil → vocabulario del calculador. */
    private function metabolicSex(?string $gender): string
    {
        return match (mb_strtolower(trim((string) $gender))) {
            'male', 'm', 'masculino', 'hombre' => 'male',
            'female', 'f', 'femenino', 'mujer' => 'female',
            default => 'unspecified',
        };
    }

    private function decimal(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private function text(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
