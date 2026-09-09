<?php

namespace App\Services\Trainer;

use App\Models\Member;
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
    public function __construct(
        private readonly NutritionGuideService $guides,
        private readonly NutritionGoalCalculatorService $calculator,
    ) {}

    /**
     * El contexto completo, agrupado como lo pide la pantalla y como lo pedirá
     * el generador de planes.
     *
     * @return array<string,mixed>
     */
    public function for(Member $member): array
    {
        $assessment = $this->guides->lastAssessmentFor($member);
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
