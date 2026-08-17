<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\PersonalRecord;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\PersonalRecordService;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Sesiones de entrenamiento reales: duración, series persistidas, volumen,
 * idempotencia y récords.
 *
 * Antes, lo que el socio escribía durante la rutina vivía solo en memoria: el
 * resumen mostraba "0 min" y "0 kg" porque calculaba el volumen desde la
 * PRESCRIPCIÓN de la rutina (peso 0 por defecto) en vez de desde las series
 * ejecutadas, y al cerrar la app no quedaba nada.
 */
class WorkoutSessionTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Routine $routine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::create([
            'full_name' => 'Socio Entrena',
            'document_number' => '880880880',
            'phone' => '+573008808808',
            'access_hash' => 'tok-880',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->routine = Routine::create([
            'name' => 'Full body',
            'member_id' => $this->member->id,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    /** Payload de una sesión: 2 ejercicios, 3 series cada uno. */
    private function payload(array $override = []): array
    {
        return array_merge([
            'client_session_id' => 'sess-abc-123',
            'routine_id' => (string) $this->routine->id,
            'routine_name' => 'Full body',
            'started_at' => '2026-08-16T10:00:00-05:00',
            'completed_at' => '2026-08-16T10:42:30-05:00',
            'exercises' => [
                [
                    'name' => 'Press de banca',
                    'order' => 0,
                    'sets' => [
                        ['set_number' => 1, 'reps' => 10, 'weight_kg' => 40, 'rpe' => 7, 'completed' => true],
                        ['set_number' => 2, 'reps' => 8, 'weight_kg' => 50, 'rpe' => 8, 'completed' => true],
                        ['set_number' => 3, 'reps' => 6, 'weight_kg' => 60, 'rpe' => 9, 'completed' => false],
                    ],
                ],
                [
                    'name' => 'Sentadilla',
                    'order' => 1,
                    'sets' => [
                        ['set_number' => 1, 'reps' => 10, 'weight_kg' => 70, 'completed' => true],
                    ],
                ],
            ],
        ], $override);
    }

    public function test_la_sesion_persiste_duracion_y_series(): void
    {
        $res = $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())
            ->assertCreated();

        // 42 min 30 s reales entre inicio y fin.
        $this->assertSame(2550, $res->json('data.duration_seconds'));

        $session = WorkoutSession::firstOrFail();
        $this->assertSame(4, $session->sets()->count(), 'se guardan las 4 series, completadas o no');

        // Los valores escritos por el socio sobreviven tal cual.
        $set = WorkoutSessionSet::where('set_number', 2)->where('exercise_name', 'Press de banca')->firstOrFail();
        $this->assertSame(8, $set->reps);
        $this->assertSame('50.00', (string) $set->weight_kg);
        $this->assertSame(8, $set->rpe);
    }

    public function test_el_volumen_sale_de_las_series_ejecutadas(): void
    {
        $res = $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())
            ->assertCreated();

        // 10×40 + 8×50 + 10×70 = 400 + 400 + 700 = 1500. La tercera serie NO
        // cuenta porque no se marcó como completada.
        $this->assertEqualsWithDelta(1500, $res->json('data.total_volume_kg'), 0.01);
        $this->assertSame(3, $res->json('data.total_sets'), 'solo las series completadas');
        $this->assertSame(2, $res->json('data.total_exercises'));
    }

    public function test_una_sesion_sin_carga_no_inventa_volumen(): void
    {
        $res = $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-bodyweight',
            'exercises' => [[
                'name' => 'Dominadas',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 12, 'completed' => true]],
            ]],
        ]), $this->auth())->assertCreated();

        $this->assertEqualsWithDelta(0, $res->json('data.total_volume_kg'), 0.01);
        $this->assertSame(1, $res->json('data.total_sets'));
    }

    public function test_reenviar_la_misma_sesion_no_la_duplica(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())
            ->assertCreated()->assertJsonPath('created', true);

        // Segundo envío con el mismo client_session_id: reintento, no sesión nueva.
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())
            ->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, WorkoutSession::count());
        $this->assertSame(4, WorkoutSessionSet::count());
        $this->assertSame(1, RoutineCompletion::count(), 'tampoco duplica la completion legacy');
    }

    public function test_entrenar_marca_un_solo_dia_de_racha(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())->assertCreated();
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-segunda-del-dia',
        ]), $this->auth())->assertCreated();

        // Dos rutinas el mismo día = un único día activo.
        $this->assertSame(1, MemberAppActivityDay::where('member_id', $this->member->id)->count());
    }

    public function test_la_sesion_se_puede_recuperar_despues(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())->assertCreated();

        // Cerrar y reabrir la app no puede perder el resumen.
        $this->getJson('/api/app/workout-sessions/sess-abc-123', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', 2550)
            ->assertJsonPath('data.total_volume_kg', fn ($v) => abs($v - 1500) < 0.01);
    }

    public function test_la_primera_serie_valida_establece_el_record(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())->assertCreated();

        $record = PersonalRecord::where('metric', PersonalRecord::METRIC_MAX_WEIGHT)
            ->where('exercise_name', 'Press de banca')->firstOrFail();

        $this->assertSame('50.00', (string) $record->value, 'la mejor serie COMPLETADA fue 50 kg');
        $this->assertNull($record->previous_value);
        $this->assertSame(PersonalRecord::SOURCE_WORKOUT, $record->source);
    }

    public function test_solo_hay_record_nuevo_cuando_se_supera(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())->assertCreated();

        // Segunda sesión con MENOS carga: no debe sustituir el récord.
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-peor',
            'exercises' => [[
                'name' => 'Press de banca',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 8, 'weight_kg' => 45, 'completed' => true]],
            ]],
        ]), $this->auth())->assertCreated()->assertJsonPath('data.new_records', []);

        $record = PersonalRecord::where('metric', PersonalRecord::METRIC_MAX_WEIGHT)
            ->where('exercise_name', 'Press de banca')->firstOrFail();
        $this->assertSame('50.00', (string) $record->value);

        // Tercera sesión superándolo: sí es récord y guarda el anterior.
        $res = $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-mejor',
            'exercises' => [[
                'name' => 'Press de banca',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 5, 'weight_kg' => 65, 'completed' => true]],
            ]],
        ]), $this->auth())->assertCreated();

        $this->assertNotEmpty($res->json('data.new_records'));
        $record->refresh();
        $this->assertSame('65.00', (string) $record->value);
        $this->assertSame('50.00', (string) $record->previous_value);
    }

    public function test_el_record_agrupa_aunque_el_nombre_cambie_de_forma(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 's1',
            'exercises' => [[
                'name' => 'Press Militar',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 5, 'weight_kg' => 40, 'completed' => true]],
            ]],
        ]), $this->auth())->assertCreated();

        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 's2',
            'exercises' => [[
                'name' => 'press militar',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 5, 'weight_kg' => 45, 'completed' => true]],
            ]],
        ]), $this->auth())->assertCreated();

        $this->assertSame(
            1,
            PersonalRecord::where('metric', PersonalRecord::METRIC_MAX_WEIGHT)->count(),
            'mayúsculas distintas no deben crear dos récords del mismo ejercicio',
        );
    }

    public function test_una_serie_sin_completar_no_genera_record(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-sin-marcar',
            'exercises' => [[
                'name' => 'Peso muerto',
                'order' => 0,
                'sets' => [['set_number' => 1, 'reps' => 5, 'weight_kg' => 120, 'completed' => false]],
            ]],
        ]), $this->auth())->assertCreated();

        $this->assertSame(0, PersonalRecord::where('exercise_name', 'Peso muerto')->count());
    }

    public function test_la_serie_se_enlaza_con_el_ejercicio_del_catalogo(): void
    {
        $exercise = Exercise::create(['name' => 'Press de banca', 'provider' => 'manual']);

        $this->postJson('/api/app/workout-sessions', $this->payload(), $this->auth())->assertCreated();

        $set = WorkoutSessionSet::where('exercise_name', 'Press de banca')->first();
        $this->assertSame($exercise->id, $set->exercise_id, 'se resuelve por nombre si la rutina no manda id');
    }

    public function test_no_registra_una_rutina_que_no_es_del_miembro(): void
    {
        $otro = Member::create([
            'full_name' => 'Otro', 'document_number' => '881881881',
            'phone' => '+573008818818', 'access_hash' => 'tok-881',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $ajena = Routine::create(['name' => 'Ajena', 'member_id' => $otro->id]);

        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-ajena',
            'routine_id' => (string) $ajena->id,
        ]), $this->auth())->assertCreated();

        // La sesión se guarda (el socio entrenó), pero sin colgarse de una
        // rutina ajena ni crear una completion en ella.
        $session = WorkoutSession::where('client_session_id', 'sess-ajena')->firstOrFail();
        $this->assertNull($session->routine_id);
        $this->assertSame(0, RoutineCompletion::where('routine_id', $ajena->id)->count());
    }

    public function test_sin_hora_de_inicio_la_duracion_no_se_inventa(): void
    {
        $res = $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-sin-inicio',
            'started_at' => null,
        ]), $this->auth())->assertCreated();

        $this->assertSame(0, $res->json('data.duration_seconds'));
    }

    /**
     * Reproduce el fallo real del iPhone: 3 ejercicios, 9 series y una duración
     * de 1 min 1 s con MILISEGUNDOS.
     *
     * `DateTime.now().toIso8601String()` incluye milisegundos, y en Carbon 3
     * `diffInSeconds()` devuelve float (en Carbon 2 devolvía int). Ese float
     * llegaba a `duration_seconds`, que es `unsignedInteger`, y PostgreSQL lo
     * rechazaba con 22P02: "invalid input syntax for type integer: 24.081829".
     *
     * Se comprueba el valor CRUDO de la columna, no el del modelo: el cast
     * `'integer'` de Eloquent convierte al leer y ocultaría el problema.
     */
    public function test_la_duracion_se_guarda_como_entero_aunque_lleguen_milisegundos(): void
    {
        $res = $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-iphone-real',
            'started_at' => '2026-08-16T23:53:37.100-05:00',
            'completed_at' => '2026-08-16T23:54:38.181-05:00', // 61,081 s
        ]), $this->auth())->assertCreated();

        $this->assertSame(61, $res->json('data.duration_seconds'));

        $raw = DB::table('workout_sessions')
            ->where('client_session_id', 'sess-iphone-real')
            ->value('duration_seconds');

        $this->assertSame(61, $raw, 'la columna es entera: un float la rompe en PostgreSQL');
    }

    /** La sesión completa del iPhone: 3 ejercicios y 9 series con carga. */
    public function test_una_sesion_de_tres_ejercicios_y_nueve_series(): void
    {
        $exercises = [];
        foreach (['Press de banca', 'Sentadilla', 'Peso muerto'] as $i => $name) {
            $sets = [];
            for ($s = 1; $s <= 3; $s++) {
                $sets[] = [
                    'set_number' => $s,
                    'reps' => 10,
                    'weight_kg' => 20 + ($i * 10) + $s,
                    'rpe' => 7,
                    'completed' => true,
                ];
            }
            $exercises[] = ['name' => $name, 'order' => $i, 'sets' => $sets];
        }

        $res = $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-3x3',
            'started_at' => '2026-08-16T23:53:37.100-05:00',
            'completed_at' => '2026-08-16T23:54:38.181-05:00',
            'exercises' => $exercises,
        ]), $this->auth())->assertCreated();

        $this->assertSame(61, $res->json('data.duration_seconds'));
        $this->assertSame(9, $res->json('data.total_sets'));
        $this->assertSame(3, $res->json('data.total_exercises'));

        // 10×(21+22+23) + 10×(31+32+33) + 10×(41+42+43) = 660+960+1260 = 2880
        $this->assertEqualsWithDelta(2880, $res->json('data.total_volume_kg'), 0.01);
        $this->assertSame(9, WorkoutSessionSet::count());
    }

    /**
     * Si algo falla a mitad de la persistencia, no puede quedar media sesión
     * guardada: ni la completion legacy, ni series sueltas, ni récords.
     *
     * Se simula un fallo en la derivación de récords, que ocurre DENTRO de la
     * transacción y después de haber creado sesión, series y completion.
     */
    public function test_un_fallo_al_guardar_no_deja_estados_parciales(): void
    {
        $this->app->bind(PersonalRecordService::class, fn () => new class extends PersonalRecordService
        {
            public function __construct() {}

            public function evaluateSession(Member $member, WorkoutSession $session): Collection
            {
                throw new RuntimeException('fallo simulado al derivar récords');
            }
        });

        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-rota',
        ]), $this->auth())->assertStatus(500)
            // Nunca "Server Error": el mensaje tiene que poder enseñarse.
            ->assertJsonPath('code', 'workout_session_not_saved');

        $this->assertSame(0, WorkoutSession::count(), 'la sesión debe revertirse');
        $this->assertSame(0, WorkoutSessionSet::count());
        $this->assertSame(0, RoutineCompletion::count());
        $this->assertSame(0, PersonalRecord::count());
    }

    /** Tras un fallo, reintentar con el mismo id guarda la sesión una sola vez. */
    public function test_reintentar_tras_un_fallo_guarda_una_unica_sesion(): void
    {
        // Falla SOLO el primer intento; el segundo se comporta con normalidad.
        // Modela exactamente lo ocurrido en el iPhone: error, y luego reintento.
        $this->app->bind(PersonalRecordService::class, fn () => new class extends PersonalRecordService
        {
            private static int $calls = 0;

            public function __construct() {}

            public function evaluateSession(Member $member, WorkoutSession $session): Collection
            {
                self::$calls++;
                if (self::$calls === 1) {
                    throw new RuntimeException('fallo simulado en el primer intento');
                }

                return (new PersonalRecordService)->evaluateSession($member, $session);
            }
        });

        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-retry',
        ]), $this->auth())->assertStatus(500);

        $this->assertSame(0, WorkoutSession::count(), 'el primer intento no deja nada');

        // La app reintenta con el MISMO client_session_id.
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-retry',
        ]), $this->auth())->assertCreated()->assertJsonPath('created', true);

        // Y un tercer envío ya es idempotente.
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-retry',
        ]), $this->auth())->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, WorkoutSession::count());
        $this->assertSame(1, RoutineCompletion::count());
    }

    public function test_requiere_autenticacion(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload())->assertStatus(401);
    }

    public function test_rechaza_valores_fuera_de_rango(): void
    {
        $this->postJson('/api/app/workout-sessions', $this->payload([
            'client_session_id' => 'sess-mala',
            'exercises' => [[
                'name' => 'Press',
                'sets' => [['set_number' => 1, 'reps' => 10, 'weight_kg' => 99999, 'completed' => true]],
            ]],
        ]), $this->auth())->assertStatus(422);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
