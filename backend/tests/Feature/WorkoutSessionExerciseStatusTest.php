<?php

namespace Tests\Feature;

use App\Enums\WorkoutExerciseStatus;
use App\Enums\WorkoutSkipReason;
use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\PersonalRecord;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionExercise;
use App\Models\WorkoutSessionSet;
use App\Services\PersonalRecordService;
use App\Services\WeeklyStreakService;
use App\Services\WorkoutSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una rutina termina cuando está HECHA, no cuando el socio llega al último
 * ejercicio de la lista.
 *
 * EL FALLO QUE ESTO CIERRA
 * ------------------------
 * FINALIZAR solo miraba el ejercicio que había en pantalla. Con cinco
 * ejercicios, marcando las series del primero, el entrenamiento se archivaba
 * como terminado: se creaba el día de racha, se notificaba la rutina completada
 * y el resumen decía «5 ejercicios», porque `total_exercises` contaba las
 * series que la app rellena desde la PRESCRIPCIÓN aunque nadie las hubiera
 * marcado. Volumen y series sí eran reales, así que el historial del socio se
 * contradecía consigo mismo.
 *
 * LO QUE SE VIGILA AQUÍ
 * ---------------------
 * Que la regla viva en el servidor. La pantalla puede estar parcheada, la
 * petición puede llegar a mano y las apps antiguas no saben nada de esto: el
 * endpoint es alcanzable directamente y tiene que defenderse solo.
 *
 * Y que las apps 2.0.3 que hay instaladas sigan pudiendo registrar su
 * entrenamiento mientras tanto. Endurecer de golpe dejaría a gente entrenando
 * sin poder guardar nada hasta que actualice.
 */
class WorkoutSessionExerciseStatusTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Routine $routine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::create([
            'full_name' => 'Socio Flexible',
            'document_number' => '990990990',
            'phone' => '+573009909909',
            'access_hash' => 'tok-990',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->routine = Routine::create([
            'name' => 'Torso pierna',
            'member_id' => $this->member->id,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    /** Tres series marcadas de verdad. */
    private function setsHechas(int $peso = 40): array
    {
        return [
            ['set_number' => 1, 'reps' => 10, 'weight_kg' => $peso, 'completed' => true],
            ['set_number' => 2, 'reps' => 10, 'weight_kg' => $peso, 'completed' => true],
            ['set_number' => 3, 'reps' => 10, 'weight_kg' => $peso, 'completed' => true],
        ];
    }

    /** Tres series prescritas y sin marcar: lo que la app rellena al empezar. */
    private function setsSinMarcar(int $peso = 40): array
    {
        return [
            ['set_number' => 1, 'reps' => 10, 'weight_kg' => $peso, 'completed' => false],
            ['set_number' => 2, 'reps' => 10, 'weight_kg' => $peso, 'completed' => false],
            ['set_number' => 3, 'reps' => 10, 'weight_kg' => $peso, 'completed' => false],
        ];
    }

    private function payload(array $exercises, string $cid = 'sess-flex-1'): array
    {
        return [
            'client_session_id' => $cid,
            'routine_id' => (string) $this->routine->id,
            'routine_name' => 'Torso pierna',
            'started_at' => '2026-09-09T08:00:00-05:00',
            'completed_at' => '2026-09-09T09:00:00-05:00',
            'exercises' => $exercises,
        ];
    }

    private function enviar(array $exercises, string $cid = 'sess-flex-1')
    {
        return $this->postJson('/api/app/workout-sessions', $this->payload($exercises, $cid), $this->auth());
    }

    // ── 1-2. Los dos contratos conviven ─────────────────────────────────

    /** 1. Una app 2.0.3, que no sabe mandar estado, sigue guardando igual. */
    public function test_el_payload_antiguo_sigue_funcionando(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'sets' => $this->setsHechas(40)],
            ['name' => 'Sentadilla', 'order' => 1, 'sets' => $this->setsHechas(60)],
        ])->assertCreated();

        $this->assertSame(6, $res->json('data.total_sets'));
        $this->assertSame(2, $res->json('data.total_exercises'));
        $this->assertSame(1, WorkoutSession::count());

        // Aunque no declarara estado, la sesión queda con su desglose: se
        // DERIVA de las series, que es lo único que esa app sabe contar.
        $this->assertSame(2, WorkoutSessionExercise::count());
        $this->assertSame(
            [WorkoutExerciseStatus::COMPLETED, WorkoutExerciseStatus::COMPLETED],
            WorkoutSessionExercise::orderBy('exercise_order')->get()->pluck('status')->all(),
        );
    }

    /** 2. La app nueva declara el estado de cada ejercicio y se guarda tal cual. */
    public function test_el_payload_nuevo_completo_funciona(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas(40)],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'completed', 'sets' => $this->setsHechas(60)],
        ])->assertCreated();

        $this->assertSame(2, $res->json('data.total_exercises'));
        $this->assertSame(6, $res->json('data.total_sets'));

        $filas = WorkoutSessionExercise::orderBy('exercise_order')->get();
        $this->assertCount(2, $filas);
        $this->assertSame('Press de banca', $filas[0]->exercise_name);
        $this->assertSame('press de banca', $filas[0]->exercise_key);
        $this->assertSame(0, $filas[0]->exercise_order);
        $this->assertNull($filas[0]->skip_reason);
    }

    // ── 3-6. La regla de finalización ───────────────────────────────────

    /** 3. Un ejercicio sin empezar impide cerrar la rutina. */
    public function test_pending_bloquea_la_finalizacion(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'pending', 'sets' => $this->setsSinMarcar()],
        ])->assertStatus(422);

        $this->assertSame('workout_incomplete', $res->json('code'));
        $this->assertStringContainsString('1 ejercicio', $res->json('message'));

        // Y no queda NADA a medias.
        $this->assertSame(0, WorkoutSession::count());
        $this->assertSame(0, WorkoutSessionSet::count());
        $this->assertSame(0, WorkoutSessionExercise::count());
    }

    /** 4. Empezado no es terminado. */
    public function test_in_progress_bloquea_la_finalizacion(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'in_progress', 'sets' => [
                ['set_number' => 1, 'reps' => 10, 'weight_kg' => 60, 'completed' => true],
                ['set_number' => 2, 'reps' => 10, 'weight_kg' => 60, 'completed' => false],
            ]],
        ])->assertStatus(422);

        $this->assertSame('workout_incomplete', $res->json('code'));
        $this->assertSame(0, WorkoutSession::count());
    }

    /**
     * 5. LA REGLA QUE DEFINE TODO: saltar es aplazar, no descartar.
     *
     * Si un salto dejara cerrar la rutina, bastaría con saltarlo todo para
     * cobrar el día entrenado, y el estado no serviría para nada.
     */
    public function test_skipped_temporarily_sigue_bloqueando(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
            [
                'name' => 'Sentadilla', 'order' => 1,
                'status' => 'skipped_temporarily', 'skip_reason' => 'machine_busy',
                'sets' => $this->setsSinMarcar(),
            ],
        ])->assertStatus(422);

        $this->assertSame('workout_incomplete', $res->json('code'));
        $this->assertSame(0, WorkoutSession::count());
        $this->assertSame(0, RoutineCompletion::count());
    }

    /** 6. Con todo completado, se cierra. */
    public function test_todos_completed_permite_finalizar(): void
    {
        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas(40)],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'completed', 'sets' => $this->setsHechas(60)],
            ['name' => 'Peso muerto', 'order' => 2, 'status' => 'completed', 'sets' => $this->setsHechas(80)],
        ])->assertCreated()
            ->assertJsonPath('data.total_exercises', 3);
    }

    /**
     * 6b. Una rutina de cinco completada entera se archiva como CINCO.
     *
     * Es el número que ve el socio en su resumen y en su historial. Antes salía
     * bien por casualidad —contaba claves únicas de series, y si todo se marcó
     * el resultado coincide—; lo que fallaba era el caso a medias. Aquí se fija
     * el caso completo para que arreglar aquel no rompa este.
     */
    public function test_una_rutina_de_cinco_completa_registra_cinco(): void
    {
        $ejercicios = [];
        foreach (['Press de banca', 'Sentadilla', 'Peso muerto', 'Remo', 'Dominadas'] as $i => $nombre) {
            $ejercicios[] = [
                'name' => $nombre,
                'order' => $i,
                'status' => 'completed',
                'sets' => $this->setsHechas(40 + $i * 5),
            ];
        }

        $res = $this->enviar($ejercicios, 'sess-cinco')->assertCreated();

        $this->assertSame(5, $res->json('data.total_exercises'));
        $this->assertSame(15, $res->json('data.total_sets'));
        $this->assertSame(5, WorkoutSessionExercise::where('status', 'completed')->count());
    }

    /**
     * 7. La POSICIÓN no participa en la regla.
     *
     * Ni estar en el último ejercicio autoriza a cerrar, ni un orden raro lo
     * impide. `currentIndex == lastIndex` no es una condición de finalización.
     */
    public function test_la_posicion_del_ejercicio_no_altera_la_regla(): void
    {
        // El ÚLTIMO está hecho y los anteriores no: sigue sin poder cerrarse.
        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'pending', 'sets' => $this->setsSinMarcar()],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'pending', 'sets' => $this->setsSinMarcar()],
            ['name' => 'Peso muerto', 'order' => 2, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ], 'sess-ultimo')->assertStatus(422);

        // Órdenes salteados y desordenados, todo hecho: se cierra igual.
        $this->enviar([
            ['name' => 'Peso muerto', 'order' => 9, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Press de banca', 'order' => 2, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Sentadilla', 'order' => 5, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ], 'sess-desordenado')->assertCreated()
            ->assertJsonPath('data.total_exercises', 3);
    }

    // ── 8-10. Los totales dicen la verdad ───────────────────────────────

    /**
     * 8. EL BUG DE `total_exercises`, con el payload que lo producía.
     *
     * Tres ejercicios prescritos, uno hecho. Antes se archivaba como 3.
     */
    public function test_total_exercises_solo_cuenta_los_completados(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'sets' => $this->setsHechas(40)],
            ['name' => 'Sentadilla', 'order' => 1, 'sets' => $this->setsSinMarcar(60)],
            ['name' => 'Peso muerto', 'order' => 2, 'sets' => $this->setsSinMarcar(80)],
        ])->assertCreated();

        $this->assertSame(1, $res->json('data.total_exercises'), 'solo uno se entrenó');

        $estados = WorkoutSessionExercise::orderBy('exercise_order')->pluck('status')->all();
        $this->assertSame([
            WorkoutExerciseStatus::COMPLETED,
            WorkoutExerciseStatus::PENDING,
            WorkoutExerciseStatus::PENDING,
        ], $estados);
    }

    /** 9. Una serie sin marcar no engorda el total de series. */
    public function test_una_serie_sin_marcar_no_suma_en_total_sets(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => [
                ['set_number' => 1, 'reps' => 10, 'weight_kg' => 50, 'completed' => true],
                ['set_number' => 2, 'reps' => 10, 'weight_kg' => 50, 'completed' => true],
                ['set_number' => 3, 'reps' => 10, 'weight_kg' => 50, 'completed' => false],
            ]],
        ])->assertCreated();

        $this->assertSame(2, $res->json('data.total_sets'));
        // Pero la fila se guarda: es el registro de lo prescrito y no ejecutado.
        $this->assertSame(3, WorkoutSessionSet::count());
    }

    /** 10. Ni el volumen. El estado del ejercicio no manda sobre las series. */
    public function test_una_serie_sin_marcar_no_suma_volumen(): void
    {
        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => [
                ['set_number' => 1, 'reps' => 10, 'weight_kg' => 50, 'completed' => true],
                ['set_number' => 2, 'reps' => 10, 'weight_kg' => 50, 'completed' => false],
            ]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(500.0, $res->json('data.total_volume_kg'), 0.01);
    }

    // ── 11-12. Identidad dentro de la sesión ────────────────────────────

    /** 11. Un ejercicio por posición: el índice único lo garantiza. */
    public function test_no_puede_haber_dos_ejercicios_en_la_misma_posicion(): void
    {
        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ])->assertCreated();

        $fila = WorkoutSessionExercise::firstOrFail();

        $this->expectException(QueryException::class);

        WorkoutSessionExercise::create([
            'workout_session_id' => $fila->workout_session_id,
            'exercise_order' => $fila->exercise_order,
            'exercise_name' => 'Otro',
            'exercise_key' => 'otro',
            'status' => WorkoutExerciseStatus::COMPLETED,
        ]);
    }

    /**
     * 12. El MISMO ejercicio dos veces en el día no colisiona.
     *
     * Pasa de verdad: una superserie, o press de banca en dos bloques. Si la
     * identidad fuera `exercise_id`, la segunda vez pisaría a la primera.
     */
    public function test_el_mismo_ejercicio_repetido_no_colisiona(): void
    {
        $ejercicio = Exercise::create(['name' => 'Press de banca']);

        $res = $this->enviar([
            ['name' => 'Press de banca', 'exercise_id' => $ejercicio->id, 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas(40)],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'completed', 'sets' => $this->setsHechas(60)],
            ['name' => 'Press de banca', 'exercise_id' => $ejercicio->id, 'order' => 2, 'status' => 'completed', 'sets' => $this->setsHechas(45)],
        ])->assertCreated();

        $this->assertSame(3, WorkoutSessionExercise::count());
        $this->assertSame(
            [0, 1, 2],
            WorkoutSessionExercise::orderBy('exercise_order')->pluck('exercise_order')->all(),
        );

        // Dos filas distintas apuntando al mismo ejercicio del catálogo.
        $this->assertSame(2, WorkoutSessionExercise::where('exercise_id', $ejercicio->id)->count());

        // `total_exercises` cuenta EJERCICIOS HECHOS, y se hicieron tres veces.
        $this->assertSame(3, $res->json('data.total_exercises'));
    }

    // ── 13-14. El motivo del salto ──────────────────────────────────────

    /**
     * 13. Un motivo del catálogo se acepta en la frontera.
     *
     * La sesión se rechaza igual —el salto sigue siendo un pendiente—, y por
     * eso el rechazo es `workout_incomplete` y NO un error de validación: el
     * motivo era correcto, lo que falta es el ejercicio.
     *
     * Consecuencia: hoy `skip_reason` no puede llegar a una fila persistida,
     * porque una sesión con un salto no se cierra. La columna existe para el
     * día en que una sesión a medias sea guardable; aquí se comprueba que el
     * valor viaja y se castea bien.
     */
    public function test_un_motivo_de_salto_valido_se_acepta(): void
    {
        $res = $this->enviar([
            [
                'name' => 'Sentadilla', 'order' => 0,
                'status' => 'skipped_temporarily', 'skip_reason' => 'discomfort',
                'sets' => $this->setsSinMarcar(),
            ],
        ])->assertStatus(422);

        $res->assertJsonPath('code', 'workout_incomplete');
        $res->assertJsonMissingPath('errors');

        $fila = new WorkoutSessionExercise([
            'exercise_name' => 'Sentadilla',
            'exercise_key' => 'sentadilla',
            'exercise_order' => 0,
            'status' => WorkoutExerciseStatus::SKIPPED_TEMPORARILY,
            'skip_reason' => WorkoutSkipReason::DISCOMFORT,
        ]);

        $this->assertSame(WorkoutSkipReason::DISCOMFORT, $fila->skip_reason);
        $this->assertTrue($fila->status->blocksCompletion());
    }

    /** 14. Un motivo inventado se rechaza; no se degrada a "otro". */
    public function test_un_motivo_de_salto_invalido_se_rechaza(): void
    {
        $this->enviar([
            [
                'name' => 'Sentadilla', 'order' => 0,
                'status' => 'skipped_temporarily', 'skip_reason' => 'me_dio_pereza',
                'sets' => $this->setsSinMarcar(),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('exercises.0.skip_reason');
    }

    /** 14b. Y un estado inventado tampoco pasa. */
    public function test_un_estado_inventado_se_rechaza(): void
    {
        $this->enviar([
            ['name' => 'Sentadilla', 'order' => 0, 'status' => 'casi', 'sets' => $this->setsHechas()],
        ])->assertStatus(422)->assertJsonValidationErrors('exercises.0.status');
    }

    // ── 15. El historial de antes de esta tabla ─────────────────────────

    /**
     * 15. Una sesión anterior a la tabla sigue siendo legible.
     *
     * Sin backfill: escribir filas para entrenamientos que ya nadie puede
     * confirmar sería inventar datos. Se derivan en lectura.
     */
    public function test_una_sesion_historica_sin_filas_nuevas_sigue_legible(): void
    {
        $sesion = WorkoutSession::create([
            'member_id' => $this->member->id,
            'client_session_id' => 'sess-historica',
            'routine_name' => 'Vieja',
            'completed_at' => now(),
            'duration_seconds' => 1800,
            'total_volume_kg' => 500,
            'total_sets' => 2,
            'total_exercises' => 2, // el valor viejo, tal como se archivó
            'source' => 'app',
        ]);

        foreach ([
            ['Press de banca', 0, true],
            ['Sentadilla', 1, false],
        ] as [$nombre, $orden, $hecha]) {
            WorkoutSessionSet::create([
                'workout_session_id' => $sesion->id,
                'exercise_name' => $nombre,
                'exercise_key' => WorkoutSessionSet::normalizeKey($nombre),
                'exercise_order' => $orden,
                'set_number' => 1,
                'reps' => 10,
                'weight_kg' => 50,
                'completed' => $hecha,
            ]);
        }

        $this->assertSame(0, $sesion->exercises()->count(), 'no se le inventan filas');

        $desglose = $sesion->exerciseBreakdown();
        $this->assertCount(2, $desglose);
        $this->assertSame(WorkoutExerciseStatus::COMPLETED->value, $desglose[0]['status']);
        $this->assertSame(WorkoutExerciseStatus::PENDING->value, $desglose[1]['status']);
        $this->assertSame(1, $sesion->completedExerciseCount());

        // La métrica ARCHIVADA no se toca: corregir el cálculo no reescribe el
        // pasado.
        $this->assertSame(2, $sesion->fresh()->total_exercises);
    }

    // ── 16-17. Idempotencia ─────────────────────────────────────────────

    /** 16. Reenviar el mismo id devuelve la sesión, no otra. */
    public function test_reenviar_el_mismo_client_session_id_no_duplica(): void
    {
        $ejercicios = [
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ];

        $this->enviar($ejercicios, 'sess-replay')->assertCreated()->assertJsonPath('created', true);
        $this->enviar($ejercicios, 'sess-replay')->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, WorkoutSession::count());
        $this->assertSame(1, WorkoutSessionExercise::count());
        $this->assertSame(3, WorkoutSessionSet::count());
    }

    /**
     * 17. La carrera: dos envíos simultáneos del mismo entrenamiento.
     *
     * Ambos consultan antes de que ninguno haya insertado, así que los dos
     * creen ser el primero. El índice único evita el duplicado —eso ya
     * funcionaba— pero el perdedor se llevaba un 500 y la app le decía al socio
     * que su entrenamiento no se había guardado, cuando sí estaba guardado.
     *
     * Se simula sustituyendo la consulta previa por una que no ve la fila y
     * deja que el rival la inserte justo después: exactamente la ventana real.
     */
    public function test_la_carrera_por_el_mismo_id_devuelve_la_sesion_existente(): void
    {
        $miembro = $this->member;

        $this->app->bind(WorkoutSessionService::class, function ($app) use ($miembro) {
            $servicio = new class(
                $app->make(PersonalRecordService::class),
                $app->make(WeeklyStreakService::class),
            ) extends WorkoutSessionService {
                public int $consultas = 0;

                public ?\Closure $alPrimerFallo = null;

                protected function findExisting(Member $member, string $clientSessionId): ?WorkoutSession
                {
                    $this->consultas++;

                    if ($this->consultas === 1) {
                        ($this->alPrimerFallo)($member, $clientSessionId);

                        return null;
                    }

                    return parent::findExisting($member, $clientSessionId);
                }
            };

            $servicio->alPrimerFallo = function (Member $member, string $cid): void {
                DB::table('workout_sessions')->insert([
                    'member_id' => $member->id,
                    'client_session_id' => $cid,
                    'routine_name' => 'Torso pierna',
                    'completed_at' => now(),
                    'duration_seconds' => 3600,
                    'total_volume_kg' => 1200,
                    'total_sets' => 3,
                    'total_exercises' => 1,
                    'source' => 'app',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            };

            return $servicio;
        });

        $res = $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ], 'sess-carrera');

        $res->assertOk()->assertJsonPath('created', false);
        $this->assertSame(1, WorkoutSession::count(), 'el índice único hizo su trabajo');
        $this->assertSame('sess-carrera', $res->json('data.client_session_id'));
    }

    // ── 18-20. Efectos de finalizar ─────────────────────────────────────

    /** 18. Una rutina no terminada no cuenta como rutina completada. */
    public function test_routine_completion_solo_para_una_sesion_valida(): void
    {
        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'pending', 'sets' => $this->setsSinMarcar()],
        ], 'sess-incompleta')->assertStatus(422);

        $this->assertSame(0, RoutineCompletion::count());

        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
            ['name' => 'Sentadilla', 'order' => 1, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ], 'sess-completa')->assertCreated();

        $this->assertSame(1, RoutineCompletion::count());
    }

    /** 19. Ni suma día de racha. */
    public function test_la_racha_solo_avanza_con_una_sesion_valida(): void
    {
        $this->enviar([
            ['name' => 'Sentadilla', 'order' => 0, 'status' => 'skipped_temporarily', 'skip_reason' => 'time_constraint', 'sets' => $this->setsSinMarcar()],
        ], 'sess-sin-racha')->assertStatus(422);

        $this->assertSame(0, MemberAppActivityDay::where('member_id', $this->member->id)->count());

        $this->enviar([
            ['name' => 'Sentadilla', 'order' => 0, 'status' => 'completed', 'sets' => $this->setsHechas()],
        ], 'sess-con-racha')->assertCreated();

        $this->assertSame(1, MemberAppActivityDay::where('member_id', $this->member->id)->count());
    }

    /**
     * 20. Los récords siguen mirando la serie, no el estado del ejercicio.
     *
     * Un ejercicio marcado como completado con una serie sin marcar no puede
     * fabricar un récord con la carga prescrita: sería un récord que el socio
     * nunca levantó.
     */
    public function test_los_records_siguen_filtrando_por_serie_completada(): void
    {
        $this->enviar([
            ['name' => 'Press de banca', 'order' => 0, 'status' => 'completed', 'sets' => [
                ['set_number' => 1, 'reps' => 10, 'weight_kg' => 50, 'completed' => true],
                ['set_number' => 2, 'reps' => 10, 'weight_kg' => 200, 'completed' => false],
            ]],
        ])->assertCreated();

        $maximo = PersonalRecord::where('member_id', $this->member->id)
            ->where('metric', 'max_weight')
            ->value('value');

        $this->assertEqualsWithDelta(50.0, (float) $maximo, 0.01, 'los 200 kg nunca se levantaron');
    }
}
