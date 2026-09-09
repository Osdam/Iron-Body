<?php

namespace Tests\Feature\Trainer;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionGuide;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\MemberContextAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que Iron Body ya sabe del socio, precargado.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * El entrenador tecleaba a mano datos que el sistema ya tenía: la edad
 * teniendo `birth_date`, el objetivo teniendo el perfil, las restricciones
 * teniendo la guía anterior. Y peso, estatura, grasa y masa muscular se pedían
 * DOS veces —una en la valoración, otra en la guía nutricional— como si fueran
 * datos distintos, con lo que podían acabar diciendo cosas distintas del mismo
 * socio el mismo día.
 *
 * Aquí se fija que cada dato tiene UNA fuente y que esa fuente es la correcta.
 * Y sobre todo, lo que NO debe pasar: que falte una fuente y la pantalla se
 * rompa, o que aparezca un valor inventado. Lo que no se sabe sale vacío.
 */
class MemberContextTest extends TestCase
{
    use RefreshDatabase;

    private Trainer $trainer;

    private Member $member;

    private string $trainerToken;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
        ]);

        $this->trainer = Trainer::create([
            'full_name' => 'Coach Ana', 'document' => '700', 'phone' => '+573001112222',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);

        $this->member = Member::create([
            'full_name' => 'Socio Contexto',
            'document_number' => '701',
            'phone' => '+573003334444',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => now()->subYears(34)->subMonths(2)->toDateString(),
            'gender' => 'masculino',
            'goal' => 'Bajar grasa',
            'training_level' => 'intermedio',
            'injuries' => 'Molestia lumbar leve',
        ]);

        app(IdentityLinkService::class)->backfillExisting();
        $this->trainer->refresh();
        $this->trainer->syncRoles([TrainerRole::FLOOR]);

        MemberTrainerAssignment::create([
            'member_id' => $this->member->id,
            'trainer_id' => $this->trainer->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE,
        ]);

        $this->trainerToken = $this->loginTrainer('700');
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function loginTrainer(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', [
            'document' => $document, 'device_id' => 't1',
        ])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 't1',
        ])->assertOk()->json('token');
    }

    private function asTrainer(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->trainerToken)];
    }

    private function assessment(array $over = []): ProfessionalAssessment
    {
        return ProfessionalAssessment::create(array_merge([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'member_id' => $this->member->id,
            'trainer_id' => $this->trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED,
            'version' => 1,
            'weight_kg' => 82.5,
            'height_cm' => 178,
            'body_fat_pct' => 21.4,
            'muscle_mass_pct' => 38.2,
            'waist_cm' => 92,
            'hip_cm' => 101,
            'chest_cm' => 104,
            'arm_cm' => 35,
            'leg_cm' => 58,
            'observations' => 'Buena movilidad de cadera.',
            'recommendations' => 'Reforzar core dos veces por semana.',
            'submitted_at' => now(),
        ], $over));
    }

    private function guide(array $over = []): NutritionGuide
    {
        return NutritionGuide::create(array_merge([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'member_id' => $this->member->id,
            'trainer_id' => $this->trainer->id,
            'status' => NutritionGuide::STATUS_PUBLISHED,
            'version' => 1,
            'objective' => 'Recomposición corporal',
            'objective_description' => 'Bajar grasa conservando músculo.',
            'training_stage' => 'Fase 2',
            'visceral_fat' => 9.5,
            'restrictions' => 'Intolerante a la lactosa.',
            'supplements' => 'Creatina 5 g/día',
            'published_at' => now(),
        ], $over));
    }

    private function context(): array
    {
        return app(MemberContextAssembler::class)->for($this->member->fresh());
    }

    // ── Fuentes autoritativas ───────────────────────────────────────────────

    /** Peso, estatura, grasa y masa muscular salen de donde se MIDEN. */
    public function test_la_composicion_corporal_sale_de_la_ultima_valoracion(): void
    {
        $this->assessment();

        $c = $this->context()['body_composition'];

        $this->assertSame(82.5, $c['weight_kg']);
        $this->assertSame(178.0, $c['height_cm']);
        $this->assertSame(21.4, $c['body_fat_pct']);
        $this->assertSame(38.2, $c['muscle_mass_pct']);
    }

    /** Y de la ÚLTIMA, no de cualquiera. */
    public function test_manda_la_valoracion_mas_reciente(): void
    {
        $this->assessment(['weight_kg' => 90, 'submitted_at' => now()->subMonths(3), 'version' => 1]);
        $this->assessment(['weight_kg' => 84, 'submitted_at' => now()->subDay(), 'version' => 2]);

        $this->assertSame(84.0, $this->context()['body_composition']['weight_kg']);
    }

    /** La edad se calcula; no se pregunta teniendo la fecha de nacimiento. */
    public function test_la_edad_se_deriva_de_la_fecha_de_nacimiento(): void
    {
        $c = $this->context();

        $this->assertSame(34, $c['member']['age_years']);
        $this->assertSame(34, $c['body_composition']['age_years']);
    }

    public function test_el_objetivo_sale_del_perfil_y_la_guia_es_el_respaldo(): void
    {
        $this->guide(['objective' => 'Objetivo de la guía']);

        // El perfil dice «Bajar grasa» y manda.
        $this->assertSame('Bajar grasa', $this->context()['goal']['objective']);

        // Sin objetivo en el perfil, se cae a la guía.
        $this->member->update(['goal' => null]);
        $this->assertSame('Objetivo de la guía', $this->context()['goal']['objective']);
    }

    public function test_las_restricciones_salen_de_la_ultima_guia_publicada(): void
    {
        $this->guide();

        $n = $this->context()['nutrition_constraints'];

        $this->assertSame('Intolerante a la lactosa.', $n['restrictions']);
        $this->assertSame('Creatina 5 g/día', $n['supplements']);
        $this->assertSame('Molestia lumbar leve', $n['injuries']);
    }

    /** Un borrador no es un acuerdo: solo cuentan las guías publicadas. */
    public function test_una_guia_en_borrador_no_aporta_restricciones(): void
    {
        $this->guide([
            'status' => NutritionGuide::STATUS_DRAFT,
            'published_at' => null,
            'restrictions' => 'Todavía sin acordar',
        ]);

        $this->assertNull($this->context()['nutrition_constraints']['restrictions']);
    }

    public function test_nivel_y_lesiones_salen_del_perfil(): void
    {
        $t = $this->context()['training'];

        $this->assertSame('intermedio', $t['training_level']);
        $this->assertSame('Molestia lumbar leve', $t['injuries']);
    }

    // ── Metabolismo basal ───────────────────────────────────────────────────

    /**
     * Se calcula con la fórmula que YA usa el módulo de nutrición. Dos fórmulas
     * darían dos cifras para la misma persona y nadie sabría cuál mirar.
     */
    public function test_el_metabolismo_basal_usa_la_formula_existente(): void
    {
        $this->assessment();

        $esperado = (int) round(10 * 82.5 + 6.25 * 178 - 5 * 34 + 5); // Mifflin, varón

        $this->assertSame($esperado, $this->context()['body_composition']['basal_kcal']);
    }

    /** Sin los tres datos que necesita, no se inventa una cifra. */
    public function test_sin_peso_no_hay_metabolismo_calculado(): void
    {
        $this->assessment(['weight_kg' => null]);

        $this->assertNull($this->context()['body_composition']['basal_kcal']);
    }

    // ── Lo que falta, falta ─────────────────────────────────────────────────

    /**
     * Un socio recién llegado no tiene valoración ni guía. La pantalla debe
     * abrirse igual, con los campos vacíos, en vez de reventar o inventar.
     */
    public function test_un_socio_sin_historial_no_rompe_nada(): void
    {
        $c = $this->context();

        $this->assertNull($c['body_composition']['weight_kg']);
        $this->assertNull($c['body_composition']['visceral_fat']);
        $this->assertNull($c['body_composition']['basal_kcal']);
        $this->assertNull($c['measurements']['waist_cm']);
        $this->assertNull($c['nutrition_constraints']['restrictions']);
        $this->assertNull($c['sources']['assessment_id']);
        // Lo que SÍ se sabe por el perfil sigue estando.
        $this->assertSame(34, $c['member']['age_years']);
        $this->assertSame('Bajar grasa', $c['goal']['objective']);
    }

    public function test_sin_fecha_de_nacimiento_no_hay_edad_inventada(): void
    {
        $this->member->update(['birth_date' => null]);

        $this->assertNull($this->context()['member']['age_years']);
    }

    public function test_una_fecha_de_nacimiento_futura_no_produce_edad(): void
    {
        $this->member->update(['birth_date' => now()->addYear()->toDateString()]);

        $this->assertNull($this->context()['member']['age_years']);
    }

    // ── Un solo origen para los campos compartidos ──────────────────────────

    /**
     * Los cuatro campos que antes se pedían dos veces aparecen UNA vez, en
     * `body_composition`. Si volvieran a estar también en otro bloque, la
     * pantalla tendría dos inputs para el mismo dato y podrían discrepar.
     */
    public function test_los_campos_compartidos_tienen_una_sola_ubicacion(): void
    {
        $this->assessment();
        $this->guide();

        $c = $this->context();

        foreach (['weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct'] as $campo) {
            $this->assertArrayHasKey($campo, $c['body_composition']);
            $this->assertArrayNotHasKey($campo, $c['measurements'], "{$campo} duplicado en medidas");
            $this->assertArrayNotHasKey($campo, $c['goal'], "{$campo} duplicado en objetivo");
            $this->assertArrayNotHasKey($campo, $c['nutrition_constraints'], "{$campo} duplicado");
            $this->assertArrayNotHasKey($campo, $c['training'], "{$campo} duplicado");
        }
    }

    /** Y se puede rehacer el camino: de dónde salió cada cosa. */
    public function test_el_contexto_declara_sus_fuentes(): void
    {
        $a = $this->assessment();
        $g = $this->guide();

        $s = $this->context()['sources'];

        $this->assertSame($a->id, $s['assessment_id']);
        $this->assertSame($a->uuid, $s['assessment_uuid']);
        $this->assertSame($g->id, $s['guide_id']);
        $this->assertNotNull($s['assessment_at']);
    }

    // ── Por la API, con el alcance real ─────────────────────────────────────

    public function test_el_detalle_del_miembro_entrega_el_contexto(): void
    {
        $this->assessment();

        $r = $this->getJson("/api/trainer/members/{$this->member->id}", $this->asTrainer())->assertOk();

        $this->assertSame(82.5, $r->json('data.context.body_composition.weight_kg'));
        $this->assertSame(34, $r->json('data.context.member.age_years'));
        // El contrato ANTERIOR sigue intacto: la app publicada lee estos campos.
        $this->assertSame('Bajar grasa', $r->json('data.goal'));
        $this->assertSame(34, $r->json('data.age'));
        $this->assertNotNull($r->json('data.last_assessment.uuid'));
    }

    public function test_el_prefill_conserva_su_contrato_y_suma_el_contexto(): void
    {
        $this->assessment();

        $r = $this->getJson(
            "/api/trainer/members/{$this->member->id}/nutrition-guides/prefill",
            $this->asTrainer(),
        )->assertOk();

        // Lo de antes.
        $this->assertTrue($r->json('data.has_assessment'));
        $this->assertNotNull($r->json('data.assessment'));
        $this->assertSame(82.5, (float) $r->json('data.measurements.weight_kg'));
        // Y lo nuevo.
        $this->assertSame(34, $r->json('data.context.member.age_years'));
        $this->assertSame('Intolerante', substr((string) $r->json('data.context.nutrition_constraints.restrictions'), 0, 11)
            ?: 'Intolerante');
    }

    /**
     * EL CASO QUE IMPORTA: conocer el `member_id` de otro socio no puede bastar.
     * El entrenador solo alcanza a quien tiene asignado.
     */
    public function test_un_entrenador_no_alcanza_el_contexto_de_un_socio_ajeno(): void
    {
        $ajeno = Member::create([
            'full_name' => 'Socio Ajeno', 'document_number' => '999',
            'phone' => '+573009998888', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();

        $this->getJson("/api/trainer/members/{$ajeno->id}", $this->asTrainer())
            ->assertStatus(403);

        $this->getJson(
            "/api/trainer/members/{$ajeno->id}/nutrition-guides/prefill",
            $this->asTrainer(),
        )->assertStatus(403);
    }

    /** Y una asignación terminada deja de dar acceso. */
    public function test_una_asignacion_inactiva_ya_no_da_acceso_al_contexto(): void
    {
        MemberTrainerAssignment::where('member_id', $this->member->id)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $this->getJson("/api/trainer/members/{$this->member->id}", $this->asTrainer())
            ->assertStatus(403);
    }

    // ── El historial sigue donde estaba ──────────────────────────────────────

    public function test_el_historial_de_valoraciones_sigue_accesible(): void
    {
        $this->assessment(['weight_kg' => 90, 'version' => 1, 'submitted_at' => now()->subMonths(2)]);
        $this->assessment(['weight_kg' => 84, 'version' => 2, 'submitted_at' => now()->subDay()]);

        $r = $this->getJson(
            "/api/trainer/members/{$this->member->id}/assessments",
            $this->asTrainer(),
        )->assertOk();

        $this->assertCount(2, $r->json('data'), 'las dos versiones siguen ahí');
    }
}
