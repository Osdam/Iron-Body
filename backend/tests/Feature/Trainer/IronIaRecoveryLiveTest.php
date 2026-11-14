<?php

namespace Tests\Feature\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Exercise;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\MembershipAiCapability;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\Plan;
use App\Models\ProfessionalAssessment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Models\User;
use App\Services\IronAiService;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use App\Services\Trainer\RoutineMuscleOrganizer;
use App\Services\Trainer\RoutinePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * E2E de la RECUPERACIÓN con OpenAI REAL y socios FICTICIOS (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * Igual que IronIaContextLiveTest: se salta sin `IRON_IA_LIVE=1`, cuesta dinero
 * (céntimos) y usa SOLO el catálogo exportado de producción.
 *
 *   IRON_IA_LIVE=1 IRON_IA_LIVE_KEY_FILE=/ruta/600 IRON_IA_LIVE_CATALOG=/ruta/catalogo.json \
 *   IRON_IA_LIVE_OUT=/ruta/resultados.jsonl \
 *   php vendor/bin/phpunit --group live tests/Feature/Trainer/IronIaRecoveryLiveTest.php
 */
#[Group('live')]
class IronIaRecoveryLiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array{int,int}, 1: array<string,mixed>}> */
    public static function casos(): array
    {
        return [
            'Recuperación · rutina 1 · intermedio' => [[1, 1], ['training_level' => 'intermedio']],
            'Recuperación · rutina 1 · principiante con rodilla lesionada' => [[1, 1], ['training_level' => 'principiante', 'injuries' => 'Dolor en la rodilla derecha al flexionar; evitar impacto.']],
            'Recuperación · rutina 2 · avanzado' => [[2, 2], ['training_level' => 'avanzado']],
            'Recuperación · rutina 3' => [[3, 3], ['training_level' => 'intermedio']],
            'Recuperación · plan 5/3 de producción' => [[5, 3], ['training_level' => 'intermedio']],
        ];
    }

    #[DataProvider('casos')]
    public function test_propuesta_real_contra_openai(array $potencias, array $perfil): void
    {
        if (getenv('IRON_IA_LIVE') !== '1') {
            $this->markTestSkipped('E2E real desactivado (IRON_IA_LIVE=1 para ejecutarlo).');
        }
        $catalogo = json_decode((string) file_get_contents((string) getenv('IRON_IA_LIVE_CATALOG')), true)['catalog'] ?? [];
        $this->assertNotEmpty($catalogo, 'falta el catálogo (IRON_IA_LIVE_CATALOG)');
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => $this->openAiKey(),
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'services.openai.timeout' => 30,
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
            'iron_ai.plan_powers.deadline_seconds' => 17,
            'iron_ai.plan_powers.min_repair_seconds' => 6,
        ]);

        [$member, $original] = $this->escenario($catalogo, $potencias, $perfil);
        $servicio = app(RoutineRecoveryCampaign::class);

        // Como la campaña: hasta 3 intentos sobre la misma fila.
        $intentos = [];
        for ($i = 1; $i <= RoutineRecoveryCampaign::MAX_ATTEMPTS; $i++) {
            $inicio = microtime(true);
            $res = $servicio->process('e2e', $original->id, now()->toDateString());
            $intentos[] = $res + ['ms' => (int) round((microtime(true) - $inicio) * 1000)];
            if ($res['outcome'] !== 'failed') {
                break;
            }
        }

        $item = IronIaRecoveryItem::where('original_routine_id', $original->id)->firstOrFail();
        $resultado = ['caso' => $this->dataName(), 'potencias' => $potencias, 'perfil' => $perfil, 'intentos' => $intentos, 'tokens' => (int) NutritionAiRun::where('mode', IntegralPlanGenerationService::RECOVERY_RUN_MODE)->sum('total_tokens')];
        if ($item->status === IronIaRecoveryItem::STATUS_PROPOSED) {
            $p = Routine::findOrFail($item->proposal_routine_id);
            $run = NutritionAiRun::findOrFail($item->run_id);
            $porId = Exercise::all()->keyBy('id');
            $resultado += [
                'propuesta' => [
                    'nombre' => $p->name, 'estado' => $p->status, 'visible' => (bool) $p->is_assigned, 'nivel' => $p->level,
                    'dias' => array_map(fn ($d) => [
                        'nombre' => $d['name'],
                        'ejercicios' => array_map(fn ($e) => ($porId[$e['exercise_id']]->local_name ?: $porId[$e['exercise_id']]->name).' ['.$porId[$e['exercise_id']]->muscle_group.'] '.$e['sets'].'×'.$e['reps'], $d['exercises']),
                    ], $p->days),
                ],
                'reparto' => $run->response_json['muscle_review'] ?? null,
                'corrida' => ['modo' => $run->mode, 'potencias' => [$run->nutrition_power, $run->routine_power], 'latency_ms' => $run->latency_ms, 'tokens' => $run->total_tokens],
            ];
        }
        if (($out = getenv('IRON_IA_LIVE_OUT')) !== false) {
            file_put_contents($out, json_encode($resultado, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }

        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, $item->status, (string) json_encode($intentos, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Borrador', $resultado['propuesta']['estado']);
        $this->assertFalse($resultado['propuesta']['visible']);
        $this->assertCount(6, $resultado['propuesta']['dias']);
        $this->assertSame(array_column(app(RoutineMuscleOrganizer::class)->dayPlan(6, $perfil['training_level']), 'name'), array_column($resultado['propuesta']['dias'], 'nombre'));
        $this->assertGreaterThanOrEqual(RoutineMuscleOrganizer::MIN_DAY_SHARE, $resultado['reparto']['min_share']);
    }

    /** @return array<string, array{0: array{int,int}, 1: array<string,mixed>}> */
    public static function flujos(): array
    {
        return [
            'Flujo de revisión · rutina 1 · intermedio' => [[1, 1], ['training_level' => 'intermedio']],
            'Flujo de revisión · plan 5/3 · hombro lesionado' => [[5, 3], ['training_level' => 'intermedio', 'injuries' => 'Molestia en el hombro izquierdo; evitar press por encima de la cabeza.']],
        ];
    }

    /**
     * QA del flujo COMPLETO del entrenador con el modelo real (RECUPERACION-REVISION-CONTRATO.md):
     * propuesta → publicar sin confirmar (no) → días que no cuadran (no) → regenerar con lo
     * confirmado → la anterior queda sustituida → publicar → la siguiente generación normal
     * del entrenador no queda bloqueada → el chat del socio no ve propuestas.
     */
    #[DataProvider('flujos')]
    public function test_flujo_de_revision_real_contra_openai(array $potencias, array $perfil): void
    {
        if (getenv('IRON_IA_LIVE') !== '1') {
            $this->markTestSkipped('E2E real desactivado (IRON_IA_LIVE=1 para ejecutarlo).');
        }
        $catalogo = json_decode((string) file_get_contents((string) getenv('IRON_IA_LIVE_CATALOG')), true)['catalog'] ?? [];
        $this->assertNotEmpty($catalogo, 'falta el catálogo (IRON_IA_LIVE_CATALOG)');
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => $this->openAiKey(),
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'services.openai.timeout' => 30,
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
            'iron_ai.plan_powers.deadline_seconds' => 17,
            'iron_ai.plan_powers.min_repair_seconds' => 6,
        ]);
        [$member, $original] = $this->escenario($catalogo, $potencias, $perfil);
        $trainer = Trainer::findOrFail($original->trainer_id);
        $campana = app(RoutineRecoveryCampaign::class);
        $publicador = app(RoutinePublisher::class);
        $nivel = $perfil['training_level'];
        $traza = ['caso' => $this->dataName(), 'potencias' => $potencias, 'perfil' => $perfil, 'pasos' => []];
        $codigo = function (callable $f): ?string {
            try {
                $f();
            } catch (IntegralPlanException $e) {
                return $e->code_;
            }

            return null;
        };

        // 1. La propuesta de la campaña (hasta 3 intentos, como la campaña).
        for ($i = 1; $i <= RoutineRecoveryCampaign::MAX_ATTEMPTS; $i++) {
            if ($campana->process('e2e-flujo', $original->id, now()->toDateString())['outcome'] !== 'failed') {
                break;
            }
        }
        $item = IronIaRecoveryItem::where('original_routine_id', $original->id)->firstOrFail();
        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, $item->status, (string) $item->error_message);
        $p6 = Routine::findOrFail($item->proposal_routine_id);
        $traza['pasos'][] = ['propuesta' => $p6->name, 'dias' => $p6->days_per_week];
        $this->assertSame(6, (int) $p6->days_per_week);

        // 2. Publicar sin confirmar, y con días que no cuadran: no se publica nada.
        $sin = $codigo(fn () => $publicador->publish($p6->fresh(), $trainer));
        $confirmacion = ['days_per_week' => 4, 'session_minutes' => 50, 'training_level' => $nivel, 'objective' => 'Perder grasa',
            'injuries' => $perfil['injuries'] ?? 'Ninguna', 'availability_confirmed' => true, 'muscle_split_reviewed' => true];
        $dias = $codigo(fn () => $publicador->publish($p6->fresh(), $trainer, $confirmacion));
        $traza['pasos'][] = ['publicar_sin_confirmar' => $sin, 'publicar_4_dias_sobre_6' => $dias];
        $this->assertSame('recovery_confirmation_required', $sin);
        $this->assertSame('recovery_days_mismatch', $dias);
        $this->assertFalse((bool) $p6->fresh()->is_assigned);

        // 3. Regenerar con lo confirmado (modelo real): 4 días, reparto muscular correcto.
        $p4 = null;
        $errores = [];
        for ($i = 1; $i <= 3 && $p4 === null; $i++) {
            try {
                $p4 = $campana->regenerate($item->fresh(), $trainer, $confirmacion);
            } catch (IntegralPlanException $e) {
                $errores[] = $e->code_;
            }
        }
        $this->assertNotNull($p4, 'la regeneración no salió en 3 intentos: '.implode(', ', $errores));
        $run = NutritionAiRun::findOrFail(IronIaRecoveryItem::findOrFail($item->id)->run_id);
        $traza['pasos'][] = ['regenerada' => $p4->name, 'dias' => $p4->days_per_week, 'nombres_dias' => array_column((array) $p4->days, 'name'),
            'reparto' => $run->response_json['muscle_review']['min_share'] ?? null, 'fallos_previos' => $errores, 'tokens' => $run->total_tokens];
        $this->assertSame(4, (int) $p4->days_per_week);
        $this->assertSame(array_column(app(RoutineMuscleOrganizer::class)->dayPlan(4, $nivel), 'name'), array_column((array) $p4->days, 'name'));
        $this->assertGreaterThanOrEqual(RoutineMuscleOrganizer::MIN_DAY_SHARE, $run->response_json['muscle_review']['min_share'] ?? 0);
        $this->assertSame('Retirada', $p6->fresh()->status);
        $this->assertSame('recovery_superseded', $codigo(fn () => $publicador->publish($p6->fresh(), $trainer, $confirmacion + ['days_per_week' => 6])));

        // 4. Publicar la regenerada con la confirmación: la original se retira y el historial queda.
        $publicada = $publicador->publish($p4->fresh(), $trainer, ['session_minutes' => 180] + $confirmacion);
        $this->assertTrue((bool) $publicada->is_assigned);
        $this->assertSame('Retirada', $original->fresh()->status);
        $this->assertTrue(Routine::whereKey($original->id)->exists());
        $this->assertSame(IronIaRecoveryItem::STATUS_PUBLISHED, $item->fresh()->status);
        $traza['pasos'][] = ['publicada' => $publicada->name, 'original' => $original->fresh()->status];

        // 5. El chat del socio no ve borradores ni propuestas; sí la publicada.
        $chat = app(IronAiService::class)->buildUserContext($member->fresh(), null);
        $this->assertStringNotContainsString('Propuesta:', $chat);
        $this->assertStringContainsString((string) $publicada->name, $chat);

        // 6. El entrenador sigue generando como siempre (modelo real, sus cupos intactos).
        $normal = app(IntegralPlanGenerationService::class)->generate($trainer, $member->fresh(), ['days_per_week' => 3, 'session_minutes' => 45]);
        $traza['pasos'][] = ['generacion_normal' => $normal['routine']->name, 'dias' => $normal['routine']->days_per_week];
        $this->assertSame(3, (int) $normal['routine']->days_per_week);
        $this->assertFalse((bool) $normal['routine']->is_assigned, 'la generación normal nace en borrador, como siempre');
        $traza['tokens_recuperacion'] = (int) NutritionAiRun::where('mode', IntegralPlanGenerationService::RECOVERY_RUN_MODE)->sum('total_tokens');
        $traza['tokens_total'] = (int) NutritionAiRun::sum('total_tokens');

        if (($out = getenv('IRON_IA_LIVE_OUT')) !== false) {
            file_put_contents($out, json_encode($traza, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }
    }

    private function openAiKey(): string
    {
        $fichero = (string) getenv('IRON_IA_LIVE_KEY_FILE');
        $this->assertFileExists($fichero, 'falta IRON_IA_LIVE_KEY_FILE');
        foreach (file($fichero, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
            if (str_starts_with($linea, 'OPENAI_API_KEY=')) {
                return trim(substr($linea, strlen('OPENAI_API_KEY=')), " \"'");
            }
        }
        $this->fail('el fichero de la clave no trae OPENAI_API_KEY');
    }

    /** @return array{0: Member, 1: Routine} socio ficticio con una rutina antigua de 4 días publicada */
    private function escenario(array $catalogo, array $potencias, array $perfil): array
    {
        foreach ($catalogo as $e) {
            Exercise::create([
                'id' => $e['id'], 'name' => $e['name'], 'local_name' => $e['local_name'], 'muscle_group' => $e['muscle_group'],
                'body_part' => $e['body_part'], 'equipment' => $e['equipment'], 'difficulty' => $e['difficulty'], 'target' => $e['target'],
                'video_path' => "videos/e2e-{$e['id']}.mp4", 'provider' => 'export', 'external_id' => "e2e-{$e['id']}",
            ]);
        }
        $trainer = Trainer::create(['full_name' => 'Coach E2E', 'document' => '990', 'phone' => '+573000000990', 'status' => 'active', 'location' => 'Sede E2E']);
        $plan = Plan::create(['name' => 'Plan E2E', 'price' => 100000, 'duration_days' => 30, 'active' => true, 'features' => ['iron_ia' => true, 'nutrition' => true, 'workouts' => true]]);
        MembershipAiCapability::create(['membership_plan_id' => $plan->id, 'plan_code' => 'plan_e2e', 'is_active' => true, 'ai_enabled' => true, 'nutrition_power' => $potencias[0], 'routine_power' => $potencias[1]]);
        $user = User::create(['name' => 'Socio E2E', 'email' => 'e2e-'.uniqid().'@ironbody.test', 'password' => 'secret-password', 'document' => '991', 'status' => 'active', 'plan' => 'Plan E2E', 'membership_start_date' => now()->subDays(5)->toDateString(), 'membership_end_date' => now()->addDays(25)->toDateString()]);
        $member = Member::create(array_merge([
            'user_id' => $user->id, 'full_name' => 'Socio E2E', 'document_number' => '991', 'phone' => '+573000000991',
            'status' => Member::STATUS_ACTIVE, 'birth_date' => '1994-05-20', 'gender' => 'femenino',
            'goal' => 'Perder grasa', 'training_level' => 'intermedio', 'injuries' => null,
        ], $perfil));
        MemberTrainerAssignment::create(['member_id' => $member->id, 'trainer_id' => $trainer->id, 'status' => MemberTrainerAssignment::STATUS_ACTIVE]);
        ProfessionalAssessment::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'trainer_id' => $trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED, 'version' => 1,
            'weight_kg' => 70, 'height_cm' => 165, 'body_fat_pct' => 30, 'submitted_at' => now(),
        ]);

        // La rutina del flujo antiguo: su corrida sin contexto y, dentro, la rutina publicada.
        $ahora = now();
        $t = $ahora->copy()->subDays(3);
        Carbon::setTestNow($t);
        $run = NutritionAiRun::create(['uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'mode' => IntegralPlanGenerationService::RUN_MODE,
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'status' => 'ok', 'prompt_version' => 'v1', 'response_json' => ['routine' => []]]);
        Carbon::setTestNow($t->copy()->addSeconds(3));
        $original = Routine::create(['name' => 'Rutina antigua 4 días', 'member_id' => $member->id, 'trainer_id' => $trainer->id, 'days_per_week' => 4,
            'is_assigned' => true, 'is_template' => false, 'status' => 'Activa', 'generated_by_ai' => true, 'level' => $perfil['training_level']]);
        RoutineExercise::create(['routine_id' => $original->id, 'exercise_id' => Exercise::orderBy('id')->value('id'), 'sets' => 3, 'reps' => '10', 'sort_order' => 0]);
        MemberRoutineAssignment::create(['member_id' => $member->id, 'routine_id' => $original->id, 'assigned_at' => now()]);
        Carbon::setTestNow($t->copy()->addSeconds(6));
        $run->touch();
        Carbon::setTestNow($ahora);

        return [$member, $original];
    }
}
