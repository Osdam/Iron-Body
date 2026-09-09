<?php

namespace Tests\Feature\Trainer;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\NutritionGuide;
use App\Models\ProfessionalAssessment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\DeviceSessionService;
use App\Services\Identity\IdentityLinkService;
use App\Services\Nutrition\Ai\NutritionAiClient;
use App\Services\Trainer\IntegralPlanGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Generación de plan integral con Iron IA: nutrición + rutina.
 *
 * LO QUE SE VIGILA
 * ----------------
 * Que la IA NO sea la autoridad. Lo que devuelve es texto de un generador de
 * texto: puede faltar una clave, venir un tipo equivocado o —lo más caro—
 * un ejercicio que no existe. Un ejercicio inventado llegaría al socio como un
 * nombre sin vídeo, sin instrucciones y sin nada detrás.
 *
 * Y que falle COMO UNIDAD: si la rutina no vale, tampoco se guarda la
 * nutrición. Medio plan es peor que ninguno, porque el entrenador publicaría
 * la guía creyendo que la rutina también está.
 *
 * Nada llega al socio sin que una persona lo publique.
 */
class IntegralPlanTest extends TestCase
{
    use RefreshDatabase;

    private Trainer $trainer;

    private Member $member;

    private string $trainerToken;

    private string $memberToken;

    /** @var list<int> */
    private array $exerciseIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-4.1-mini',
        ]);

        $this->trainer = Trainer::create([
            'full_name' => 'Coach Ana', 'document' => '800', 'phone' => '+573001110000',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);

        $this->member = Member::create([
            'full_name' => 'Socio Plan', 'document_number' => '801', 'phone' => '+573002220000',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => now()->subYears(30)->toDateString(),
            'gender' => 'masculino', 'goal' => 'Bajar grasa',
            'training_level' => 'intermedio', 'injuries' => 'Lumbar sensible',
        ]);

        app(IdentityLinkService::class)->backfillExisting();
        $this->trainer->refresh();
        $this->trainer->syncRoles([TrainerRole::FLOOR]);

        MemberTrainerAssignment::create([
            'member_id' => $this->member->id, 'trainer_id' => $this->trainer->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE,
        ]);

        // Catálogo real del gimnasio. Solo de aquí puede elegir el modelo.
        foreach (['Sentadilla', 'Press banca', 'Remo con barra', 'Peso muerto'] as $i => $n) {
            $this->exerciseIds[] = Exercise::create([
                'name' => $n, 'local_name' => $n,
                'muscle_group' => 'Piernas', 'body_part' => 'lower legs',
                'equipment' => 'barra', 'difficulty' => 'intermedio', 'target' => 'cuádriceps',
                'video_path' => "videos/{$i}.mp4",
                'provider' => 'local', 'external_id' => "ex-{$i}",
            ])->id;
        }

        $this->trainerToken = $this->loginTrainer('800');
        $this->memberToken = app(DeviceSessionService::class)
            ->issueSession($this->member, ['device_id' => 'm1'])['token'];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function loginTrainer(string $document): string
    {
        $a = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 't1'])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $a->json('challenge_id'),
            'code' => $a->json('dev_code'),
            'device_id' => 't1',
        ])->assertOk()->json('token');
    }

    private function asTrainer(?string $t = null): array
    {
        return ['Authorization' => 'Bearer '.($t ?? $this->trainerToken)];
    }

    private function asMember(): array
    {
        return ['Authorization' => "Bearer {$this->memberToken}"];
    }

    /** Sustituye al cliente de IA por uno que devuelve lo que le digamos. */
    private function fakeAi(array $result): void
    {
        $this->instance(NutritionAiClient::class, new class($result) extends NutritionAiClient
        {
            public function __construct(private array $r) {}

            public function complete(string $model, array $messages, bool $json = true, ?int $maxTokens = null): array
            {
                return $this->r;
            }
        });
    }

    /** Una respuesta bien formada del modelo. */
    private function goodPlan(array $over = []): array
    {
        return array_merge([
            'status' => 'ok',
            'model' => 'gpt-4.1-mini',
            'json' => [
                'nutrition' => [
                    'objective' => 'Recomposición corporal',
                    'objective_description' => 'Bajar grasa conservando músculo.',
                    'training_stage' => 'Fase 2',
                    'meals' => [
                        ['label' => 'Desayuno', 'time' => '07:00', 'description' => 'Huevos y avena'],
                        ['label' => 'Almuerzo', 'time' => '13:00', 'description' => 'Proteína y arroz'],
                    ],
                    'recommendations' => 'Beber 2 litros al día.',
                    'restrictions' => 'Sin lactosa.',
                ],
                'routine' => [
                    'name' => 'Full body 3 días',
                    'objective' => 'Recomposición',
                    'level' => 'intermedio',
                    'days' => [
                        ['name' => 'Día 1', 'exercises' => [
                            ['exercise_id' => $this->exerciseIds[0], 'sets' => 4, 'reps' => '8-10', 'rest_seconds' => 90],
                            ['exercise_id' => $this->exerciseIds[1], 'sets' => 3, 'reps' => '10'],
                        ]],
                        ['name' => 'Día 2', 'exercises' => [
                            ['exercise_id' => $this->exerciseIds[2], 'sets' => 4, 'reps' => '8'],
                        ]],
                    ],
                ],
            ],
        ], $over);
    }

    private function assessment(): ProfessionalAssessment
    {
        return ProfessionalAssessment::create([
            'uuid' => (string) Str::uuid(),
            'member_id' => $this->member->id, 'trainer_id' => $this->trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED, 'version' => 1,
            'weight_kg' => 82, 'height_cm' => 178, 'body_fat_pct' => 20,
            'submitted_at' => now(),
        ]);
    }

    private function generate(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/trainer/members/{$this->member->id}/integral-plan/generate",
            [],
            $this->asTrainer(),
        );
    }

    // ── Camino feliz ────────────────────────────────────────────────────────

    public function test_genera_los_dos_borradores_enlazados_a_la_valoracion(): void
    {
        $a = $this->assessment();
        $this->fakeAi($this->goodPlan());

        $r = $this->generate()->assertStatus(201);

        $this->assertSame('draft', $r->json('data.status'));
        $this->assertSame($a->id, $r->json('data.source_assessment_id'));

        $guia = NutritionGuide::firstOrFail();
        $rutina = Routine::firstOrFail();

        $this->assertSame(NutritionGuide::STATUS_DRAFT, $guia->status);
        $this->assertSame($a->id, $guia->source_assessment_id);
        $this->assertSame($a->id, $rutina->source_assessment_id);
        $this->assertTrue((bool) $guia->generated_by_ai);
        $this->assertTrue((bool) $rutina->generated_by_ai);
    }

    public function test_los_ejercicios_se_persisten_con_su_id_real(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $ids = RoutineExercise::pluck('exercise_id')->all();

        $this->assertCount(3, $ids);
        foreach ($ids as $id) {
            $this->assertContains($id, $this->exerciseIds, 'ejercicio fuera del catálogo');
        }
    }

    /** EL BORRADOR NO SE VE. Nada llega al socio sin que alguien lo publique. */
    public function test_la_rutina_en_borrador_no_aparece_en_mis_rutinas(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $rutina = Routine::firstOrFail();
        $this->assertFalse((bool) $rutina->is_assigned);
        $this->assertSame(0, MemberRoutineAssignment::count());

        $r = $this->getJson('/api/app/routines/assigned', $this->asMember())->assertOk();
        $this->assertCount(0, $r->json('data'));
    }

    // ── La IA no es la autoridad ────────────────────────────────────────────

    public function test_una_respuesta_sin_json_no_deja_nada(): void
    {
        $this->assessment();
        $this->fakeAi(['status' => 'ok', 'json' => null]);

        $this->generate()->assertStatus(422)->assertJsonPath('code', 'ai_invalid_output');

        $this->assertSame(0, NutritionGuide::count());
        $this->assertSame(0, Routine::count());
    }

    public function test_un_plan_sin_rutina_no_guarda_la_nutricion(): void
    {
        $this->assessment();
        $plan = $this->goodPlan();
        unset($plan['json']['routine']);
        $this->fakeAi($plan);

        $this->generate()->assertStatus(422)->assertJsonPath('code', 'ai_invalid_output');

        // Medio plan es peor que ninguno.
        $this->assertSame(0, NutritionGuide::count(), 'la nutrición NO puede quedar suelta');
        $this->assertSame(0, Routine::count());
    }

    public function test_un_plan_sin_comidas_se_rechaza(): void
    {
        $this->assessment();
        $plan = $this->goodPlan();
        $plan['json']['nutrition']['meals'] = [];
        $this->fakeAi($plan);

        $this->generate()->assertStatus(422)->assertJsonPath('code', 'ai_invalid_output');
        $this->assertSame(0, NutritionGuide::count());
    }

    /** EL CANDADO: un ejercicio que no existe no entra, y sin días, no hay plan. */
    public function test_un_ejercicio_inventado_no_se_persiste(): void
    {
        $this->assessment();
        $plan = $this->goodPlan();
        $plan['json']['routine']['days'] = [
            ['name' => 'Día 1', 'exercises' => [
                ['exercise_id' => 999999, 'sets' => 4, 'reps' => '10'],
                ['exercise_id' => 888888, 'sets' => 3, 'reps' => '12'],
            ]],
        ];
        $this->fakeAi($plan);

        $this->generate()->assertStatus(422)->assertJsonPath('code', 'ai_invalid_output');

        $this->assertSame(0, RoutineExercise::count());
        $this->assertSame(0, Routine::count());
    }

    /** Y si mezcla válidos con inventados, solo sobreviven los válidos. */
    public function test_los_ejercicios_inventados_se_descartan_y_los_reales_quedan(): void
    {
        $this->assessment();
        $plan = $this->goodPlan();
        $plan['json']['routine']['days'] = [
            ['name' => 'Día 1', 'exercises' => [
                ['exercise_id' => $this->exerciseIds[0], 'sets' => 4, 'reps' => '10'],
                ['exercise_id' => 999999, 'sets' => 3, 'reps' => '12'],
                ['exercise_id' => $this->exerciseIds[1], 'sets' => 3, 'reps' => '12'],
            ]],
        ];
        $this->fakeAi($plan);

        $this->generate()->assertStatus(201);

        $ids = RoutineExercise::pluck('exercise_id')->all();
        $this->assertCount(2, $ids);
        $this->assertNotContains(999999, $ids);
    }

    /** Un nombre no basta: solo el id resuelve. */
    public function test_no_se_acepta_un_ejercicio_solo_por_nombre(): void
    {
        $this->assessment();
        $plan = $this->goodPlan();
        $plan['json']['routine']['days'] = [
            ['name' => 'Día 1', 'exercises' => [
                ['name' => 'Sentadilla', 'sets' => 4, 'reps' => '10'],
            ]],
        ];
        $this->fakeAi($plan);

        $this->generate()->assertStatus(422);
        $this->assertSame(0, Routine::count());
    }

    public function test_un_fallo_del_modelo_no_deja_plan_parcial(): void
    {
        $this->assessment();
        $this->fakeAi(['status' => 'error', 'error_code' => 'timeout', 'json' => null]);

        $r = $this->generate()->assertStatus(422);

        $this->assertSame('ai_upstream_error', $r->json('code'));
        $this->assertTrue($r->json('retryable'), 'un timeout se puede reintentar');
        $this->assertSame(0, NutritionGuide::count());
        $this->assertSame(0, Routine::count());
    }

    // ── Coste y registro ────────────────────────────────────────────────────

    public function test_cada_intento_queda_registrado(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $run = NutritionAiRun::firstOrFail();

        $this->assertSame(IntegralPlanGenerationService::RUN_MODE, $run->mode);
        $this->assertSame($this->member->id, $run->member_id);
        $this->assertSame('ok', $run->status);
        $this->assertSame('gpt-4.1-mini', $run->model);
        $this->assertNotEmpty($run->input_hash);
    }

    public function test_un_intento_fallido_tambien_queda_registrado(): void
    {
        $this->assessment();
        $this->fakeAi(['status' => 'error', 'error_code' => 'rate_limited', 'json' => null]);
        $this->generate()->assertStatus(422);

        $run = NutritionAiRun::firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('rate_limited', $run->error_code);
    }

    /** El guardián de coste es el que ya existe; no se monta otro. */
    public function test_el_limite_diario_corta_la_generacion(): void
    {
        config(['nutrition.ai.rate_limit_per_user' => 1]);
        $this->assessment();
        $this->fakeAi($this->goodPlan());

        $this->generate()->assertStatus(201);
        $r = $this->generate()->assertStatus(422);

        $this->assertSame('rate_limit_per_user', $r->json('code'));
        $this->assertSame(1, Routine::count(), 'la segunda no creó nada');
    }

    public function test_sin_iron_ia_configurado_no_se_intenta_generar(): void
    {
        config(['services.openai.enabled' => false]);
        $this->assessment();

        $this->generate()->assertStatus(422)->assertJsonPath('code', 'ai_disabled');
        $this->assertSame(0, NutritionAiRun::count(), 'ni siquiera se registra un intento');
    }

    // ── Publicación ─────────────────────────────────────────────────────────

    public function test_publicar_la_rutina_la_hace_visible_en_mis_rutinas(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $rutina = Routine::firstOrFail();

        $this->postJson("/api/trainer/routines/{$rutina->id}/publish", [], $this->asTrainer())
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $this->assertTrue((bool) $rutina->fresh()->is_assigned);
        $this->assertSame(1, MemberRoutineAssignment::count());

        // Y el socio la ve donde ya mira, sin categoría nueva.
        $r = $this->getJson('/api/app/routines/assigned', $this->asMember())->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('Full body 3 días', $r->json('data.0.name'));
    }

    public function test_publicar_dos_veces_no_duplica_la_asignacion(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);
        $rutina = Routine::firstOrFail();

        $this->postJson("/api/trainer/routines/{$rutina->id}/publish", [], $this->asTrainer())->assertOk();
        $this->postJson("/api/trainer/routines/{$rutina->id}/publish", [], $this->asTrainer())->assertOk();

        $this->assertSame(1, MemberRoutineAssignment::count());
    }

    /** La nutrición se publica con el ciclo que ya existía. */
    public function test_la_nutricion_se_publica_con_el_flujo_existente(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $guia = NutritionGuide::firstOrFail();

        $this->postJson("/api/trainer/nutrition-guides/{$guia->uuid}/publish", [], $this->asTrainer())
            ->assertOk();

        $this->assertSame(NutritionGuide::STATUS_PUBLISHED, $guia->fresh()->status);

        // Y el socio la ve por su endpoint de siempre.
        $this->getJson('/api/member/nutrition-guide', $this->asMember())->assertOk();
    }

    // ── Alcance ─────────────────────────────────────────────────────────────

    public function test_un_entrenador_no_genera_para_un_socio_ajeno(): void
    {
        $ajeno = Member::create([
            'full_name' => 'Socio Ajeno', 'document_number' => '999',
            'phone' => '+573009990000', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $this->fakeAi($this->goodPlan());

        $this->postJson(
            "/api/trainer/members/{$ajeno->id}/integral-plan/generate",
            [],
            $this->asTrainer(),
        )->assertStatus(403);

        $this->assertSame(0, Routine::count());
    }

    /** Si el socio cambió de entrenador entre generar y publicar, no se publica. */
    public function test_una_asignacion_inactiva_impide_publicar(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);
        $rutina = Routine::firstOrFail();

        MemberTrainerAssignment::where('member_id', $this->member->id)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $this->postJson("/api/trainer/routines/{$rutina->id}/publish", [], $this->asTrainer())
            ->assertStatus(422)
            ->assertJsonPath('code', 'assignment_inactive');

        $this->assertFalse((bool) $rutina->fresh()->is_assigned);
        $this->assertSame(0, MemberRoutineAssignment::count());
    }

    public function test_un_entrenador_no_publica_la_rutina_de_otro(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);
        $rutina = Routine::firstOrFail();

        $otro = Trainer::create([
            'full_name' => 'Coach Beto', 'document' => '802', 'phone' => '+573004440000',
            'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $otro->refresh();
        $otro->syncRoles([TrainerRole::FLOOR]);
        MemberTrainerAssignment::create([
            'member_id' => $this->member->id, 'trainer_id' => $otro->id, 'status' => 'active',
        ]);

        $this->postJson(
            "/api/trainer/routines/{$rutina->id}/publish",
            [],
            $this->asTrainer($this->loginTrainer('802')),
        )->assertStatus(422)->assertJsonPath('code', 'routine_not_owned');

        $this->assertFalse((bool) $rutina->fresh()->is_assigned);
    }

    // ── El editor del borrador ──────────────────────────────────────────────

    /** Genera y devuelve el borrador ya listo para editar. */
    private function generateDraft(): Routine
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        return Routine::firstOrFail();
    }

    /** @return list<array<string,mixed>> */
    private function currentExercises(Routine $r): array
    {
        return $this->getJson("/api/trainer/routines/{$r->id}", $this->asTrainer())
            ->assertOk()->json('data.exercises');
    }

    private function saveExercises(Routine $r, array $exercises, ?string $token = null)
    {
        return $this->putJson(
            "/api/trainer/routines/{$r->id}/exercises",
            ['exercises' => $exercises],
            $this->asTrainer($token),
        );
    }

    public function test_el_borrador_se_abre_con_sus_ejercicios_en_orden(): void
    {
        $r = $this->generateDraft();

        $ej = $this->currentExercises($r);

        $this->assertCount(3, $ej);
        $this->assertSame([0, 1, 2], array_column($ej, 'sort_order'));
        // Con el nombre y el grupo muscular reales, para poder revisarlo.
        $this->assertNotEmpty($ej[0]['name']);
        $this->assertSame($this->exerciseIds[0], $ej[0]['exercise_id']);
    }

    public function test_se_pueden_corregir_series_y_repeticiones(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $ej[0]['sets'] = 5;
        $ej[0]['reps'] = '6-8';
        $ej[0]['notes'] = 'Bajar despacio.';

        $this->saveExercises($r, $ej)->assertOk();

        $fila = RoutineExercise::find($ej[0]['id']);
        $this->assertSame(5, (int) $fila->sets);
        $this->assertSame('6-8', $fila->reps);
        $this->assertSame('Bajar despacio.', $fila->notes);
        // Y la fila es la MISMA: editar no la recrea.
        $this->assertSame($ej[0]['id'], $fila->id);
    }

    public function test_se_puede_sustituir_un_ejercicio_por_otro_del_catalogo(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $ej[0]['exercise_id'] = $this->exerciseIds[3]; // Peso muerto

        $resp = $this->saveExercises($r, $ej)->assertOk();

        $this->assertSame($this->exerciseIds[3], $resp->json('data.exercises.0.exercise_id'));
        $this->assertSame('Peso muerto', $resp->json('data.exercises.0.name'));
    }

    /** EL CANDADO, otra vez: el backend no se fía de que la app filtre bien. */
    public function test_un_ejercicio_fuera_del_catalogo_se_rechaza_al_editar(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);
        $original = $ej[0]['exercise_id'];

        $ej[0]['exercise_id'] = 999999;

        $this->saveExercises($r, $ej)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_exercise_id');

        // Y no se guardó NADA: ni ese ni los demás.
        $this->assertSame($original, RoutineExercise::find($ej[0]['id'])->exercise_id);
        $this->assertSame(3, RoutineExercise::where('routine_id', $r->id)->count());
    }

    public function test_reordenar_no_recrea_las_filas(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);
        $idsAntes = array_column($ej, 'id');

        // Se invierte el orden mandando la lista al revés.
        $this->saveExercises($r, array_reverse($ej))->assertOk();

        $despues = $this->currentExercises($r);

        $this->assertSame(array_reverse($idsAntes), array_column($despues, 'id'),
            'las filas son las mismas, solo cambian de sitio');
        $this->assertSame([0, 1, 2], array_column($despues, 'sort_order'));
    }

    public function test_quitar_un_ejercicio_lo_elimina_y_recoloca_el_resto(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        array_splice($ej, 1, 1); // fuera el del medio

        $this->saveExercises($r, $ej)->assertOk();

        $despues = $this->currentExercises($r);
        $this->assertCount(2, $despues);
        $this->assertSame([0, 1], array_column($despues, 'sort_order'));
        $this->assertSame(2, RoutineExercise::where('routine_id', $r->id)->count());
    }

    public function test_se_puede_anadir_un_ejercicio_nuevo(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $ej[] = ['exercise_id' => $this->exerciseIds[3], 'sets' => 3, 'reps' => '12'];

        $this->saveExercises($r, $ej)->assertOk();

        $this->assertCount(4, $this->currentExercises($r));
    }

    public function test_una_rutina_no_puede_quedarse_sin_ejercicios(): void
    {
        $r = $this->generateDraft();

        $this->saveExercises($r, [])->assertStatus(422);
        $this->assertSame(3, RoutineExercise::where('routine_id', $r->id)->count());
    }

    public function test_las_series_fuera_de_rango_se_rechazan(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);
        $ej[0]['sets'] = 99;

        $this->saveExercises($r, $ej)->assertStatus(422);
    }

    // ── Alcance del editor ──────────────────────────────────────────────────

    public function test_un_entrenador_no_edita_la_rutina_de_otro(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $otro = Trainer::create([
            'full_name' => 'Coach Beto', 'document' => '803', 'phone' => '+573005550000',
            'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $otro->refresh();
        $otro->syncRoles([TrainerRole::FLOOR]);
        MemberTrainerAssignment::create([
            'member_id' => $this->member->id, 'trainer_id' => $otro->id, 'status' => 'active',
        ]);

        $ej[0]['sets'] = 9;
        $this->saveExercises($r, $ej, $this->loginTrainer('803'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'routine_not_owned');

        $this->assertSame(4, (int) RoutineExercise::find($ej[0]['id'])->sets);
    }

    public function test_una_asignacion_inactiva_impide_editar(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        MemberTrainerAssignment::where('member_id', $this->member->id)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $ej[0]['sets'] = 9;
        $this->saveExercises($r, $ej)
            ->assertStatus(422)
            ->assertJsonPath('code', 'assignment_inactive');
    }

    /**
     * Una rutina publicada no se edita por aquí: el socio podría estar
     * entrenándola en ese momento y vería cambiar sus series a mitad de sesión.
     */
    public function test_una_rutina_ya_publicada_no_se_edita_por_este_camino(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $this->postJson("/api/trainer/routines/{$r->id}/publish", [], $this->asTrainer())->assertOk();

        $ej[0]['sets'] = 9;
        $this->saveExercises($r, $ej)
            ->assertStatus(422)
            ->assertJsonPath('code', 'routine_published');
    }

    // ── El catálogo del selector ────────────────────────────────────────────

    public function test_el_catalogo_del_entrenador_es_el_mismo_que_ve_la_ia(): void
    {
        $r = $this->getJson('/api/trainer/exercises', $this->asTrainer())->assertOk();

        $ids = array_column($r->json('data'), 'id');
        sort($ids);
        $esperados = $this->exerciseIds;
        sort($esperados);

        // Exactamente los mismos ejercicios que recibe el generador.
        $this->assertSame($esperados, $ids);
        $this->assertCount(4, $r->json('data'));
        // Con lo justo para elegir: nombre, músculo y equipamiento.
        $this->assertArrayHasKey('name', $r->json('data.0'));
        $this->assertArrayHasKey('muscle_group', $r->json('data.0'));
        $this->assertArrayHasKey('equipment', $r->json('data.0'));
    }

    public function test_un_ejercicio_sin_video_no_se_ofrece(): void
    {
        Exercise::create([
            'name' => 'Sin vídeo', 'local_name' => 'Sin vídeo',
            'muscle_group' => 'Pecho', 'equipment' => 'ninguno',
            'provider' => 'local', 'external_id' => 'ex-sin-video',
        ]);

        $r = $this->getJson('/api/trainer/exercises', $this->asTrainer())->assertOk();

        $this->assertCount(4, $r->json('data'), 'el que no tiene vídeo queda fuera');
    }

    // ── Editar y luego publicar ─────────────────────────────────────────────

    public function test_lo_editado_es_lo_que_ve_el_socio_al_publicar(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);

        $ej[0]['sets'] = 5;
        $ej[0]['reps'] = '6-8';
        $ej[0]['exercise_id'] = $this->exerciseIds[3];
        $this->saveExercises($r, $ej)->assertOk();

        $this->postJson("/api/trainer/routines/{$r->id}/publish", [], $this->asTrainer())->assertOk();

        // Y el socio la ve, con los cambios, en Mis Rutinas.
        $resp = $this->getJson('/api/app/routines/assigned', $this->asMember())->assertOk();
        $this->assertCount(1, $resp->json('data'));

        $fila = RoutineExercise::find($ej[0]['id']);
        $this->assertSame(5, (int) $fila->sets);
        $this->assertSame('6-8', $fila->reps);
        $this->assertSame($this->exerciseIds[3], $fila->exercise_id);
    }

    /** Guardar NO publica. Son dos actos distintos. */
    public function test_guardar_el_borrador_no_lo_publica(): void
    {
        $r = $this->generateDraft();
        $ej = $this->currentExercises($r);
        $ej[0]['sets'] = 5;

        $this->saveExercises($r, $ej)->assertOk()->assertJsonPath('data.published', false);

        $this->assertFalse((bool) $r->fresh()->is_assigned);
        $this->assertSame(0, MemberRoutineAssignment::count());
        $this->assertCount(0, $this->getJson('/api/app/routines/assigned', $this->asMember())->json('data'));
    }

    // ── Nutrición: el editor que YA existía ─────────────────────────────────

    public function test_el_borrador_de_nutricion_se_edita_con_el_flujo_de_siempre(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $guia = NutritionGuide::firstOrFail();

        $this->putJson("/api/trainer/nutrition-guides/{$guia->uuid}", [
            'objective' => 'Objetivo corregido por el entrenador',
            'recommendations' => 'Ajuste manual.',
        ], $this->asTrainer())->assertOk();

        $this->assertSame('Objetivo corregido por el entrenador', $guia->fresh()->objective);
        $this->assertSame(NutritionGuide::STATUS_DRAFT, $guia->fresh()->status, 'editar no publica');
    }

    // ── Regenerar e histórico ───────────────────────────────────────────────

    /**
     * Regenerar NO pisa lo anterior: quedan las dos versiones, cada una con su
     * origen. El entrenador puede comparar antes de decidir.
     */
    public function test_regenerar_conserva_el_borrador_anterior(): void
    {
        $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $plan2 = $this->goodPlan();
        $plan2['json']['routine']['name'] = 'Full body v2';
        $this->fakeAi($plan2);
        $this->generate()->assertStatus(201);

        $this->assertSame(2, Routine::count(), 'las dos rutinas siguen ahí');
        $this->assertSame(2, NutritionGuide::count());
        $this->assertSame(2, NutritionAiRun::count());

        // Y ninguna es visible todavía.
        $this->assertSame(0, MemberRoutineAssignment::count());
    }

    public function test_el_historico_de_valoraciones_no_se_toca(): void
    {
        $a = $this->assessment();
        $this->fakeAi($this->goodPlan());
        $this->generate()->assertStatus(201);

        $this->assertSame(1, ProfessionalAssessment::count());
        $this->assertSame(ProfessionalAssessment::STATUS_SUBMITTED, $a->fresh()->status);
        $this->assertSame(1, $a->fresh()->version);
    }
}
