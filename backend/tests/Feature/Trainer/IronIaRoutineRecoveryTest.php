<?php

namespace Tests\Feature\Trainer;

use App\Jobs\IronIa\GenerateRecoveryProposal;
use App\Models\Exercise;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MemberRoutineAssignment;
use App\Models\MembershipAiCapability;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\NutritionGoal;
use App\Models\NutritionGuide;
use App\Models\Plan;
use App\Models\ProfessionalAssessment;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Identity\IdentityLinkService;
use App\Services\Nutrition\Ai\NutritionAiCostGuard;
use App\Services\RealtimeEvents;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use App\Services\Trainer\RoutineMuscleOrganizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeIronIaOpenAi;
use Tests\TestCase;

/**
 * Recuperación de rutinas de Iron IA del flujo antiguo (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * Lo que se vigila, en este orden: que solo entren las rutinas que lo
 * necesitan, que la propuesta nazca invisible y marcada como «por confirmar»,
 * que nada se publique solo, que publicar la haga vigente sin borrar historia,
 * que repetir o cortar la campaña no duplique ni cobre dos veces, y que el
 * entrenador siga generando y sustituyendo con normalidad.
 *
 * OpenAI es siempre un doble (`Http::fake`): ninguna prueba sale a la red.
 */
class IronIaRoutineRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPANA = 'recuperacion-prueba';

    private const CORTE = '2026-11-13';

    private Trainer $trainer;

    private Plan $plan;

    private string $token;

    private int $documento = 950;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-01 15:00:00', 'UTC'));

        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
            'iron_ai.plan_powers.deadline_seconds' => 17.0,
            'iron_ai.plan_powers.min_repair_seconds' => 6.0,
        ]);
        Http::preventStrayRequests();

        $this->trainer = Trainer::create([
            'full_name' => 'Coach Ficticio', 'document' => '900', 'phone' => '+573009990000',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);
        $this->plan = Plan::create(['name' => 'Plan Ficticio', 'price' => 100000, 'duration_days' => 30, 'active' => true,
            'features' => ['iron_ia' => true, 'nutrition' => true, 'workouts' => true]]);
        $this->powers(1, 1);

        // Un catálogo con el que se puede repartir una semana de 6 días con sentido.
        $catalogo = [
            ['Press banca', 'Pecho'], ['Press inclinado', 'Pecho'], ['Aperturas', 'Pecho'],
            ['Press militar', 'Hombro'], ['Elevaciones laterales', 'Hombro'], ['Pájaros', 'Hombro'],
            ['Extensión de tríceps', 'Tríceps'], ['Fondos en banco', 'Tríceps'],
            ['Remo con barra', 'Espalda'], ['Jalón al pecho', 'Espalda'], ['Remo con mancuerna', 'Espalda'], ['Dominadas asistidas', 'Espalda'],
            ['Curl de bíceps', 'Bíceps'], ['Curl martillo', 'Bíceps'],
            ['Sentadilla goblet', 'Piernas'], ['Prensa de piernas', 'Piernas'], ['Zancadas', 'Piernas'], ['Hip thrust', 'Glúteo'],
            ['Peso muerto rumano', 'Femoral'], ['Curl femoral', 'Femoral'], ['Elevación de talones', 'Pantorrilla'],
            ['Plancha', 'Core'], ['Crunch', 'Core'],
        ];
        foreach ($catalogo as $i => [$n, $g]) {
            Exercise::create([
                'name' => $n, 'local_name' => $n, 'muscle_group' => $g, 'body_part' => $g,
                'equipment' => 'mancuernas', 'difficulty' => 'intermedio', 'target' => $g,
                'video_path' => "videos/r{$i}.mp4", 'provider' => 'local', 'external_id' => "rec-{$i}",
            ]);
        }

        app(IdentityLinkService::class)->backfillExisting();
        $this->trainer->refresh();
        $this->trainer->syncRoles([TrainerRole::FLOOR]);
        $this->token = $this->login('900', 'r1');

        // «Hoy» es después del corte: la campaña se lanza cuando ya hay app corregida.
        Carbon::setTestNow(Carbon::parse('2026-11-14 15:00:00', 'UTC'));
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function login(string $documento, string $dispositivo): string
    {
        $a = $this->postJson('/api/trainer/auth/access', ['document' => $documento, 'device_id' => $dispositivo])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $a->json('challenge_id'), 'code' => $a->json('dev_code'), 'device_id' => $dispositivo,
        ])->assertOk()->json('token');
    }

    private function powers(int $n, int $r): void
    {
        MembershipAiCapability::updateOrCreate(
            ['membership_plan_id' => $this->plan->id],
            ['plan_code' => 'plan_ficticio', 'is_active' => true, 'ai_enabled' => true, 'nutrition_power' => $n, 'routine_power' => $r],
        );
    }

    /** @param  array{level?: string, injuries?: ?string, end?: string, trainer?: ?Trainer}  $o */
    private function socio(array $o = []): Member
    {
        $doc = (string) ++$this->documento;
        $user = User::create([
            'name' => 'Socio '.$doc, 'email' => "socio-{$doc}@ironbody.test", 'password' => 'secret-password',
            'document' => $doc, 'status' => 'active', 'plan' => 'Plan Ficticio',
            'membership_start_date' => '2026-10-01', 'membership_end_date' => $o['end'] ?? '2026-12-31',
        ]);
        $m = Member::create([
            'user_id' => $user->id, 'full_name' => 'Socio '.$doc, 'document_number' => $doc, 'phone' => '+57300'.$doc,
            'status' => Member::STATUS_ACTIVE, 'birth_date' => '1994-05-20', 'gender' => 'femenino',
            'goal' => 'Perder grasa', 'training_level' => $o['level'] ?? 'intermedio', 'injuries' => $o['injuries'] ?? null,
        ]);
        $entrenador = array_key_exists('trainer', $o) ? $o['trainer'] : $this->trainer;
        if ($entrenador !== null) {
            MemberTrainerAssignment::create([
                'member_id' => $m->id, 'trainer_id' => $entrenador->id, 'status' => MemberTrainerAssignment::STATUS_ACTIVE,
            ]);
        }

        return $m;
    }

    /**
     * Una rutina de Iron IA como las de producción: su corrida y, dentro de
     * ella, la rutina con sus ejercicios; publicada salvo que se diga otra cosa.
     *
     * @param  array{at?: string, context?: bool, status?: string, visible?: bool, power?: ?int}  $o
     */
    private function rutinaIa(Member $m, int $dias, array $o = []): Routine
    {
        $ahora = Carbon::now();
        $t = Carbon::parse($o['at'] ?? '2026-11-05 15:00:00', 'UTC');
        Carbon::setTestNow($t);
        $run = NutritionAiRun::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $m->id, 'mode' => IntegralPlanGenerationService::RUN_MODE,
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'status' => 'ok', 'prompt_version' => 'v1',
            'nutrition_power' => $o['power'] ?? null, 'routine_power' => $o['power'] ?? null, 'total_tokens' => 11000,
            'response_json' => ($o['context'] ?? false) ? ['routine' => [], 'trainer_context' => ['received' => ['days_per_week']]] : ['routine' => []],
        ]);

        Carbon::setTestNow($t->copy()->addSeconds(4));
        $ids = Exercise::orderBy('id')->pluck('id')->all();
        $diasJson = [];
        for ($d = 0; $d < $dias; $d++) {
            $diasJson[] = ['name' => 'Día '.($d + 1), 'exercises' => array_map(
                fn ($k) => ['exercise_id' => $ids[($d * 3 + $k) % count($ids)], 'sets' => 3, 'reps' => '10', 'notes' => ''],
                range(0, 3),
            )];
        }
        $visible = $o['visible'] ?? true;
        $r = Routine::create([
            'name' => "Rutina IA {$dias} días", 'objective' => 'Perder grasa', 'level' => 'intermedio', 'days_per_week' => $dias,
            'member_id' => $m->id, 'trainer_id' => $this->trainer->id, 'trainer_name' => $this->trainer->full_name,
            'is_assigned' => $visible, 'is_template' => false, 'created_by_admin' => false,
            'status' => $o['status'] ?? ($visible ? 'Activa' : 'Borrador'), 'generated_by_ai' => true, 'days' => $diasJson,
        ]);
        $orden = 0;
        foreach ($diasJson as $dia) {
            foreach ($dia['exercises'] as $e) {
                RoutineExercise::create(['routine_id' => $r->id, 'exercise_id' => $e['exercise_id'], 'sets' => 3, 'reps' => '10', 'sort_order' => $orden++]);
            }
        }
        if ($visible) {
            MemberRoutineAssignment::create(['member_id' => $m->id, 'routine_id' => $r->id, 'assigned_at' => now()]);
        }

        Carbon::setTestNow($t->copy()->addSeconds(8));
        $run->touch();
        Carbon::setTestNow($ahora);

        return $r->refresh();
    }

    private function recuperar(array $opciones = []): PendingCommand
    {
        return $this->artisan('iron-ia:recover-routines', array_merge([
            'campaign' => self::CAMPANA, '--cutoff' => self::CORTE,
            '--report' => storage_path('framework/testing/recuperacion-'.uniqid().'.json'),
        ], $opciones));
    }

    private function asTrainer(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    /** @return list<int> ids de las rutinas que ve el socio en Mis Rutinas */
    private function rutinasDelSocio(Member $m): array
    {
        // La app recibe los ids como texto (contrato de RoutineResource): se comparan como enteros.
        return array_map('intval', array_column(
            $this->getJson('/api/app/routines/assigned', ['Authorization' => 'Bearer '.$m->access_hash])->assertOk()->json('data'),
            'id',
        ));
    }

    /**
     * Lo que el entrenador confirma con el socio para publicar una propuesta
     * (RECUPERACION-REVISION-CONTRATO.md §2): por defecto, lo que cuadra con ella.
     */
    private function confirmar(Routine $p, array $o = []): array
    {
        $p->refresh();
        $nivel = mb_strtolower((string) $p->level);
        $nivel = match (true) {
            str_contains($nivel, 'princip') => 'principiante',
            str_contains($nivel, 'avanz') => 'avanzado',
            default => 'intermedio',
        };

        return ['recovery_confirmation' => array_merge([
            'days_per_week' => (int) $p->days_per_week,
            'session_minutes' => 180,
            'training_level' => $nivel,
            'objective' => 'Perder grasa',
            'injuries' => 'Ninguna',
            'availability_confirmed' => true,
            'muscle_split_reviewed' => true,
        ], $o)];
    }

    private function propuestaDe(Routine $original): Routine
    {
        $item = IronIaRecoveryItem::where('original_routine_id', $original->id)->firstOrFail();

        return Routine::findOrFail($item->proposal_routine_id);
    }

    // ── Quién entra en la campaña ───────────────────────────────────────────

    public function test_la_simulacion_dice_quien_entra_y_por_que_sin_escribir_nada(): void
    {
        FakeIronIaOpenAi::install();
        $afectada = $this->rutinaIa($this->socio(), 4);
        $corregida = $this->rutinaIa($this->socio(), 4, ['context' => true]);
        $borrador = $this->rutinaIa($this->socio(), 3, ['visible' => false]);
        $seis = $this->rutinaIa($this->socio(), 6);
        $vencida = $this->rutinaIa($this->socio(['end' => '2026-11-10']), 4);
        $sinEntrenador = $this->rutinaIa($this->socio(['trainer' => null]), 4);
        $posterior = $this->socio();
        $sustituida = $this->rutinaIa($posterior, 4);
        $manual = Routine::create(['name' => 'Rutina a mano', 'member_id' => $posterior->id, 'trainer_id' => $this->trainer->id,
            'is_assigned' => true, 'is_template' => false, 'status' => 'Activa', 'days_per_week' => 5, 'generated_by_ai' => false]);
        $despues = $this->rutinaIa($this->socio(), 4, ['at' => '2026-11-14 12:00:00']);
        $this->socio(); // sin rutina: ni aparece

        $antes = [Routine::count(), NutritionAiRun::count()];
        $informe = storage_path('framework/testing/recuperacion-sim.json');
        $this->recuperar(['--report' => $informe])->expectsOutputToContain('SIMULACIÓN')->assertExitCode(0);

        $this->assertSame($antes, [Routine::count(), NutritionAiRun::count()]);
        $this->assertSame(0, IronIaRecoveryItem::count());
        Http::assertNothingSent();

        $motivos = array_column(json_decode(file_get_contents($informe), true)['evaluation'], 'reason', 'routine_id');
        @unlink($informe);
        $this->assertSame('eligible', $motivos[$afectada->id]);
        $this->assertSame('corrected_generation', $motivos[$corregida->id]);
        $this->assertSame('never_published', $motivos[$borrador->id]);
        $this->assertSame('already_six_days', $motivos[$seis->id]);
        $this->assertSame('membership_expired', $motivos[$vencida->id]);
        $this->assertSame('no_active_trainer', $motivos[$sinEntrenador->id]);
        $this->assertSame('superseded', $motivos[$sustituida->id]);
        $this->assertSame('after_cutoff', $motivos[$despues->id]);
        $this->assertArrayNotHasKey($manual->id, $motivos, 'una rutina hecha a mano no es de Iron IA');
    }

    public function test_varios_programas_del_mismo_socio_dan_una_sola_propuesta(): void
    {
        $socio = $this->socio();
        $vieja = $this->rutinaIa($socio, 3, ['at' => '2026-10-20 15:00:00']);
        $reciente = $this->rutinaIa($socio, 4, ['at' => '2026-11-05 15:00:00']);

        $motivos = array_column(app(RoutineRecoveryCampaign::class)->evaluate(self::CAMPANA, self::CORTE), 'reason', 'routine_id');

        $this->assertSame('superseded', $motivos[$vieja->id]);
        $this->assertSame('eligible', $motivos[$reciente->id]);
    }

    // ── La propuesta ────────────────────────────────────────────────────────

    public function test_la_propuesta_nace_en_borrador_marcada_y_el_socio_no_la_ve(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $item = IronIaRecoveryItem::sole();
        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, $item->status);
        $p = $this->propuestaDe($original);
        $this->assertSame('Borrador', $p->status);
        $this->assertFalse((bool) $p->is_assigned);
        $this->assertFalse(MemberRoutineAssignment::where('routine_id', $p->id)->exists());
        $this->assertTrue((bool) $p->generated_by_ai);
        $this->assertSame($this->trainer->id, (int) $p->trainer_id, 'la revisa y publica su entrenador activo');
        $this->assertSame(6, (int) $p->days_per_week);
        $this->assertStringStartsWith('Propuesta: 6 días por confirmar · ', $p->name);
        $this->assertSame(
            ['Día 1 · Empuje A', 'Día 2 · Tracción A', 'Día 3 · Pierna A', 'Día 4 · Empuje B', 'Día 5 · Tracción B', 'Día 6 · Pierna B'],
            array_column($p->days, 'name'),
        );

        // El socio sigue viendo SOLO la original; nadie le avisa de nada.
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));
        $this->assertSame(0, MemberRealtimeEvent::where('member_id', $socio->id)->count());

        // Solo la rutina: ninguna guía, y nada escrito en la meta del socio.
        $this->assertSame(0, NutritionGuide::count());
        $this->assertSame(0, NutritionGoal::count());

        // La corrida va aparte de las del entrenador y dice qué es.
        $run = NutritionAiRun::findOrFail($item->run_id);
        $this->assertSame(IntegralPlanGenerationService::RECOVERY_RUN_MODE, $run->mode);
        $this->assertSame(self::CAMPANA, $run->response_json['recovery']);
        $this->assertGreaterThanOrEqual(0.6, $run->response_json['muscle_review']['min_share']);

        // El modelo recibió los 6 días y el reparto muscular.
        $sistema = Http::recorded()->first()[0]->data()['messages'][0]['content'];
        $this->assertStringContainsString('EXACTAMENTE 6 días', $sistema);
        $this->assertStringContainsString('Día 1 · Empuje A (pecho, hombro y tríceps)', $sistema);
    }

    public function test_una_meta_de_seis_dias_tampoco_publica_sola(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        NutritionGoal::create(['member_id' => $socio->id, 'daily_calories' => 2000, 'protein_g' => 120, 'carbs_g' => 220,
            'fat_g' => 60, 'training_days_per_week' => 6, 'is_active' => true, 'source' => 'calculated']);
        $original = $this->rutinaIa($socio, 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $this->assertSame('Borrador', $this->propuestaDe($original)->status);
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));
    }

    public function test_un_principiante_recibe_una_propuesta_de_su_nivel_para_revisar(): void
    {
        FakeIronIaOpenAi::install();
        $original = $this->rutinaIa($this->socio(['level' => 'principiante']), 3);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $p = $this->propuestaDe($original);
        $this->assertSame('Borrador', $p->status);
        $this->assertSame(6, (int) $p->days_per_week);
        $usuario = json_decode(Http::recorded()->first()[0]->data()['messages'][1]['content'], true);
        $this->assertSame('principiante', $usuario['member']['training']['training_level']);
    }

    public function test_las_lesiones_del_perfil_llegan_al_modelo(): void
    {
        FakeIronIaOpenAi::install();
        $this->rutinaIa($this->socio(['injuries' => 'Lesión de rodilla derecha']), 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $usuario = json_decode(Http::recorded()->first()[0]->data()['messages'][1]['content'], true);
        $this->assertSame('Lesión de rodilla derecha', $usuario['member']['training']['injuries']);
    }

    public function test_con_potencia_de_rutina_3_reparte_por_secciones_y_no_toca_las_potencias(): void
    {
        $this->powers(5, 3);
        FakeIronIaOpenAi::install();
        $original = $this->rutinaIa($this->socio(), 4, ['power' => 3]);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $p = $this->propuestaDe($original);
        $this->assertSame(6, (int) $p->days_per_week);
        $run = NutritionAiRun::findOrFail(IronIaRecoveryItem::sole()->run_id);
        $this->assertSame([1, 3], [(int) $run->nutrition_power, (int) $run->routine_power], 'la rutina con su potencia; la nutrición, que no se guarda, al mínimo');
        $cap = MembershipAiCapability::where('membership_plan_id', $this->plan->id)->firstOrFail();
        $this->assertSame([5, 3], [(int) $cap->nutrition_power, (int) $cap->routine_power], 'las potencias del plan no cambian');
        $this->assertSame(0, NutritionGuide::count());
    }

    // ── Reintentos, duplicados, cortes y concurrencia ───────────────────────

    public function test_un_fallo_se_reintenta_y_no_pasa_de_tres_intentos(): void
    {
        // El modelo responde algo inservible (sin días): la validación lo rechaza, como en producción.
        FakeIronIaOpenAi::install(function (string $schema, array $user, array $content) {
            $content['routine']['days'] = [];

            return ['content' => $content];
        });
        $original = $this->rutinaIa($this->socio(), 4);

        foreach ([1, 2, 3] as $intento) {
            $this->recuperar(['--execute' => true])->assertExitCode(0);
            $item = IronIaRecoveryItem::sole();
            $this->assertSame(IronIaRecoveryItem::STATUS_FAILED, $item->status);
            $this->assertSame($intento, $item->attempts);
        }

        $llamadas = count(Http::recorded());
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $this->assertSame($llamadas, count(Http::recorded()), 'agotados los intentos, no se vuelve a pagar');
        $this->assertNull(IronIaRecoveryItem::sole()->proposal_routine_id);
        $this->assertSame(1, Routine::count(), 'ningún borrador a medias');
        $this->assertTrue(Routine::whereKey($original->id)->exists());
    }

    public function test_un_fallo_seguido_de_un_acierto_deja_la_propuesta(): void
    {
        $llamadas = 0;
        FakeIronIaOpenAi::install(function (string $schema, array $user, array $content) use (&$llamadas) {
            if (++$llamadas > 1) {
                return null;
            }
            $content['routine']['days'] = [];

            return ['content' => $content];
        });
        $this->rutinaIa($this->socio(), 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $item = IronIaRecoveryItem::sole();
        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, $item->status);
        $this->assertSame(2, $item->attempts);
    }

    public function test_lanzarla_dos_veces_no_duplica_ni_cobra_dos_veces(): void
    {
        FakeIronIaOpenAi::install();
        $this->rutinaIa($this->socio(), 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $llamadas = count(Http::recorded());
        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $this->assertSame(1, IronIaRecoveryItem::count());
        $this->assertSame(1, Routine::where('status', 'Borrador')->count());
        $this->assertSame($llamadas, count(Http::recorded()));
    }

    public function test_una_ejecucion_cortada_adopta_su_borrador_sin_volver_a_pagar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);

        // La ejecución anterior reclamó la fila, generó y guardó… y se cortó antes de marcarla.
        Carbon::setTestNow(Carbon::parse('2026-11-14 14:00:00', 'UTC'));
        $item = IronIaRecoveryItem::create([
            'campaign' => self::CAMPANA, 'original_routine_id' => $original->id, 'member_id' => $socio->id,
            'trainer_id' => $this->trainer->id, 'status' => IronIaRecoveryItem::STATUS_GENERATING, 'proposed_days' => 6,
            'attempts' => 1, 'claimed_at' => now(),
        ]);
        $run = NutritionAiRun::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $socio->id, 'mode' => IntegralPlanGenerationService::RECOVERY_RUN_MODE,
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'status' => 'ok', 'prompt_version' => 'v1',
            'nutrition_power' => 1, 'routine_power' => 1, 'total_tokens' => 9000, 'response_json' => ['recovery' => self::CAMPANA],
        ]);
        $huerfana = Routine::create(['name' => 'Rutina 6 días', 'member_id' => $socio->id, 'trainer_id' => $this->trainer->id,
            'is_assigned' => false, 'is_template' => false, 'status' => 'Borrador', 'days_per_week' => 6, 'generated_by_ai' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-14 15:00:00', 'UTC'));

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $item->refresh();
        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, $item->status);
        $this->assertSame($huerfana->id, (int) $item->proposal_routine_id);
        $this->assertSame($run->id, (int) $item->run_id);
        $this->assertStringStartsWith('Propuesta: 6 días por confirmar · ', $huerfana->refresh()->name);
    }

    public function test_dos_ejecuciones_a_la_vez_no_se_pisan(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);

        // Otra ejecución tiene el cerrojo de la campaña: esta no empieza.
        $cerrojo = Cache::lock('iron-ia-recovery:'.self::CAMPANA, 60);
        $this->assertTrue($cerrojo->get());
        $this->recuperar(['--execute' => true])->expectsOutputToContain('Ya hay una ejecución')->assertExitCode(1);
        $cerrojo->release();

        // Y aunque alguien la saltara: una fila reclamada hace un momento no se vuelve a reclamar.
        IronIaRecoveryItem::create([
            'campaign' => self::CAMPANA, 'original_routine_id' => $original->id, 'member_id' => $socio->id,
            'trainer_id' => $this->trainer->id, 'status' => IronIaRecoveryItem::STATUS_GENERATING, 'proposed_days' => 6,
            'attempts' => 1, 'claimed_at' => now(),
        ]);
        $res = app(RoutineRecoveryCampaign::class)->process(self::CAMPANA, $original->id, self::CORTE);

        $this->assertSame('not_claimed', $res['outcome']);
        Http::assertNothingSent();
    }

    public function test_por_tandas_con_limite_y_se_reanuda(): void
    {
        FakeIronIaOpenAi::install();
        $this->rutinaIa($this->socio(), 4);
        $this->rutinaIa($this->socio(), 3);

        $this->recuperar(['--execute' => true, '--limit' => 1])->assertExitCode(0);
        $this->assertSame(1, IronIaRecoveryItem::where('status', IronIaRecoveryItem::STATUS_PROPOSED)->count());

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $this->assertSame(2, IronIaRecoveryItem::where('status', IronIaRecoveryItem::STATUS_PROPOSED)->count());
    }

    public function test_el_tope_de_tokens_para_la_campana(): void
    {
        FakeIronIaOpenAi::install();
        $this->rutinaIa($this->socio(), 4);
        $this->rutinaIa($this->socio(), 3);

        $this->recuperar(['--execute' => true, '--limit' => 1])->assertExitCode(0);
        $this->recuperar(['--execute' => true, '--max-tokens' => 1])->expectsOutputToContain('Tope de 1 tokens')->assertExitCode(0);

        $this->assertSame(1, IronIaRecoveryItem::count());
    }

    // ── Publicar, ajustar o descartar: el entrenador decide ─────────────────

    public function test_publicar_la_hace_vigente_y_retira_la_original_sin_borrar_historia(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $completado = RoutineCompletion::create(['member_id' => $socio->id, 'routine_id' => $original->id, 'completed_at' => now()->subDays(2), 'source' => 'app']);
        WorkoutSession::create(['member_id' => $socio->id, 'routine_id' => $original->id, 'routine_completion_id' => $completado->id,
            'client_session_id' => (string) Str::uuid(), 'routine_name' => $original->name, 'started_at' => now()->subDays(2)->subHour(),
            'completed_at' => now()->subDays(2), 'duration_seconds' => 3600, 'total_sets' => 12, 'total_exercises' => 4, 'source' => 'app']);
        $ejerciciosOriginal = RoutineExercise::where('routine_id', $original->id)->count();

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $base = IronIaRecoveryItem::sole()->proposal_base_name;

        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['session_minutes' => 60]), $this->asTrainer())->assertOk();

        $p->refresh();
        $this->assertTrue((bool) $p->is_assigned);
        $this->assertSame('Activa', $p->status);
        $this->assertSame($base, $p->name, 'publicar es confirmar: la marca «por confirmar» desaparece');

        $original->refresh();
        $this->assertSame('Retirada', $original->status);
        $this->assertFalse((bool) $original->is_assigned);
        $this->assertSame($ejerciciosOriginal, RoutineExercise::where('routine_id', $original->id)->count());
        $this->assertSame(1, WorkoutSession::where('routine_id', $original->id)->count());
        $this->assertSame(1, RoutineCompletion::where('routine_id', $original->id)->count());

        $item = IronIaRecoveryItem::sole();
        $this->assertSame(IronIaRecoveryItem::STATUS_PUBLISHED, $item->status);
        $this->assertSame($this->trainer->id, (int) $item->published_by_trainer_id);
        $this->assertNotNull($item->original_retired_at);

        // El socio ve la nueva, no la anterior, y su app recibe el aviso de siempre.
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));
        $this->assertTrue(MemberRealtimeEvent::where('member_id', $socio->id)->where('type', RealtimeEvents::ROUTINE)->exists());

        // Lo que confirmó queda guardado con la sustitución.
        $this->assertSame(6, $item->confirmation['days_per_week']);
        $this->assertSame(60, $item->confirmation['session_minutes']);
        $this->assertTrue($item->confirmation['availability_confirmed']);
        $this->assertNotNull($item->confirmed_at);

        // Publicar otra vez (ya no es una propuesta pendiente: sin confirmación) no hace una segunda sustitución.
        $publicada = $item->published_at;
        $this->postJson("/api/trainer/routines/{$p->id}/publish", [], $this->asTrainer())->assertOk();
        $this->assertEquals($publicada, IronIaRecoveryItem::sole()->published_at);
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));
    }

    public function test_si_el_socio_puede_menos_dias_el_entrenador_ajusta_antes_de_publicar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 3);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        // Confirmó 4 días con el socio: quita los dos últimos en el editor de siempre,
        // como la app: con el `day` que manda el servidor en cada fila.
        $filas = $this->getJson("/api/trainer/routines/{$p->id}", $this->asTrainer())->assertOk()->json('data.exercises');
        $nombres = array_map(fn ($d) => (string) $d['name'], (array) $p->days);
        $this->assertCount(6, $nombres);
        $this->assertSame(array_sum(array_map(fn ($d) => count((array) $d['exercises']), (array) $p->days)), count($filas));
        $this->assertNotContains(null, array_column($filas, 'day'), 'cada fila lleva su día');
        $this->assertSame($nombres, array_values(array_unique(array_column($filas, 'day'))));

        // Guardar sin tocar nada conserva los 6 días (antes quedaba en 1).
        $tal = array_map(fn ($e) => ['id' => $e['id'], 'exercise_id' => $e['exercise_id'], 'sets' => $e['sets'], 'reps' => $e['reps'], 'day' => $e['day']], $filas);
        $this->putJson("/api/trainer/routines/{$p->id}/exercises", ['exercises' => $tal], $this->asTrainer())->assertOk();
        $this->assertSame(6, (int) $p->refresh()->days_per_week);

        $cuerpo = array_values(array_filter($tal, fn ($e) => in_array($e['day'], array_slice($nombres, 0, 4), true)));
        $this->putJson("/api/trainer/routines/{$p->id}/exercises", ['exercises' => $cuerpo], $this->asTrainer())->assertOk();
        $this->assertSame(4, (int) $p->refresh()->days_per_week);

        // Con los 6 de la propuesta ya no cuadra; con los 4 confirmados, sí.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['days_per_week' => 6]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_days_mismatch');
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['days_per_week' => 4]), $this->asTrainer())->assertOk();

        $this->assertSame(4, (int) $p->refresh()->days_per_week);
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));
    }

    public function test_descartar_la_propuesta_deja_la_original_como_estaba(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        $this->postJson("/api/trainer/routines/{$p->id}/retire", [], $this->asTrainer())->assertOk();

        $this->assertSame(IronIaRecoveryItem::STATUS_DISCARDED, IronIaRecoveryItem::sole()->status);
        $this->assertSame('Activa', $original->refresh()->status);
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));
    }

    // ── El entrenador sigue trabajando como siempre ─────────────────────────

    public function test_la_recuperacion_no_gasta_los_cupos_del_entrenador(): void
    {
        config(['iron_ai.plan_powers.trainer_max_per_window' => 1, 'iron_ai.plan_powers.member_daily_generations' => 1]);
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $this->rutinaIa($socio, 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $this->assertSame(1, IronIaRecoveryItem::where('status', IronIaRecoveryItem::STATUS_PROPOSED)->count());

        // Con 1 generación por ventana y 1 por socio y día, el entrenador puede generar igual.
        $this->postJson("/api/trainer/members/{$socio->id}/integral-plan/generate", ['days_per_week' => 4], $this->asTrainer())->assertStatus(201);
    }

    public function test_una_valoracion_nueva_y_su_rutina_sustituyen_a_la_recuperacion_como_siempre(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $recuperada = $this->propuestaDe($original);
        $this->postJson("/api/trainer/routines/{$recuperada->id}/publish", $this->confirmar($recuperada), $this->asTrainer())->assertOk();

        // Nueva valoración y nueva generación con el formulario: nada lo bloquea.
        $valoracion = ProfessionalAssessment::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $socio->id, 'trainer_id' => $this->trainer->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED, 'version' => 1, 'weight_kg' => 68, 'height_cm' => 165,
            'body_fat_pct' => 28, 'submitted_at' => now(),
        ]);
        $nueva = $this->postJson("/api/trainer/members/{$socio->id}/integral-plan/generate", ['days_per_week' => 5], $this->asTrainer())
            ->assertStatus(201)->json('data.routine.id');
        $this->assertSame($valoracion->id, (int) Routine::findOrFail($nueva)->source_assessment_id);
        $this->assertSame(5, (int) Routine::findOrFail($nueva)->days_per_week, 'los días no se quedaron en 6');

        // Se publica con el botón de siempre y la de recuperación se retira con el de siempre.
        $this->postJson("/api/trainer/routines/{$nueva}/publish", [], $this->asTrainer())->assertOk();
        $this->postJson("/api/trainer/routines/{$recuperada->id}/retire", [], $this->asTrainer())->assertOk();
        $this->assertSame([$nueva], $this->rutinasDelSocio($socio));
        $this->assertSame(IronIaRecoveryItem::STATUS_PUBLISHED, IronIaRecoveryItem::sole()->status, 'retirar después no reescribe lo que pasó');
    }

    public function test_la_api_no_permite_marcar_una_generacion_como_recuperacion(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();

        $this->postJson("/api/trainer/members/{$socio->id}/integral-plan/generate",
            ['days_per_week' => 4, 'recovery_campaign' => 'colada', 'recovery_confirmed' => true], $this->asTrainer())->assertStatus(201);

        $run = NutritionAiRun::sole();
        $this->assertSame(IntegralPlanGenerationService::RUN_MODE, $run->mode);
        $this->assertArrayNotHasKey('recovery', $run->response_json);
        $this->assertNotContains('recovery_campaign', $run->response_json['trainer_context']['received'] ?? []);
        $this->assertNotContains('recovery_confirmed', $run->response_json['trainer_context']['received'] ?? []);
        $this->assertSame(0, IronIaRecoveryItem::count(), 'ninguna fila de campaña');
        $this->assertSame(1, NutritionGuide::count(), 'una generación normal guarda su guía');
    }

    public function test_el_portal_y_la_app_reciben_la_misma_forma_de_siempre(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        $lista = $this->getJson("/api/trainer/members/{$socio->id}/routines", $this->asTrainer())->assertOk()->json('data');
        $claves = array_map(fn ($r) => array_keys($r), $lista);
        $this->assertSame($claves[0], $claves[1], 'la propuesta y la original tienen las mismas claves');
        // Una sola clave nueva, `recovery`, en todas: null en la original.
        $this->assertSame(
            ['id', 'name', 'objective', 'level', 'days_per_week', 'status', 'is_assigned', 'published', 'generated_by_ai', 'source_assessment_id', 'exercises_count', 'days', 'recovery'],
            $claves[0],
        );
        $porId = array_column($lista, null, 'id');
        $this->assertNull($porId[$original->id]['recovery']);
        $this->assertSame([
            'campaign' => self::CAMPANA, 'status' => 'proposed', 'needs_confirmation' => true, 'proposed_days' => 6,
            'original_routine_id' => $original->id, 'original_days' => 4, 'regenerations_left' => 3,
        ], $porId[$p->id]['recovery']);

        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();
        $app = $this->getJson('/api/app/routines/assigned', ['Authorization' => 'Bearer '.$socio->access_hash])->assertOk()->json('data.0');
        $this->assertSame($p->id, (int) $app['id']);
        $this->assertArrayHasKey('days', $app);
    }

    // ── Lo que señalaron las revisiones de seguridad y final ───────────────

    public function test_el_cupo_de_nutricion_de_los_socios_no_cambia(): void
    {
        config(['nutrition.ai.daily_cost_guard' => 1, 'nutrition.ai.rate_limit_per_user' => 1]);
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $this->rutinaIa($socio, 4);
        $guardian = app(NutritionAiCostGuard::class);
        $this->assertTrue($guardian->check($socio)['allowed']);

        $this->recuperar(['--execute' => true])->assertExitCode(0);

        $this->assertSame(1, IronIaRecoveryItem::where('status', IronIaRecoveryItem::STATUS_PROPOSED)->count());
        $this->assertSame(['allowed' => true, 'reason' => null], $guardian->check($socio), 'la recuperación no gasta la IA de nutrición del socio');
    }

    public function test_con_cola_cada_propuesta_lleva_el_tope_y_no_se_genera_aqui(): void
    {
        Queue::fake();
        FakeIronIaOpenAi::install();
        $original = $this->rutinaIa($this->socio(), 4);

        // Una cola sin carril (sin worker en producción) se rechaza antes de tocar nada.
        $this->recuperar(['--execute' => true, '--queue' => 'default'])->assertExitCode(2);
        Queue::assertNothingPushed();
        $this->assertSame(0, IronIaRecoveryItem::count());

        $this->recuperar(['--execute' => true, '--queue' => 'commercial', '--max-tokens' => 500000])->assertExitCode(0);

        Http::assertNothingSent();
        Queue::assertPushedOn('commercial', GenerateRecoveryProposal::class,
            fn (GenerateRecoveryProposal $j) => $j->originalRoutineId === $original->id && $j->maxTokens === 500000 && $j->campaign === self::CAMPANA
                && $j->connection === 'commercial');
        // Y por defecto el job ya va a su carril, nunca a `default`.
        $this->assertSame(['commercial', 'commercial'], [(new GenerateRecoveryProposal('x', 1, self::CORTE))->connection, (new GenerateRecoveryProposal('x', 1, self::CORTE))->queue]);
    }

    public function test_el_tope_cuenta_tambien_los_intentos_fallidos(): void
    {
        FakeIronIaOpenAi::install(function (string $schema, array $user, array $content) {
            $content['routine']['days'] = [];

            return ['content' => $content];
        });
        $original = $this->rutinaIa($this->socio(), 4);

        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $gastado = app(RoutineRecoveryCampaign::class)->tokensSpent(self::CAMPANA);
        $this->assertGreaterThan(0, $gastado, 'un intento inválido también se paga');

        $llamadas = count(Http::recorded());
        $res = app(RoutineRecoveryCampaign::class)->process(self::CAMPANA, $original->id, self::CORTE, $gastado);
        $this->assertSame(['routine_id' => $original->id, 'outcome' => 'skipped', 'reason' => 'token_cap'], $res);
        $this->assertSame($llamadas, count(Http::recorded()));
    }

    public function test_publicar_una_propuesta_descartada_la_sustituye_igual(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $this->postJson("/api/trainer/routines/{$p->id}/retire", [], $this->asTrainer())->assertOk();

        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();

        $this->assertSame(IronIaRecoveryItem::STATUS_PUBLISHED, IronIaRecoveryItem::sole()->status);
        $this->assertStringStartsNotWith('Propuesta:', $p->refresh()->name);
        $this->assertSame('Retirada', $original->refresh()->status);
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));
    }

    public function test_un_nombre_cambiado_a_mano_se_respeta_al_publicar(): void
    {
        FakeIronIaOpenAi::install();
        $original = $this->rutinaIa($this->socio(), 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $p->update(['name' => 'Rutina de Ana, 6 días']);

        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();

        $this->assertSame('Rutina de Ana, 6 días', $p->refresh()->name);
    }

    public function test_el_corte_es_el_dia_de_bogota(): void
    {
        $dentro = $this->rutinaIa($this->socio(), 4, ['at' => '2026-11-14 03:00:00']); // 13-11, 22:00 en Bogotá
        $fuera = $this->rutinaIa($this->socio(), 4, ['at' => '2026-11-14 05:30:00']);  // 14-11, 00:30 en Bogotá

        $motivos = array_column(app(RoutineRecoveryCampaign::class)->evaluate(self::CAMPANA, self::CORTE), 'reason', 'routine_id');

        $this->assertSame('eligible', $motivos[$dentro->id]);
        $this->assertSame('after_cutoff', $motivos[$fuera->id]);
    }

    public function test_otra_campana_no_vuelve_a_tratar_la_misma_rutina(): void
    {
        FakeIronIaOpenAi::install();
        $original = $this->rutinaIa($this->socio(), 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $llamadas = count(Http::recorded());

        $motivos = array_column(app(RoutineRecoveryCampaign::class)->evaluate('recuperacion-mal-escrita', self::CORTE), 'reason', 'routine_id');
        $this->assertSame('in_other_campaign', $motivos[$original->id]);
        $this->recuperar(['campaign' => 'recuperacion-mal-escrita', '--execute' => true])->assertExitCode(0);
        $this->assertSame($llamadas, count(Http::recorded()));
        $this->assertSame(1, Routine::where('status', 'Borrador')->count());
    }

    public function test_el_socio_no_puede_borrar_las_rutinas_de_su_entrenador(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $propia = Routine::create(['name' => 'Mi rutina', 'member_id' => $socio->id, 'is_assigned' => false, 'is_template' => false, 'status' => 'Activa', 'days_per_week' => 2]);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $auth = ['Authorization' => 'Bearer '.$socio->access_hash];

        // La propuesta en borrador: no es suya.
        $this->deleteJson("/api/app/routines/{$p->id}", [], $auth)->assertForbidden();

        // La original, ya retirada tras publicar: tampoco; su historial se queda.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();
        $this->deleteJson("/api/app/routines/{$original->id}", [], $auth)->assertForbidden();
        $this->assertTrue(Routine::whereKey($original->id)->exists());
        $this->assertGreaterThan(0, RoutineExercise::where('routine_id', $original->id)->count());

        // La que creó él sí la puede borrar, como siempre.
        $this->deleteJson("/api/app/routines/{$propia->id}", [], $auth)->assertOk();
        $this->assertFalse(Routine::whereKey($propia->id)->exists());
    }

    // ── Revisión del entrenador antes de publicar (RECUPERACION-REVISION-CONTRATO.md) ──

    /** Nada cambió: la propuesta sigue en borrador y el socio ve su original. */
    private function nadaPublicado(Member $socio, Routine $original, Routine $p): void
    {
        $this->assertFalse((bool) $p->refresh()->is_assigned);
        $this->assertSame('Activa', $original->refresh()->status);
        $this->assertSame(IronIaRecoveryItem::STATUS_PROPOSED, IronIaRecoveryItem::sole()->status);
        $this->assertNull(IronIaRecoveryItem::sole()->confirmation);
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));
    }

    public function test_publicar_una_propuesta_sin_confirmar_no_publica_nada(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        // La 2.0.10 sin parche publica sin cuerpo: se rechaza con un motivo legible.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", [], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_confirmation_required')->assertJsonPath('ok', false);
        // Mirar los seis días no basta: las dos casillas tienen que estar marcadas.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['availability_confirmed' => false]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_confirmation_required');
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['muscle_split_reviewed' => false]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_confirmation_required');
        // Faltan datos: validación de Laravel.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", ['recovery_confirmation' => ['days_per_week' => 6]], $this->asTrainer())
            ->assertStatus(422)->assertJsonValidationErrors(['recovery_confirmation.session_minutes', 'recovery_confirmation.training_level', 'recovery_confirmation.objective', 'recovery_confirmation.injuries']);

        $this->nadaPublicado($socio, $original, $p);
    }

    public function test_los_dias_el_nivel_y_los_minutos_tienen_que_cuadrar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $this->assertSame(6, (int) $p->days_per_week);

        $r = $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['days_per_week' => 4]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_days_mismatch');
        $this->assertSame('La rutina tiene 6 días y el socio puede 4: ajústala en el editor o regénerala con 4 días.', $r->json('message'));

        $otro = $this->confirmar($p)['recovery_confirmation']['training_level'] === 'avanzado' ? 'principiante' : 'avanzado';
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['training_level' => $otro]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_level_mismatch');

        $r = $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['session_minutes' => 20]), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_minutes_mismatch');
        $this->assertStringContainsString('el socio tiene 20', $r->json('message'));

        $this->nadaPublicado($socio, $original, $p);

        // Siete días confirmados caben en una rutina de seis (un día de descanso).
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['days_per_week' => 7]), $this->asTrainer())->assertOk();
        $this->assertSame(7, IronIaRecoveryItem::sole()->confirmation['days_per_week']);
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));
    }

    public function test_regenerar_con_lo_confirmado_deja_una_propuesta_nueva_para_revisar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $corridas = NutritionAiRun::count();

        $r = $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", [
            'days_per_week' => 4, 'session_minutes' => 45, 'training_level' => ' Intermedio ',
            'objective' => 'Perder  grasa', 'injuries' => 'Rodilla derecha operada', 'observations' => 'Prefiere máquinas',
        ], $this->asTrainer())->assertStatus(201)->assertJsonPath('ok', true);

        $nueva = Routine::findOrFail($r->json('data.id'));
        $this->assertNotSame($p->id, $nueva->id);
        $this->assertSame(4, (int) $nueva->days_per_week);
        $this->assertStringStartsWith('Propuesta: 4 días por confirmar · ', $nueva->name);
        $this->assertFalse((bool) $nueva->is_assigned, 'regenerar no publica');
        $this->assertSame($this->trainer->id, (int) $nueva->trainer_id);
        $this->assertSame(['campaign' => self::CAMPANA, 'status' => 'proposed', 'needs_confirmation' => true, 'proposed_days' => 4,
            'original_routine_id' => $original->id, 'original_days' => 4, 'regenerations_left' => 2], $r->json('data.recovery'));

        // La anterior queda retirada, no borrada; la original sigue siendo la del socio.
        $this->assertSame('Retirada', $p->refresh()->status);
        $this->assertTrue(Routine::whereKey($p->id)->exists());
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));

        // Y ya no se publica por ninguna vía: ni el entrenador, ni la administración, ni se regenera.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_superseded');
        $this->postJson("/api/trainer/routines/{$p->id}/publish", [], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_superseded');
        $this->patchJson("/api/routines/{$p->id}/assign", ['assignedMemberId' => $socio->id], $this->adminHeaders())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_publish_from_trainer');
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", [
            'days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'x', 'injuries' => 'Ninguna',
        ], $this->asTrainer())->assertNotFound();
        $this->assertSame([$original->id], $this->rutinasDelSocio($socio));
        $lista = array_column($this->getJson("/api/trainer/members/{$socio->id}/routines", $this->asTrainer())->assertOk()->json('data'), null, 'id');
        $this->assertSame('superseded', $lista[$p->id]['recovery']['status']);
        $this->assertFalse($lista[$p->id]['recovery']['needs_confirmation']);
        $this->assertSame($nueva->id, $lista[$p->id]['recovery']['replaced_by_routine_id']);
        $this->assertSame('proposed', $lista[$nueva->id]['recovery']['status']);

        // La corrida va por la campaña, con los días como disponibilidad confirmada.
        $this->assertSame($corridas + 1, NutritionAiRun::count());
        $run = NutritionAiRun::latest('id')->first();
        $this->assertSame(IntegralPlanGenerationService::RECOVERY_RUN_MODE, $run->mode);
        $this->assertSame(self::CAMPANA, $run->response_json['recovery'] ?? null);
        $this->assertNotContains('recovery_confirmed', $run->response_json['trainer_context']['received'] ?? []);
        $this->assertSame((int) $run->id, (int) IronIaRecoveryItem::sole()->run_id);

        // Y se publica con los mismos datos confirmados: la original se retira.
        $this->postJson("/api/trainer/routines/{$nueva->id}/publish", $this->confirmar($nueva, ['days_per_week' => 4, 'session_minutes' => 180]), $this->asTrainer())->assertOk();
        $this->assertSame('Retirada', $original->refresh()->status);
        $this->assertSame([$nueva->id], $this->rutinasDelSocio($socio));
        $this->assertSame(IronIaRecoveryItem::STATUS_PUBLISHED, IronIaRecoveryItem::sole()->status);

        // Publicada, ya no se regenera.
        $this->postJson("/api/trainer/routines/{$nueva->id}/recovery/regenerate", [
            'days_per_week' => 3, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'x', 'injuries' => 'Ninguna',
        ], $this->asTrainer())->assertStatus(422)->assertJsonPath('code', 'recovery_not_pending');
    }

    public function test_regenerar_tiene_tope_dueno_y_solo_vale_para_propuestas(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $cuerpo = ['days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'Salud', 'injuries' => 'Ninguna'];

        // Validación: lo mismo que el formulario, pero obligatorio.
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", ['days_per_week' => 9], $this->asTrainer())
            ->assertStatus(422)->assertJsonValidationErrors(['days_per_week', 'session_minutes', 'training_level', 'objective', 'injuries']);

        // Una rutina normal no es una propuesta.
        $this->postJson("/api/trainer/routines/{$original->id}/recovery/regenerate", $cuerpo, $this->asTrainer())->assertNotFound();

        // Otro entrenador no la toca.
        $p->update(['trainer_id' => Trainer::create(['full_name' => 'Otro', 'document' => 'T-otro', 'status' => 'active'])->id]);
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", $cuerpo, $this->asTrainer())->assertForbidden();
        $p->update(['trainer_id' => $this->trainer->id]);

        // Sin regeneraciones: 429 y sin llamar al modelo.
        IronIaRecoveryItem::query()->update(['regenerations' => IronIaRecoveryItem::MAX_REGENERATIONS]);
        $corridas = NutritionAiRun::count();
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", $cuerpo, $this->asTrainer())
            ->assertStatus(429)->assertJsonPath('code', 'recovery_regeneration_limit');
        $this->assertSame($corridas, NutritionAiRun::count());
        $this->assertSame((int) $p->id, (int) IronIaRecoveryItem::sole()->proposal_routine_id);
    }

    public function test_un_fallo_al_regenerar_conserva_la_propuesta_anterior(): void
    {
        // El modelo responde algo inservible (sin días) solo al regenerar.
        $fallar = false;
        FakeIronIaOpenAi::install(function (string $schema, array $user, array $content) use (&$fallar) {
            if ($fallar) {
                $content['routine']['days'] = [];
            }

            return ['content' => $content];
        });
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        $fallar = true;
        $r = $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", [
            'days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'Salud', 'injuries' => 'Ninguna',
        ], $this->asTrainer())->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('retryable', true);
        $this->assertStringStartsWith('ai_', (string) $r->json('code'));

        $item = IronIaRecoveryItem::sole();
        $this->assertSame((int) $p->id, (int) $item->proposal_routine_id);
        $this->assertSame(0, (int) $item->regenerations, 'un fallo no gasta una regeneración');
        $this->assertSame(1, (int) $item->regeneration_attempts, 'pero sí un intento: se paga igual');
        $this->assertSame(3, $item->toApi()['regenerations_left']);
        $this->assertNotSame('Retirada', $p->refresh()->status);
    }

    public function test_repetir_una_regeneracion_que_falla_tiene_tope_de_gasto(): void
    {
        // Revisión de seguridad del 13-11: sin tope de intentos, repetir un fallo era gasto sin límite.
        $fallar = false;
        FakeIronIaOpenAi::install(function (string $schema, array $user, array $content) use (&$fallar) {
            if ($fallar) {
                $content['routine']['days'] = [];
            }

            return ['content' => $content];
        });
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $cuerpo = ['days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'Salud', 'injuries' => 'Ninguna'];

        $fallar = true;
        for ($i = 1; $i <= IronIaRecoveryItem::MAX_REGENERATION_ATTEMPTS; $i++) {
            $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", $cuerpo, $this->asTrainer())->assertStatus(422);
        }
        $llamadas = count(Http::recorded());
        $corridas = NutritionAiRun::count();

        // Agotados los intentos: ni fallando ni acertando se vuelve a llamar al modelo.
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", $cuerpo, $this->asTrainer())
            ->assertStatus(429)->assertJsonPath('code', 'recovery_regeneration_limit');
        $fallar = false;
        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", $cuerpo, $this->asTrainer())
            ->assertStatus(429)->assertJsonPath('code', 'recovery_regeneration_limit');
        $this->assertSame($llamadas, count(Http::recorded()));
        $this->assertSame($corridas, NutritionAiRun::count());

        $item = IronIaRecoveryItem::sole();
        $this->assertSame(IronIaRecoveryItem::MAX_REGENERATION_ATTEMPTS, (int) $item->regeneration_attempts);
        $this->assertSame(0, (int) $item->regenerations);
        $this->assertSame((int) $p->id, (int) $item->proposal_routine_id, 'la propuesta sigue siendo la misma');
        $this->assertSame(0, $item->toApi()['regenerations_left']);

        // Sigue pudiendo publicarla tal cual (o ajustarla en el editor).
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();
    }

    public function test_regenerar_pide_tambien_la_llave_de_generar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        // Un rol que publica rutinas pero no genera planes: no regenera (y no llama al modelo).
        $permisos = config('trainer.permissions');
        $rol = $this->trainer->role ?? TrainerRole::FLOOR;
        $permisos[$rol] = array_values(array_diff((array) ($permisos[$rol] ?? []), ['plans.generate']));
        config(['trainer.permissions' => $permisos]);
        $llamadas = count(Http::recorded());

        $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", [
            'days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'Salud', 'injuries' => 'Ninguna',
        ], $this->asTrainer())->assertForbidden();
        $this->assertSame($llamadas, count(Http::recorded()));
        $this->assertSame(0, (int) IronIaRecoveryItem::sole()->regeneration_attempts);

        // Publicar (routines.assign) sigue funcionando.
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p), $this->asTrainer())->assertOk();
    }

    public function test_el_crm_no_publica_una_propuesta_pendiente(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        $this->patchJson("/api/routines/{$p->id}/assign", ['assignedMemberId' => $socio->id], $this->adminHeaders())
            ->assertStatus(422)->assertJsonPath('code', 'recovery_publish_from_trainer');
        $this->nadaPublicado($socio, $original, $p);

        // Una rutina normal se sigue asignando como siempre.
        $this->patchJson("/api/routines/{$original->id}/assign", ['assignedMemberId' => $socio->id], $this->adminHeaders())->assertOk();
    }

    public function test_mover_un_ejercicio_por_encima_del_limite_de_su_dia_no_le_cambia_el_dia(): void
    {
        // Revisión del punto F (13-11): el editor sube o baja ejercicios conservando su
        // `day`. Si uno de «Día 2» queda por encima del último de «Día 1», el orden de las
        // filas ya no coincidía con la agrupación por días y, al reabrir, el día que manda
        // el servidor (por posición) era otro.
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);

        $filas = $this->getJson("/api/trainer/routines/{$p->id}", $this->asTrainer())->assertOk()->json('data.exercises');
        $diaDe = fn (array $lista) => array_combine(array_column($lista, 'id'), array_column($lista, 'day'));
        $antes = $diaDe($filas);
        $nombres = array_values(array_unique(array_column($filas, 'day')));

        // El primero del segundo día sube por encima del último del primero (lo que hace «subir»).
        $primeroDel2 = array_search($nombres[1], array_column($filas, 'day'), true);
        $cuerpo = array_map(fn ($e) => ['id' => $e['id'], 'exercise_id' => $e['exercise_id'], 'sets' => $e['sets'], 'reps' => $e['reps'], 'day' => $e['day']], $filas);
        [$cuerpo[$primeroDel2 - 1], $cuerpo[$primeroDel2]] = [$cuerpo[$primeroDel2], $cuerpo[$primeroDel2 - 1]];
        $this->putJson("/api/trainer/routines/{$p->id}/exercises", ['exercises' => $cuerpo], $this->asTrainer())->assertOk();

        $despues = $this->getJson("/api/trainer/routines/{$p->id}", $this->asTrainer())->assertOk()->json('data.exercises');
        $this->assertSame($antes, $diaDe($despues), 'cada ejercicio sigue en su día');
        $this->assertSame(6, (int) $p->refresh()->days_per_week);
        // Y guardar otra vez tal cual no lo cambia (antes, el segundo guardado lo fijaba en el día equivocado).
        $tal = array_map(fn ($e) => ['id' => $e['id'], 'exercise_id' => $e['exercise_id'], 'sets' => $e['sets'], 'reps' => $e['reps'], 'day' => $e['day']], $despues);
        $this->putJson("/api/trainer/routines/{$p->id}/exercises", ['exercises' => $tal], $this->asTrainer())->assertOk();
        $this->assertSame($antes, $diaDe($this->getJson("/api/trainer/routines/{$p->id}", $this->asTrainer())->json('data.exercises')));
    }

    // ── «Organizar automáticamente» (punto F; ORGANIZAR-AUTOMATICAMENTE-CONTRATO.md) ──

    /** @return list<array<string,mixed>> la lista del editor tal como la manda la app */
    private function listaDelEditor(Routine $p): array
    {
        return array_map(fn ($e) => ['id' => $e['id'], 'exercise_id' => $e['exercise_id'], 'sets' => $e['sets'], 'reps' => $e['reps'],
            'weight' => $e['weight'], 'notes' => $e['notes'], 'day' => $e['day']],
            $this->getJson("/api/trainer/routines/{$p->id}", $this->asTrainer())->assertOk()->json('data.exercises'));
    }

    public function test_organizar_seis_a_cuatro_dias_es_una_vista_previa_y_luego_se_guarda_y_publica(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $lista = $this->listaDelEditor($p);
        $diasAntes = $p->fresh()->days;
        $filasAntes = RoutineExercise::where('routine_id', $p->id)->orderBy('id')->get(['id', 'exercise_id', 'sort_order', 'sets', 'reps'])->toArray();

        $r = $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista, 'days_per_week' => 4, 'session_minutes' => 60], $this->asTrainer())
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('data.days_per_week', 4)->assertJsonPath('data.changed', true);
        $data = $r->json('data');

        // Los mismos ejercicios (mismos ids de fila), agrupados por los 4 días del reparto; nada escrito.
        $nombres = array_column(app(RoutineMuscleOrganizer::class)->dayPlan(4, 'intermedio'), 'name');
        $this->assertSame($nombres, array_column($data['days'], 'name'));
        $this->assertEqualsCanonicalizing(array_column($lista, 'id'), array_column($data['exercises'], 'id'));
        $this->assertSame($nombres, array_values(array_unique(array_column($data['exercises'], 'day'))));
        $this->assertTrue($data['review']['ok'], json_encode($data['review'], JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThanOrEqual(0.6, $data['review']['min_share']);
        $this->assertEquals($diasAntes, $p->fresh()->days, 'organizar no escribe');
        $this->assertSame($filasAntes, RoutineExercise::where('routine_id', $p->id)->orderBy('id')->get(['id', 'exercise_id', 'sort_order', 'sets', 'reps'])->toArray());

        // El entrenador lo aplica y guarda con el PUT de siempre: 4 días, mismas filas.
        $cuerpo = array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'exercise_id', 'sets', 'reps', 'weight', 'notes', 'day'])), $data['exercises']);
        $this->putJson("/api/trainer/routines/{$p->id}/exercises", ['exercises' => $cuerpo], $this->asTrainer())->assertOk();
        $this->assertSame(4, (int) $p->refresh()->days_per_week);
        $this->assertEqualsCanonicalizing(array_column($filasAntes, 'id'), RoutineExercise::where('routine_id', $p->id)->pluck('id')->all());

        // Y se publica con 4 días confirmados, sin pagar una regeneración.
        $corridas = NutritionAiRun::count();
        $this->postJson("/api/trainer/routines/{$p->id}/publish", $this->confirmar($p, ['days_per_week' => 4]), $this->asTrainer())->assertOk();
        $this->assertSame($corridas, NutritionAiRun::count());
        $this->assertSame([$p->id], $this->rutinasDelSocio($socio));

        // Publicada, ya no se organiza.
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $cuerpo, 'days_per_week' => 4], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'routine_published');
    }

    public function test_organizar_lo_ya_organizado_no_cambia_y_avisa_de_la_duracion(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $lista = $this->listaDelEditor($p);

        $data = $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista, 'session_minutes' => 20], $this->asTrainer())
            ->assertOk()->json('data');
        $this->assertSame(6, $data['days_per_week'], 'por defecto, los días que tiene la lista');
        $this->assertFalse($data['changed'], 'la propuesta ya viene organizada');
        $this->assertSame(array_column($lista, 'id'), array_column($data['exercises'], 'id'));
        $this->assertNotEmpty(array_filter($data['warnings'], fn ($w) => str_contains($w, 'la sesión es de 20')));
    }

    public function test_organizar_respeta_dueno_catalogo_y_numero_de_dias(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $lista = $this->listaDelEditor($p);

        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => array_slice($lista, 0, 2), 'days_per_week' => 4], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'organize_more_days_than_exercises');
        $malo = $lista;
        $malo[0]['exercise_id'] = 999999;
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $malo], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'invalid_exercise_id');
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista, 'days_per_week' => 9], $this->asTrainer())
            ->assertStatus(422)->assertJsonValidationErrors(['days_per_week']);

        // Una publicada (la original) no.
        $this->postJson("/api/trainer/routines/{$original->id}/organize", ['exercises' => $lista], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'routine_published');
        // De un socio que ya no es suyo, no.
        MemberTrainerAssignment::where('member_id', $socio->id)->update(['status' => 'ended', 'ended_at' => now()]);
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'assignment_inactive');
        MemberTrainerAssignment::where('member_id', $socio->id)->update(['status' => MemberTrainerAssignment::STATUS_ACTIVE, 'ended_at' => null]);
        // De otro entrenador, no.
        $p->update(['trainer_id' => Trainer::create(['full_name' => 'Otro', 'document' => 'T-otro2', 'status' => 'active'])->id]);
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista], $this->asTrainer())
            ->assertStatus(422)->assertJsonPath('code', 'routine_not_owned');
    }

    public function test_organizar_respeta_lo_fijado_y_los_compuestos_por_su_musculo_secundario(): void
    {
        // Antes de cualquier petición: el catálogo se carga una vez por petición.
        $pm = Exercise::create(['name' => 'Peso muerto', 'local_name' => 'Peso muerto', 'muscle_group' => 'Espalda', 'body_part' => 'Espalda',
            'equipment' => 'barra', 'difficulty' => 'intermedio', 'target' => 'glutes', 'video_path' => 'videos/pm.mp4', 'provider' => 'local', 'external_id' => 'rec-pm']);
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $lista = $this->listaDelEditor($p);
        $dias = array_values(array_unique(array_column($lista, 'day')));

        // Un peso muerto (Espalda + glutes) en el día de pierna B: está bien, no se mueve.
        $lista[] = ['id' => null, 'exercise_id' => $pm->id, 'sets' => 3, 'reps' => '8', 'weight' => null, 'notes' => null, 'day' => $dias[5]];
        // Un ejercicio de pierna fijado a mano en el primer día (de empuje): se respeta.
        $pierna = array_search(true, array_map(fn ($e) => $e['day'] === $dias[2], $lista), true);
        $lista[$pierna]['day'] = $dias[0];
        $lista[$pierna]['pinned'] = true;

        $r = $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $lista], $this->asTrainer());
        $this->assertSame(200, $r->status(), (string) $r->getContent());
        $data = $r->json('data');
        // La fila añadida (sin id) en Pierna B; el doble del modelo puede haber puesto otro peso muerto en Tracción.
        $anadido = array_values(array_filter($data['exercises'], fn ($e) => $e['exercise_id'] === $pm->id && $e['id'] === null))[0];
        $this->assertSame($dias[5], $anadido['day']);
        $this->assertFalse($anadido['moved'], 'el compuesto cuenta su músculo secundario');
        $fijado = array_values(array_filter($data['exercises'], fn ($e) => $e['id'] === $lista[$pierna]['id']))[0];
        $this->assertSame($dias[0], $fijado['day']);
        $this->assertTrue($fijado['pinned']);
        $this->assertCount(count($lista), $data['exercises']);
    }

    public function test_sse_solo_avisa_al_socio_cuando_cambia_lo_que_ve_y_despues_de_confirmar(): void
    {
        FakeIronIaOpenAi::install();
        $socio = $this->socio();
        $original = $this->rutinaIa($socio, 4);
        $eventos = fn () => MemberRealtimeEvent::where('member_id', $socio->id)->where('type', RealtimeEvents::ROUTINE)->count();
        $antes = $eventos();

        // La campaña y regenerar crean borradores invisibles: el socio no recibe nada.
        $this->recuperar(['--execute' => true])->assertExitCode(0);
        $p = $this->propuestaDe($original);
        $this->postJson("/api/trainer/routines/{$p->id}/organize", ['exercises' => $this->listaDelEditor($p), 'days_per_week' => 4], $this->asTrainer())->assertOk();
        $nueva = $this->postJson("/api/trainer/routines/{$p->id}/recovery/regenerate", [
            'days_per_week' => 4, 'session_minutes' => 45, 'training_level' => 'intermedio', 'objective' => 'Salud', 'injuries' => 'Ninguna',
        ], $this->asTrainer())->assertStatus(201)->json('data.id');
        $this->assertSame($antes, $eventos(), 'ni la campaña, ni organizar, ni regenerar avisan al socio');

        // Una publicación rechazada (la transacción se deshace) no avisa.
        $this->postJson("/api/trainer/routines/{$nueva}/publish", [], $this->asTrainer())->assertStatus(422);
        $this->assertSame($antes, $eventos(), 'sin commit no hay aviso');

        // La buena avisa una vez.
        $this->postJson("/api/trainer/routines/{$nueva}/publish", $this->confirmar(Routine::findOrFail($nueva), ['days_per_week' => 4]), $this->asTrainer())->assertOk();
        $this->assertSame($antes + 1, $eventos());
    }
}
