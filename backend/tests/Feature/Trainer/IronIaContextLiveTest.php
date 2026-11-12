<?php

namespace Tests\Feature\Trainer;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MembershipAiCapability;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\NutritionGuide;
use App\Models\Plan;
use App\Models\ProfessionalAssessment;
use App\Models\Routine;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\User;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\PlanSafetyLexicon;
use App\Services\Trainer\SessionTimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * E2E con OpenAI REAL y socios FICTICIOS (docs/iron-ia/CONTEXTO-DEL-ENTRENADOR.md §E2E).
 *
 * NO corre en la suite normal: se salta si falta `IRON_IA_LIVE=1`. Cuesta dinero
 * (≈ 0,01 USD el nivel 1, ≈ 0,05 USD el 5) y tarda lo que tarda el modelo.
 *
 *   IRON_IA_LIVE=1 \
 *   IRON_IA_LIVE_KEY_FILE=/ruta/600/con/OPENAI_API_KEY=... \
 *   IRON_IA_LIVE_CATALOG=/ruta/catalogo.json   # {"catalog":[{id,name,...}]}, SOLO el catálogo
 *   IRON_IA_LIVE_OUT=/ruta/resultados.jsonl \
 *   php vendor/bin/phpunit --group live tests/Feature/Trainer/IronIaContextLiveTest.php
 *
 * La clave se lee del fichero y nunca se imprime. Nada sale de esta base local
 * salvo la petición a OpenAI, que no lleva nombre, teléfono ni documento.
 */
#[Group('live')]
class IronIaContextLiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array{int,int}, 1: array<string,mixed>, 2: array<string,mixed>}> */
    public static function casos(): array
    {
        $base = ['injuries' => 'Ninguna', 'restrictions' => 'Ninguna reportada', 'observations' => 'Ninguna'];

        return [
            'L1 · 6 días · 45 min · intermedio · perder grasa' => [[1, 1], ['objective' => 'Perder grasa', 'training_level' => 'intermedio', 'days_per_week' => 6, 'session_minutes' => 45] + $base, []],
            'L1 · 3 días · 30 min · principiante · mantenimiento' => [[1, 1], ['objective' => 'Mantenimiento', 'training_level' => 'principiante', 'days_per_week' => 3, 'session_minutes' => 30] + $base, ['training_level' => 'principiante']],
            'L1 · 4 días · 45 min · intermedio · ganar masa' => [[1, 1], ['objective' => 'Ganar masa muscular', 'training_level' => 'intermedio', 'days_per_week' => 4, 'session_minutes' => 45] + $base, []],
            'L1 · 5 días · 60 min · avanzado · rendimiento' => [[1, 1], ['objective' => 'Mejorar rendimiento', 'training_level' => 'avanzado', 'days_per_week' => 5, 'session_minutes' => 60] + $base, ['training_level' => 'avanzado']],
            'L1 · alergia al maní y sin lácteos · 4 días · 45 min' => [[1, 1], ['objective' => 'Perder grasa', 'training_level' => 'intermedio', 'days_per_week' => 4, 'session_minutes' => 45, 'restrictions' => 'Alergia al maní. No toma lácteos.'] + $base, []],
            'L1 · cuerpo vacío (app 2.0.9)' => [[1, 1], [], []],
            'L5 · 6 días · 45 min · intermedio · perder grasa' => [[5, 5], ['objective' => 'Perder grasa', 'training_level' => 'intermedio', 'days_per_week' => 6, 'session_minutes' => 45] + $base, []],
            'L5/3 (plan de producción) · 6 días · 45 min' => [[5, 3], ['objective' => 'Perder grasa', 'training_level' => 'intermedio', 'days_per_week' => 6, 'session_minutes' => 45] + $base, []],
            'L5 · alergia al maní · 4 días · 45 min' => [[5, 5], ['objective' => 'Perder grasa', 'training_level' => 'intermedio', 'days_per_week' => 4, 'session_minutes' => 45, 'restrictions' => 'Alergia al maní'] + $base, []],
            'L3 · 5 días · 60 min · avanzado · recomposición' => [[3, 3], ['objective' => 'Recomposición', 'training_level' => 'avanzado', 'days_per_week' => 5, 'session_minutes' => 60] + $base, ['training_level' => 'avanzado']],
        ];
    }

    #[DataProvider('casos')]
    public function test_contexto_real_contra_openai(array $potencias, array $cuerpo, array $perfil): void
    {
        if (getenv('IRON_IA_LIVE') !== '1') {
            $this->markTestSkipped('E2E real desactivado (IRON_IA_LIVE=1 para ejecutarlo).');
        }
        $clave = $this->openAiKey();
        $catalogo = json_decode((string) file_get_contents((string) getenv('IRON_IA_LIVE_CATALOG')), true)['catalog'] ?? [];
        $this->assertNotEmpty($catalogo, 'falta el catálogo (IRON_IA_LIVE_CATALOG)');

        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => $clave,
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'services.openai.timeout' => 30,
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
            'iron_ai.plan_powers.deadline_seconds' => 17,
            'iron_ai.plan_powers.min_repair_seconds' => 6,
        ]);

        [$member, $token] = $this->escenario($catalogo, $potencias, $perfil);

        $intentos = [];
        for ($i = 1; $i <= 2; $i++) {
            $inicio = microtime(true);
            $r = $this->postJson("/api/trainer/members/{$member->id}/integral-plan/generate", $cuerpo, ['Authorization' => "Bearer {$token}"]);
            $intentos[] = ['status' => $r->status(), 'ms' => (int) round((microtime(true) - $inicio) * 1000), 'code' => $r->json('code'), 'message' => $r->status() === 201 ? null : $r->json('message')];
            if ($r->status() !== 422 || ! $r->json('retryable')) {
                break;
            }
        }

        $resultado = ['caso' => $this->dataName(), 'potencias' => $potencias, 'cuerpo' => $cuerpo, 'intentos' => $intentos];
        $ultimo = end($intentos);
        if ($ultimo['status'] === 201) {
            $rutina = Routine::latest('id')->firstOrFail();
            $guia = NutritionGuide::latest('id')->firstOrFail();
            $run = NutritionAiRun::where('status', 'ok')->latest('id')->firstOrFail();
            $min = $cuerpo['session_minutes'] ?? null;
            $claves = array_column(app(PlanSafetyLexicon::class)->restrictionsIn([(string) ($cuerpo['restrictions'] ?? '')]), 'key');
            $choques = [];
            foreach ((array) $guia->meals as $m) {
                foreach (app(PlanSafetyLexicon::class)->conflicts(trim(($m['title'] ?? $m['label'] ?? '').' '.($m['description'] ?? '')), $claves) as $c) {
                    $choques[] = $c['found'];
                }
            }
            $resultado += [
                'rutina' => [
                    'dias' => count($rutina->days), 'days_per_week' => $rutina->days_per_week, 'nivel' => $rutina->level,
                    'ejercicios_por_dia' => array_map(fn ($d) => count($d['exercises']), $rutina->days),
                    'min_estimados' => $min === null ? null : array_map(fn ($d) => SessionTimeBudget::estimateMinutes($d['exercises'], $min), $rutina->days),
                    'nombres_dias' => array_map(fn ($d) => $d['name'], $rutina->days),
                ],
                'guia' => ['comidas' => count((array) $guia->meals), 'objetivo' => $guia->objective, 'choques_con_restricciones' => $choques],
                'analisis' => $run->response_json['analysis'] ?? ($run->response_json['plan']['analysis'] ?? null),
                'corrida' => ['latency_ms' => $run->latency_ms, 'tokens' => $run->total_tokens, 'llamadas' => $run->usage_json['calls'] ?? null, 'reparaciones' => $run->usage_json['repairs'] ?? null],
                'trainer_context' => $run->response_json['trainer_context'] ?? null,
            ];
        }
        if (($out = getenv('IRON_IA_LIVE_OUT')) !== false) {
            file_put_contents($out, json_encode($resultado, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }

        $this->assertSame(201, $ultimo['status'], (string) json_encode($intentos, JSON_UNESCAPED_UNICODE));
        if (isset($cuerpo['days_per_week'])) {
            $this->assertSame(min(6, $cuerpo['days_per_week']), $resultado['rutina']['dias']);
            $this->assertSame($cuerpo['training_level'], $resultado['rutina']['nivel']);
            foreach ($resultado['rutina']['min_estimados'] as $m) {
                $this->assertLessThanOrEqual($cuerpo['session_minutes'] * SessionTimeBudget::TOLERANCE, $m);
            }
            $this->assertSame([], $resultado['guia']['choques_con_restricciones']);
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

    /**
     * Socio ficticio con valoración, plan con las potencias del caso, entrenador asignado.
     *
     * @return array{0: Member, 1: string}
     */
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
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles([TrainerRole::FLOOR]);
        MemberTrainerAssignment::create(['member_id' => $member->id, 'trainer_id' => $trainer->id, 'status' => MemberTrainerAssignment::STATUS_ACTIVE]);
        ProfessionalAssessment::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'trainer_id' => $trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED, 'version' => 1,
            'weight_kg' => 70, 'height_cm' => 165, 'body_fat_pct' => 30, 'submitted_at' => now(),
        ]);

        $a = $this->postJson('/api/trainer/auth/access', ['document' => '990', 'device_id' => 'e2e'])->assertOk();
        $token = $this->postJson('/api/trainer/auth/verify', ['challenge_id' => $a->json('challenge_id'), 'code' => $a->json('dev_code'), 'device_id' => 'e2e'])->assertOk()->json('token');

        return [$member, $token];
    }
}
