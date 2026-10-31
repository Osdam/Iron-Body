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
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\User;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NIVEL 1 = HOY. Con las dos potencias a 1, lo que se envía a OpenAI y lo que
 * se guarda tiene que ser exactamente lo de antes de las potencias.
 *
 * La referencia NO la escribió este cambio: `tests/fixtures/iron-ia/v1-*.json`
 * se capturó ejecutando el código de producción (594550b) con este mismo
 * escenario y la respuesta de `v1-openai-response.json`, ANTES de tocar nada
 * (ver docs/iron-ia/POTENCIAS-IRON-IA.md §6). Si alguien cambia el prompt, los
 * parámetros o la forma de guardar del nivel 1, esto se pone rojo.
 *
 * Lo único que el nivel 1 añade —y por eso se comprueba aparte— es medir:
 * tokens, latencia y nivel en la fila de `nutrition_ai_runs`.
 */
class IntegralPlanLevelOneParityTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // El MISMO escenario de la captura, campo a campo.
        Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00', 'UTC'));
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
        ]);

        $trainer = Trainer::create([
            'full_name' => 'Coach Ana', 'document' => '800', 'phone' => '+573001110000',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);
        $this->member = Member::create([
            'full_name' => 'Socio Plan', 'document_number' => '801', 'phone' => '+573002220000',
            'status' => Member::STATUS_ACTIVE,
            'birth_date' => '1996-03-10',
            'gender' => 'masculino', 'goal' => 'Bajar grasa',
            'training_level' => 'intermedio', 'injuries' => 'Lumbar sensible',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles([TrainerRole::FLOOR]);
        MemberTrainerAssignment::create([
            'member_id' => $this->member->id, 'trainer_id' => $trainer->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE,
        ]);
        foreach (['Sentadilla', 'Press banca', 'Remo con barra', 'Peso muerto'] as $i => $n) {
            Exercise::create([
                'name' => $n, 'local_name' => $n,
                'muscle_group' => 'Piernas', 'body_part' => 'lower legs',
                'equipment' => 'barra', 'difficulty' => 'intermedio', 'target' => 'cuádriceps',
                'video_path' => "videos/{$i}.mp4",
                'provider' => 'local', 'external_id' => "ex-{$i}",
            ]);
        }
        ProfessionalAssessment::create([
            'uuid' => (string) Str::uuid(),
            'member_id' => $this->member->id, 'trainer_id' => $trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED, 'version' => 1,
            'weight_kg' => 82, 'height_cm' => 178, 'body_fat_pct' => 20,
            'observations' => 'Buena movilidad de cadera.',
            'submitted_at' => now(),
        ]);

        $a = $this->postJson('/api/trainer/auth/access', ['document' => '800', 'device_id' => 't1'])->assertOk();
        $this->token = $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $a->json('challenge_id'), 'code' => $a->json('dev_code'), 'device_id' => 't1',
        ])->assertOk()->json('token');
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/iron-ia/{$name}.json")), true);
    }

    /**
     * Ids reales → los de la captura (1..4 por orden de creación).
     *
     * En SQLite coinciden; en PostgreSQL las secuencias no vuelven a 1 entre
     * pruebas (el ROLLBACK no las toca), así que la misma escena tiene otros
     * números. Los ids son lo ÚNICO que se traduce: todo lo demás se compara
     * tal cual.
     *
     * @return array<int,int>
     */
    private function ids(string $model): array
    {
        $reales = $model::query()->orderBy('id')->pluck('id')->all();

        return array_combine($reales, range(1, count($reales)));
    }

    /** La respuesta capturada, con sus ids llevados a los reales (como haría el modelo). */
    private function upstream(): array
    {
        $cuerpo = $this->fixture('v1-openai-response');
        $aReales = array_flip($this->ids(Exercise::class));
        $plan = json_decode($cuerpo['choices'][0]['message']['content'], true);
        foreach ($plan['routine']['days'] as &$d) {
            foreach ($d['exercises'] as &$e) {
                $e['exercise_id'] = $aReales[$e['exercise_id']] ?? $e['exercise_id'];
            }
        }
        unset($d, $e);
        // Si no hay que traducir nada, se envía la captura byte a byte.
        if ($aReales !== array_combine(range(1, count($aReales)), range(1, count($aReales)))) {
            $cuerpo['choices'][0]['message']['content'] = json_encode($plan, JSON_UNESCAPED_UNICODE);
        }

        return $cuerpo;
    }

    /**
     * El payload esperado: el capturado, con los ids del catálogo traducidos a
     * los reales y vuelto a serializar igual que lo hace el servicio. Antes se
     * comprueba que esa serialización no altera NADA de la captura.
     */
    private function expectedPayload(): array
    {
        $esperado = $this->fixture('v1-request-payload');
        $usuario = json_decode($esperado['messages'][1]['content'], true);
        $this->assertSame(
            $esperado['messages'][1]['content'],
            json_encode($usuario, JSON_UNESCAPED_UNICODE),
            'la re-serialización de la captura no es exacta: la comparación no valdría',
        );

        $aReales = array_flip($this->ids(Exercise::class));
        foreach ($usuario['exercise_catalog'] as &$e) {
            $e['id'] = $aReales[$e['id']];
        }
        unset($e);
        $esperado['messages'][1]['content'] = json_encode($usuario, JSON_UNESCAPED_UNICODE);

        return $esperado;
    }

    /** Lo mismo que guardó la captura, con los mismos campos y los ids traducidos a los de la captura. */
    private function snapshot(array $response): array
    {
        $ej = $this->ids(Exercise::class);
        $rutina = $this->ids(Routine::class);
        $valoracion = $this->ids(ProfessionalAssessment::class);
        $dias = function (array $days) use ($ej): array {
            foreach ($days as &$d) {
                foreach ($d['exercises'] as &$e) {
                    $e['exercise_id'] = $ej[$e['exercise_id']];
                }
            }

            return $days;
        };

        $out = [
            'response' => $response,
            'guide' => NutritionGuide::firstOrFail()->only([...NutritionGuide::CONTENT_FIELDS, 'status', 'version', 'generated_by_ai', 'source_assessment_id', 'trainer_type']),
            'routine' => Routine::firstOrFail()->only(['name', 'objective', 'level', 'description', 'days_per_week', 'is_assigned', 'is_template', 'created_by_admin', 'status', 'source_assessment_id', 'generated_by_ai', 'days', 'notes']),
            'routine_exercises' => RoutineExercise::orderBy('sort_order')->get(['exercise_id', 'sets', 'reps', 'weight', 'notes', 'sort_order'])->toArray(),
            'run' => NutritionAiRun::firstOrFail()->only(['mode', 'provider', 'model', 'input_hash', 'status', 'error_code', 'prompt_version', 'response_json']),
        ];
        unset($out['response']['data']['nutrition_guide']['uuid']);
        // `nutrition_guides.generated_by_ai` no tiene cast: SQLite (donde se
        // capturó) lo devuelve 1 y PostgreSQL, true. Es el mismo valor.
        $out['guide']['generated_by_ai'] = (int) $out['guide']['generated_by_ai'];

        $out['response']['data']['routine']['id'] = $rutina[$out['response']['data']['routine']['id']];
        $out['response']['data']['routine']['source_assessment_id'] = $valoracion[$out['response']['data']['routine']['source_assessment_id']];
        $out['response']['data']['source_assessment_id'] = $valoracion[$out['response']['data']['source_assessment_id']];
        $out['response']['data']['routine']['days'] = $dias($out['response']['data']['routine']['days']);
        $out['guide']['source_assessment_id'] = $valoracion[$out['guide']['source_assessment_id']];
        $out['routine']['source_assessment_id'] = $valoracion[$out['routine']['source_assessment_id']];
        $out['routine']['days'] = $dias($out['routine']['days']);
        $out['run']['response_json']['routine']['days'] = $dias($out['run']['response_json']['routine']['days']);
        foreach ($out['routine_exercises'] as &$re) {
            $re['exercise_id'] = $ej[$re['exercise_id']];
        }
        unset($re);

        return json_decode(json_encode($out), true);
    }

    private function generateAndCompare(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->upstream(), 200)]);

        $r = $this->postJson("/api/trainer/members/{$this->member->id}/integral-plan/generate", [], ['Authorization' => "Bearer {$this->token}"])
            ->assertStatus(201);

        $enviados = Http::recorded();
        $this->assertCount(1, $enviados, 'el nivel 1 es UNA llamada');
        $enviado = $enviados[0][0]->data();
        $this->assertSame(
            $this->expectedPayload(),
            $enviado,
            'el payload del nivel 1 cambió respecto a producción (prompt, parámetros o contexto)',
        );

        // El hash de entrada es la misma fórmula sobre lo enviado; con los ids
        // de la captura, el mismo valor.
        $snap = $this->snapshot($r->json());
        $this->assertSame(hash('sha256', 'v1|'.json_encode($enviado['messages'])), $snap['run']['input_hash']);
        $esperado = $this->fixture('v1-expected-output');
        if ($enviado === $this->fixture('v1-request-payload')) {
            $this->assertSame($esperado['run']['input_hash'], $snap['run']['input_hash']);
        }
        $snap['run']['input_hash'] = $esperado['run']['input_hash'];

        $this->assertSame($esperado, $snap);
    }

    public function test_nivel_1_envia_el_mismo_payload_que_produccion_y_guarda_lo_mismo(): void
    {
        // Sin plan: nivel 1 por definición.
        $this->generateAndCompare();
    }

    public function test_un_plan_con_potencias_1_1_explicitas_es_identico_a_no_tener_plan(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        MembershipAiCapability::create([
            'membership_plan_id' => $plan->id, 'plan_code' => 'mensual', 'is_active' => true, 'ai_enabled' => true,
            'nutrition_power' => 1, 'routine_power' => 1,
        ]);
        $user = User::create([
            'name' => 'Socio Plan', 'email' => 'paridad@ironbody.test', 'password' => 'secret-password',
            'document' => '801', 'status' => 'active', 'plan' => 'Mensual', 'membership_end_date' => '2026-11-27',
        ]);
        $this->member->update(['user_id' => $user->id]);

        $this->generateAndCompare();
    }

    /** Lo ÚNICO nuevo en el nivel 1: se mide. Columnas aparte, nada cambia en lo de antes. */
    public function test_nivel_1_guarda_tokens_latencia_y_nivel(): void
    {
        $this->generateAndCompare();

        $run = NutritionAiRun::firstOrFail();
        $this->assertSame(1, $run->nutrition_power);
        $this->assertSame(1, $run->routine_power);
        $this->assertSame(1180, $run->prompt_tokens);
        $this->assertSame(742, $run->completion_tokens);
        $this->assertSame(1922, $run->total_tokens);
        $this->assertNotNull($run->latency_ms);
        $this->assertSame('stop', $run->usage_json['finish_reason']);
    }

    /**
     * Robustez: una respuesta cortada por longitud ya no se confunde con una
     * mal formada, y su texto se conserva en la corrida (antes se perdía).
     */
    public function test_nivel_1_cortado_por_longitud_falla_reintentable_sin_guardar_y_conserva_el_texto(): void
    {
        $cuerpo = $this->upstream();
        $cuerpo['choices'][0]['finish_reason'] = 'length';
        $cuerpo['choices'][0]['message']['content'] = mb_substr($cuerpo['choices'][0]['message']['content'], 0, 900);
        Http::fake(['api.openai.com/*' => Http::response($cuerpo, 200)]);

        $this->postJson("/api/trainer/members/{$this->member->id}/integral-plan/generate", [], ['Authorization' => "Bearer {$this->token}"])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_invalid_output')
            ->assertJsonPath('retryable', true);

        $this->assertSame(0, NutritionGuide::count());
        $this->assertSame(0, Routine::count());
        $run = NutritionAiRun::firstOrFail();
        $this->assertSame('invalid', $run->status);
        $this->assertSame('ai_truncated', $run->error_code);
        $this->assertStringStartsWith('{"analysis"', $run->response_json['truncated_content']);
    }
}
