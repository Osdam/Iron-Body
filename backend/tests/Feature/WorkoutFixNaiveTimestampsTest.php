<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La reparación de las sesiones que se guardaron con la hora local leída como
 * UTC.
 *
 * Lo que se exige aquí, por encima de que corrija: que NO toque lo que no debe.
 * Un comando que desplaza instantes en producción tiene que ser incapaz de
 * mover una sesión sana, una de otra época o una que ya se guardó bien.
 */
class WorkoutFixNaiveTimestampsTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::create([
            'full_name' => 'Socio Reparado',
            'document_number' => '661661661',
            'phone' => '+573006616616',
            'access_hash' => 'tok-661',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Crea una sesión diciendo qué guardó la app (`completed_at`) y cuándo la
     * escribió PostgreSQL (`created_at`).
     */
    private function makeSession(string $completedAt, string $createdAt, string $id = 'ws-x'): WorkoutSession
    {
        $s = WorkoutSession::create([
            'member_id' => $this->member->id,
            'client_session_id' => $id,
            'completed_at' => Carbon::parse($completedAt, 'UTC'),
            'started_at' => Carbon::parse($completedAt, 'UTC')->subMinutes(30),
            'duration_seconds' => 1800,
            'total_volume_kg' => 1200,
            'total_sets' => 3,
            'total_exercises' => 1,
        ]);

        // `created_at` lo pone la BD; para el test se fuerza.
        WorkoutSession::whereKey($s->id)->update(['created_at' => Carbon::parse($createdAt, 'UTC')]);

        WorkoutSessionSet::create([
            'workout_session_id' => $s->id,
            'exercise_name' => 'Sentadilla',
            'exercise_key' => 'sentadilla',
            'exercise_order' => 0,
            'set_number' => 1,
            'reps' => 10,
            'weight_kg' => 60,
            'completed' => true,
            'performed_at' => Carbon::parse($completedAt, 'UTC'),
        ]);

        return $s->fresh();
    }

    private function utc(WorkoutSession $s): string
    {
        return Carbon::parse($s->fresh()->completed_at)->utc()->format('Y-m-d H:i:s');
    }

    /** El caso real: la 1:16 del lunes guardada como las 20:16 del domingo. */
    public function test_repara_la_sesion_desplazada_cinco_horas(): void
    {
        // La app dijo 01:16 UTC; el servidor la escribió a las 06:16 UTC.
        $s = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41');

        $this->assertSame(
            '2026-08-16',
            Carbon::parse($s->completed_at)->setTimezone('America/Bogota')->toDateString(),
            'antes de reparar aparece el domingo',
        );

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame('2026-08-17 06:16:41', $this->utc($s));
        $this->assertSame(
            '2026-08-17',
            Carbon::parse($s->fresh()->completed_at)->setTimezone('America/Bogota')->toDateString(),
            'después es el lunes, que es cuando entrenó',
        );
    }

    /** El inicio se desplaza igual: la duración no puede cambiar. */
    public function test_conserva_la_duracion_al_desplazar(): void
    {
        $s = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $f = $s->fresh();
        $this->assertSame(
            1800,
            (int) Carbon::parse($f->started_at)->diffInSeconds(Carbon::parse($f->completed_at)),
        );
    }

    /** Las series se fecharon a partir de la sesión y viajan con ella. */
    public function test_arrastra_las_series_de_la_sesion(): void
    {
        $s = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $set = WorkoutSessionSet::where('workout_session_id', $s->id)->firstOrFail();
        $this->assertSame(
            '2026-08-17 06:16:41',
            Carbon::parse($set->performed_at)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /** Sin `--apply` no se escribe ni un byte. */
    public function test_el_simulacro_no_escribe(): void
    {
        $s = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41');

        $this->artisan('workouts:fix-naive-timestamps')->assertSuccessful();

        $this->assertSame('2026-08-17 01:16:41', $this->utc($s), 'el simulacro dejó la fila intacta');
    }

    /** Una sesión sana (guardada con zona) no se toca. */
    public function test_no_toca_una_sesion_sana(): void
    {
        // Guardada bien: el insert ocurre segundos después de terminar.
        $s = $this->makeSession('2026-08-17 06:16:41', '2026-08-17 06:16:43', 'ws-sana');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $this->assertSame('2026-08-17 06:16:41', $this->utc($s));
    }

    /**
     * Una sesión escrita mucho después de entrenar (sincronización diferida) no
     * es una sesión rota: el desfase no cae en la ventana.
     */
    public function test_no_toca_una_sesion_subida_tarde(): void
    {
        $s = $this->makeSession('2026-08-16 14:00:00', '2026-08-17 09:00:00', 'ws-tarde');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $this->assertSame('2026-08-16 14:00:00', $this->utc($s));
    }

    /** Nada creado después del despliegue del arreglo entra en el barrido. */
    public function test_no_toca_nada_posterior_al_arreglo(): void
    {
        $s = $this->makeSession('2026-08-20 01:00:00', '2026-08-20 06:00:00', 'ws-nueva');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $this->assertSame('2026-08-20 01:00:00', $this->utc($s), 'la app ya manda zona: ese desfase es real');
    }

    /** Correr el comando dos veces no vuelve a desplazar. */
    public function test_es_idempotente(): void
    {
        $s = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);
        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $this->assertSame('2026-08-17 06:16:41', $this->utc($s), 'la segunda pasada no encuentra nada que reparar');
    }

    /** `--member` acota el barrido. */
    public function test_acota_por_socio(): void
    {
        $otro = Member::create([
            'full_name' => 'Otro Socio',
            'document_number' => '662662662',
            'phone' => '+573006626626',
            'access_hash' => 'tok-662',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $mio = $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41', 'ws-mio');

        $suyo = WorkoutSession::create([
            'member_id' => $otro->id,
            'client_session_id' => 'ws-suyo',
            'completed_at' => Carbon::parse('2026-08-17 01:16:41', 'UTC'),
            'duration_seconds' => 0,
            'total_volume_kg' => 0,
            'total_sets' => 0,
            'total_exercises' => 0,
        ]);
        WorkoutSession::whereKey($suyo->id)->update(['created_at' => Carbon::parse('2026-08-17 06:16:41', 'UTC')]);

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true, '--member' => $this->member->id]);

        $this->assertSame('2026-08-17 06:16:41', $this->utc($mio));
        $this->assertSame('2026-08-17 01:16:41', $this->utc($suyo), 'el socio excluido queda intacto');
    }

    /** No se borra ninguna fila. */
    public function test_no_borra_nada(): void
    {
        $this->makeSession('2026-08-17 01:16:41', '2026-08-17 06:16:41', 'ws-a');
        $this->makeSession('2026-08-17 00:10:08', '2026-08-17 05:10:08', 'ws-b');

        $this->artisan('workouts:fix-naive-timestamps', ['--apply' => true]);

        $this->assertSame(2, WorkoutSession::count());
        $this->assertSame(2, WorkoutSessionSet::count());
    }
}
