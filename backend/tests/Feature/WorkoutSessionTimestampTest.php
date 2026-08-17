<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El instante en que se entrenó, de extremo a extremo.
 *
 * EL FALLO REAL
 * -------------
 * `DateTime.now().toIso8601String()` de Dart emite la hora LOCAL sin ningún
 * designador de zona: `2026-08-17T01:16:41.123`. El backend la leía como UTC,
 * así que cada entrenamiento quedaba archivado 5 horas en el pasado. Uno hecho
 * el LUNES a la 1:16 de la madrugada se guardaba como las 20:16 del DOMINGO y
 * desaparecía de "esta semana". En producción los seis entrenamientos afectados
 * mostraban exactamente esa diferencia entre `created_at` (reloj del servidor) y
 * `completed_at` (lo que mandó la app).
 *
 * Aquí se comprueban las dos mitades del arreglo:
 *  - la app ahora manda UTC inequívoco (`...Z`) y el instante se conserva;
 *  - un timestamp legacy SIN zona se interpreta como Bogotá, no como UTC.
 *
 * Las horas elegidas son las que rompían: 00:01 y 01:16 (madrugada, cambian de
 * día al desplazarse) y 23:59 (fin de día, cambia de día en el otro sentido).
 */
class WorkoutSessionTimestampTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::create([
            'full_name' => 'Socio Horario',
            'document_number' => '551551551',
            'phone' => '+573005515515',
            'access_hash' => 'tok-551',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    /**
     * Manda una sesión con los timestamps EXACTOS que se le pasen, tal como
     * viajarían por la red.
     */
    private function sendSession(string $startedAt, string $completedAt, string $clientId): void
    {
        $this->postJson('/api/app/workout-sessions', [
            'client_session_id' => $clientId,
            'routine_name' => 'Full body',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'exercises' => [
                [
                    'name' => 'Sentadilla',
                    'order' => 0,
                    'sets' => [
                        ['set_number' => 1, 'reps' => 10, 'weight_kg' => 60, 'completed' => true],
                        ['set_number' => 2, 'reps' => 10, 'weight_kg' => 60, 'completed' => true],
                    ],
                ],
            ],
        ], $this->auth())->assertSuccessful();
    }

    /** Lo que quedó guardado, en UTC y como cadena comparable. */
    private function storedUtc(string $clientId): string
    {
        $session = WorkoutSession::where('client_session_id', $clientId)->firstOrFail();

        return Carbon::parse($session->completed_at)->utc()->format('Y-m-d H:i:s');
    }

    /** El mismo instante visto en el reloj del gimnasio. */
    private function storedLocal(string $clientId): string
    {
        $session = WorkoutSession::where('client_session_id', $clientId)->firstOrFail();

        return Carbon::parse($session->completed_at)
            ->setTimezone('America/Bogota')->format('Y-m-d H:i:s');
    }

    private function weekly(): array
    {
        return $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data.weekly_training');
    }

    // ---------------------------------------------------------------- app nueva

    /**
     * 00:01 del lunes en Bogotá. La app manda 05:01 UTC del lunes.
     *
     * Es el minuto más frágil de la semana: un minuto antes es domingo, y
     * cualquier desplazamiento hacia atrás lo saca de la semana en curso.
     */
    public function test_las_00_01_de_bogota_se_guardan_como_el_mismo_instante(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:30:00', 'UTC'));

        $this->sendSession('2026-08-17T05:00:00.000Z', '2026-08-17T05:01:00.000Z', 'ws-0001');

        $this->assertSame('2026-08-17 05:01:00', $this->storedUtc('ws-0001'));
        $this->assertSame('2026-08-17 00:01:00', $this->storedLocal('ws-0001'), 'sigue siendo lunes de madrugada');
    }

    /**
     * 01:16 del lunes en Bogotá: la hora EXACTA del entrenamiento que se perdió
     * en producción. Debe almacenarse como 06:16 UTC y volver como lunes.
     */
    public function test_la_01_16_del_lunes_se_almacena_como_06_16_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 06:30:00', 'UTC'));

        $this->sendSession('2026-08-17T05:46:41.123Z', '2026-08-17T06:16:41.123Z', 'ws-0116');

        $this->assertSame('2026-08-17 06:16:41', $this->storedUtc('ws-0116'));
        $this->assertSame('2026-08-17 01:16:41', $this->storedLocal('ws-0116'));

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions'], 'el entrenamiento de esta madrugada cuenta en esta semana');
        $this->assertSame(1, $w['total_sessions']);
        $this->assertSame('2026-08-17', $w['week_start']);

        $hoy = collect($w['days'])->firstWhere('is_today', true);
        $this->assertSame('lunes', $hoy['weekday'], 'entrenó el lunes, no el domingo');
        $this->assertSame('2026-08-17', $hoy['date']);
        $this->assertSame(1, $hoy['sessions']);
        $this->assertEqualsWithDelta(1200, $hoy['volume_kg'], 0.01);

        $domingo = collect($w['days'])->firstWhere('weekday', 'domingo');
        $this->assertSame(0, $domingo['sessions'], 'no puede aparecer también el domingo');
    }

    /** 23:59 del lunes en Bogotá = 04:59 UTC del martes. Sigue siendo lunes. */
    public function test_las_23_59_de_bogota_no_se_pasan_al_dia_siguiente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 05:30:00', 'UTC')); // martes 00:30 Bogotá

        $this->sendSession('2026-08-18T04:29:00.000Z', '2026-08-18T04:59:00.000Z', 'ws-2359');

        $this->assertSame('2026-08-18 04:59:00', $this->storedUtc('ws-2359'));
        $this->assertSame('2026-08-17 23:59:00', $this->storedLocal('ws-2359'));

        $lunes = collect($this->weekly()['days'])->firstWhere('weekday', 'lunes');
        $this->assertSame(1, $lunes['sessions'], 'lo entrenado a las 23:59 es del lunes');
    }

    /** La duración se calcula sobre instantes reales, no sobre horas ingenuas. */
    public function test_la_duracion_sale_de_los_instantes_reales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 06:30:00', 'UTC'));

        $this->sendSession('2026-08-17T05:46:41.123Z', '2026-08-17T06:16:41.123Z', 'ws-dur');

        $session = WorkoutSession::where('client_session_id', 'ws-dur')->firstOrFail();
        $this->assertSame(1800, $session->duration_seconds);
    }

    // --------------------------------------------------- frontera domingo/lunes

    /**
     * TEST DOMINGO/LUNES.
     *
     * Dos entrenamientos consecutivos separados por la medianoche del gimnasio:
     * el domingo a las 23:30 y el lunes a las 00:30 (03:30 y 05:30 UTC). Están a
     * una hora de distancia pero pertenecen a SEMANAS DISTINTAS, y ese es
     * justamente el corte que el desplazamiento de 5 horas rompía.
     */
    public function test_el_domingo_tarde_y_el_lunes_de_madrugada_caen_en_semanas_distintas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC')); // lunes 07:00 Bogotá

        $this->sendSession('2026-08-17T03:00:00.000Z', '2026-08-17T04:30:00.000Z', 'ws-dom'); // dom 23:30
        $this->sendSession('2026-08-17T05:00:00.000Z', '2026-08-17T05:30:00.000Z', 'ws-lun'); // lun 00:30

        $this->assertSame('2026-08-16 23:30:00', $this->storedLocal('ws-dom'));
        $this->assertSame('2026-08-17 00:30:00', $this->storedLocal('ws-lun'));

        $w = $this->weekly();

        // La semana en curso empieza el lunes 17: solo entra el segundo.
        $this->assertSame('2026-08-17', $w['week_start']);
        $this->assertTrue($w['has_sessions']);
        $this->assertSame(1, $w['total_sessions'], 'el del domingo es de la semana pasada');

        $lunes = collect($w['days'])->firstWhere('weekday', 'lunes');
        $this->assertSame(1, $lunes['sessions']);
        $this->assertTrue($lunes['is_today']);

        // Y el del domingo no se perdió: sigue existiendo, en la semana anterior.
        $this->assertTrue($w['has_previous_sessions']);
        $this->assertSame(2, WorkoutSession::where('member_id', $this->member->id)->count());
    }

    // -------------------------------------------------------- app legacy sin zona

    /**
     * Una app vieja manda la hora local SIN designador. Interpretarla como UTC
     * era el fallo; ahora se lee explícitamente como Bogotá.
     */
    public function test_un_timestamp_legacy_sin_zona_se_lee_como_bogota(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC'));

        $this->sendSession('2026-08-17T00:46:41.123', '2026-08-17T01:16:41.123', 'ws-legacy');

        $this->assertSame('2026-08-17 06:16:41', $this->storedUtc('ws-legacy'), 'la 1:16 de Bogotá son las 6:16 UTC');
        $this->assertSame('2026-08-17 01:16:41', $this->storedLocal('ws-legacy'));

        $hoy = collect($this->weekly()['days'])->firstWhere('is_today', true);
        $this->assertSame('lunes', $hoy['weekday']);
        $this->assertSame(1, $hoy['sessions']);
    }

    /** Formato legacy con espacio en vez de `T`, y sin segundos. */
    public function test_el_formato_legacy_con_espacio_tambien_se_lee_como_bogota(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC'));

        $this->sendSession('2026-08-17 00:46', '2026-08-17 01:16', 'ws-legacy-2');

        $this->assertSame('2026-08-17 06:16:00', $this->storedUtc('ws-legacy-2'));
    }

    /** Un offset explícito distinto de Z se respeta: manda el instante. */
    public function test_un_offset_explicito_se_respeta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC'));

        $this->sendSession('2026-08-17T00:46:41-05:00', '2026-08-17T01:16:41-05:00', 'ws-offset');

        $this->assertSame('2026-08-17 06:16:41', $this->storedUtc('ws-offset'));
        $this->assertSame('2026-08-17 01:16:41', $this->storedLocal('ws-offset'));
    }

    /**
     * Un reloj en otra zona (un socio de viaje) manda su propio offset: el
     * instante se conserva y NO se reinterpreta como si fuera de Bogotá.
     */
    public function test_un_offset_ajeno_no_se_reinterpreta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC'));

        $this->sendSession('2026-08-17T09:00:00+02:00', '2026-08-17T10:00:00+02:00', 'ws-madrid');

        $this->assertSame('2026-08-17 08:00:00', $this->storedUtc('ws-madrid'));
        $this->assertSame('2026-08-17 03:00:00', $this->storedLocal('ws-madrid'));
    }

    /** Una fecha ilegible no puede tumbar el registro de lo ya entrenado. */
    public function test_una_fecha_invalida_no_rompe_el_registro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'UTC'));

        $this->postJson('/api/app/workout-sessions', [
            'client_session_id' => 'ws-basura',
            'routine_name' => 'Full body',
            'completed_at' => null,
            'exercises' => [
                ['name' => 'Sentadilla', 'order' => 0, 'sets' => [
                    ['set_number' => 1, 'reps' => 10, 'weight_kg' => 60, 'completed' => true],
                ]],
            ],
        ], $this->auth())->assertSuccessful();

        $session = WorkoutSession::where('client_session_id', 'ws-basura')->firstOrFail();
        $this->assertNotNull($session->completed_at, 'sin fecha usable se sella con la hora del servidor');
        $this->assertSame(0, $session->duration_seconds, 'sin inicio no se inventa duración');
    }
}
