<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Notification;
use App\Services\Classes\ClassBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Las reglas de reservar y cancelar que se endurecieron al llevar las puertas
 * de la app al dominio único de reservas
 * ({@see ClassBookingService}).
 *
 * Cada una era un hueco por el que una llamada directa —o una versión vieja de
 * la app— hacía lo que la pantalla escondía:
 *
 *   · La reserva individual no miraba la sesión: se reservaba una clase que el
 *     entrenador ya había iniciado o cerrado.
 *   · Cancelar miraba la sesión «vigente» de la clase, que podía ser la de la
 *     semana pasada: una clase cerrada (u olvidada abierta) el lunes impedía
 *     cancelar la reserva del lunes siguiente.
 *   · «Organizar mi semana» aceptaba cualquier fecha, aunque la clase no se
 *     dictara ese día: la fila consumía cupo en una sesión que no existe.
 *   · El plan semanal no contaba las reservas heredadas sin fecha, que sí
 *     ocupan cupo al reservar: enseñaba sitio donde la reserva decía «llena».
 *   · «Clase reservada» se avisaba una sola vez en la vida del socio por clase.
 *
 * Cada prueba mira el EFECTO (el código, el texto literal que enseña la app,
 * la fila, el aviso), no qué método se llamó. El reloj se fija como en
 * producción: un instante UTC, escrito en hora de Bogotá.
 */
class ClassBookingRulesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Bogota';

    /** Semana de prueba: del lunes 05/10/2026 al domingo 11/10/2026. */
    private const LUNES = '2026-10-05';

    private const MIERCOLES = '2026-10-07';

    private const LUNES_SIGUIENTE = '2026-10-12';

    private const MIERCOLES_SIGUIENTE = '2026-10-14';

    private const RESERVA_APP = '/api/app/classes/%d/reserve';

    private const RESERVA_HEREDADA = '/api/classes/%d/reserve';

    private const CANCELA_HEREDADA = '/api/classes/%d/cancel';

    private int $doc = 610200300;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    /** «Ahora» como instante UTC (el backend corre en UTC), escrito en hora de Bogotá. */
    private function ahora(string $fechaHoraBogota): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHoraBogota, self::TZ)->utc());
    }

    private function socio(): Member
    {
        $doc = (string) $this->doc++;

        return $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ]));
    }

    private function auth(Member $socio): array
    {
        return ['Authorization' => 'Bearer '.$socio->access_hash];
    }

    /** Por defecto, una clase de los lunes de 09:00 a 11:00. */
    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional',
            'day_of_week' => 'Lunes', 'start_time' => '09:00', 'end_time' => '11:00',
            'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true, 'is_recurring' => true,
        ], $extra));
    }

    /** La sesión que el entrenador inició (y quizá cerró), con horas de Bogotá guardadas como instantes UTC. */
    private function sesion(MyClass $clase, string $fecha, string $inicio, ?string $cierre = null): ClassSession
    {
        return ClassSession::create([
            'class_id' => $clase->id,
            'session_date' => $fecha,
            'started_at' => Carbon::parse("{$fecha} {$inicio}", self::TZ)->utc(),
            'ended_at' => $cierre === null ? null : Carbon::parse("{$fecha} {$cierre}", self::TZ)->utc(),
        ]);
    }

    /** Una reserva ya existente; `null` es una heredada, anterior a `session_date`. */
    private function reservar(MyClass $clase, Member $socio, ?string $fecha): ClassReservation
    {
        return ClassReservation::create([
            'class_id' => $clase->id, 'member_id' => $socio->id,
            'session_date' => $fecha, 'reserved_at' => now(),
        ]);
    }

    private function llamar(string $metodo, string $ruta, MyClass $clase, Member $socio): TestResponse
    {
        return $this->json($metodo, sprintf($ruta, $clase->id), [], $this->auth($socio));
    }

    /** @return list<?string> las fechas que ocupa el socio en la clase, en orden */
    private function fechasDe(MyClass $clase, Member $socio): array
    {
        return ClassReservation::where('class_id', $clase->id)
            ->where('member_id', $socio->id)
            ->orderBy('session_date')
            ->get()
            ->map(fn (ClassReservation $r) => $r->session_date?->toDateString())
            ->all();
    }

    /** @return list<string> claves anti-duplicado de los avisos «Clase reservada» del socio */
    private function avisosDeReserva(Member $socio): array
    {
        return Notification::where('audience', Notification::AUDIENCE_MEMBER)
            ->where('member_id', $socio->id)
            ->where('title', 'Clase reservada')
            ->orderBy('id')
            ->pluck('event_key')
            ->all();
    }

    /** La ocurrencia de la clase en «Organizar mi semana», en la semana de ese lunes. */
    private function enElPlanSemanal(MyClass $clase, Member $socio, string $lunes, string $fecha): array
    {
        $dias = $this->getJson('/api/app/classes/weekly?week_start='.$lunes, $this->auth($socio))
            ->assertOk()
            ->json('days');

        $ocurrencia = collect($dias)
            ->flatMap(fn (array $dia) => $dia['classes'])
            ->first(fn (array $c) => $c['class_id'] === $clase->id && $c['session_date'] === $fecha);
        $this->assertNotNull($ocurrencia, "La clase debe salir el {$fecha} en el plan semanal.");

        return $ocurrencia;
    }

    // ── Proveedores ─────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function puertasDeReserva(): array
    {
        return [
            'app' => [self::RESERVA_APP],
            'heredada' => [self::RESERVA_HEREDADA],
        ];
    }

    /**
     * Puerta × estado de la sesión, con el texto LITERAL que enseña cada puerta
     * (la app publicada lo muestra tal cual).
     *
     * @return array<string, array{string, bool, string}>
     */
    public static function reservaConLaSesionIniciada(): array
    {
        return [
            'app, en curso' => [self::RESERVA_APP, false, 'La clase ya está en curso.'],
            'app, finalizada' => [self::RESERVA_APP, true, 'La clase ya finalizó.'],
            'heredada, en curso' => [self::RESERVA_HEREDADA, false, 'La clase ya está en curso.'],
            'heredada, finalizada' => [self::RESERVA_HEREDADA, true, 'La clase ya finalizó.'],
        ];
    }

    /** @return array<string, array{string, string, bool, string}> */
    public static function cancelacionConLaSesionIniciada(): array
    {
        return [
            'app, en curso' => ['DELETE', self::RESERVA_APP, false, 'La clase ya está en curso.'],
            'app, finalizada' => ['DELETE', self::RESERVA_APP, true, 'La clase ya finalizó.'],
            'heredada, en curso' => ['POST', self::CANCELA_HEREDADA, false, 'La clase ya está en curso.'],
            'heredada, finalizada' => ['POST', self::CANCELA_HEREDADA, true, 'La clase ya finalizó.'],
        ];
    }

    /**
     * Puerta de cancelación × cómo quedó la sesión del lunes pasado: cerrada, u
     * olvidada abierta por el entrenador.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function cancelacionTrasLaSesionDeLaSemanaPasada(): array
    {
        return [
            'app, cerrada' => ['DELETE', self::RESERVA_APP, '10:58'],
            'app, olvidada abierta' => ['DELETE', self::RESERVA_APP, null],
            'heredada, cerrada' => ['POST', self::CANCELA_HEREDADA, '10:58'],
            'heredada, olvidada abierta' => ['POST', self::CANCELA_HEREDADA, null],
        ];
    }

    /**
     * Horario de la clase, una fecha que NO es día suyo y otra que sí.
     *
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function diasEnQueLaClaseNoSeDicta(): array
    {
        return [
            'un miércoles para una clase de los lunes' => [
                ['day_of_week' => 'Lunes', 'start_time' => '18:00', 'end_time' => '19:00'],
                self::MIERCOLES,
                self::LUNES_SIGUIENTE,
            ],
            'otra semana para una clase de fecha única' => [
                ['day_of_week' => 'Jueves', 'start_time' => '07:00', 'end_time' => '08:00', 'is_recurring' => false, 'date_time' => '2026-10-08 07:00:00'],
                '2026-10-15',
                '2026-10-08',
            ],
            'un lunes anterior al inicio de su vigencia' => [
                ['day_of_week' => 'Lunes', 'start_time' => '18:00', 'end_time' => '19:00', 'date_time' => '2026-10-19 00:00:00'],
                self::LUNES_SIGUIENTE,
                '2026-10-19',
            ],
        ];
    }

    // ── Reserva individual ──────────────────────────────────────────────────

    #[DataProvider('reservaConLaSesionIniciada')]
    public function test_la_reserva_individual_se_rechaza_si_la_sesion_de_su_fecha_ya_empezo_o_termino(string $ruta, bool $cerrada, string $mensaje): void
    {
        // Lunes 10:00: la fecha operativa de una clase de los lunes es HOY, aunque
        // su hora ya pasó; lo que la cierra es la sesión, no el reloj.
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase();
        $this->sesion($clase, self::LUNES, '09:01', $cerrada ? '09:50' : null);
        $socio = $this->socio();

        $this->llamar('POST', $ruta, $clase, $socio)
            ->assertStatus(422)
            ->assertExactJson(['message' => $mensaje]);

        $this->assertSame(0, ClassReservation::count(), 'Rechazada sin efectos: ninguna fila.');
        $this->assertSame([], $this->avisosDeReserva($socio), 'Ni aviso de «Clase reservada».');
    }

    #[DataProvider('puertasDeReserva')]
    public function test_la_sesion_cerrada_de_la_semana_pasada_no_impide_reservar_la_siguiente(string $ruta): void
    {
        $this->ahora(self::MIERCOLES.' 10:00');
        $clase = $this->clase();
        $this->sesion($clase, self::LUNES, '09:01', '10:58');
        $socio = $this->socio();

        $this->llamar('POST', $ruta, $clase, $socio)
            ->assertOk()
            ->assertJsonPath('data.is_reserved', true);

        $this->assertSame([self::LUNES_SIGUIENTE], $this->fechasDe($clase, $socio), 'La sesión que cuenta es la de SU fecha.');
    }

    #[DataProvider('puertasDeReserva')]
    public function test_una_sesion_olvidada_abierta_de_la_semana_pasada_no_impide_reservar_la_siguiente(string $ruta): void
    {
        $this->ahora(self::MIERCOLES.' 10:00');
        $clase = $this->clase();
        $this->sesion($clase, self::LUNES, '09:01', null); // el entrenador nunca la cerró
        $socio = $this->socio();

        $this->llamar('POST', $ruta, $clase, $socio)->assertOk();

        $this->assertSame([self::LUNES_SIGUIENTE], $this->fechasDe($clase, $socio), 'No «La clase ya está en curso.»: esa sesión es de otra fecha.');
    }

    #[DataProvider('puertasDeReserva')]
    public function test_una_clase_de_fecha_unica_que_ya_paso_no_se_reserva(string $ruta): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase([
            'day_of_week' => 'Jueves', 'start_time' => '07:00', 'end_time' => '08:00',
            'is_recurring' => false, 'date_time' => '2026-10-01 07:00:00',
        ]);
        $socio = $this->socio();

        $this->llamar('POST', $ruta, $clase, $socio)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Esta clase ya pasó.']);

        $this->assertSame(0, ClassReservation::count(), 'Ninguna fila en una sesión vencida.');
    }

    // ── Cancelación ─────────────────────────────────────────────────────────

    #[DataProvider('cancelacionTrasLaSesionDeLaSemanaPasada')]
    public function test_la_sesion_de_la_semana_pasada_no_bloquea_cancelar_la_siguiente(string $metodo, string $ruta, ?string $cierre): void
    {
        $this->ahora(self::MIERCOLES.' 10:00');
        $clase = $this->clase();
        $this->sesion($clase, self::LUNES, '09:01', $cierre);
        $socio = $this->socio();
        $this->reservar($clase, $socio, self::LUNES_SIGUIENTE);

        $this->llamar($metodo, $ruta, $clase, $socio)
            ->assertOk()
            ->assertJsonPath('data.is_reserved', false);

        $this->assertSame([], $this->fechasDe($clase, $socio), 'El cupo del lunes 12 queda libre.');
    }

    #[DataProvider('cancelacionConLaSesionIniciada')]
    public function test_no_se_cancela_la_reserva_de_una_sesion_que_ya_empezo_o_termino(string $metodo, string $ruta, bool $cerrada, string $mensaje): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase();
        $socio = $this->socio();
        $this->reservar($clase, $socio, self::LUNES);
        $this->sesion($clase, self::LUNES, '09:01', $cerrada ? '09:50' : null);

        $this->llamar($metodo, $ruta, $clase, $socio)
            ->assertStatus(422)
            ->assertExactJson(['message' => $mensaje]);

        $this->assertSame([self::LUNES], $this->fechasDe($clase, $socio), 'La reserva sigue: nada se borró.');
    }

    // ── «Organizar mi semana» ───────────────────────────────────────────────

    #[DataProvider('diasEnQueLaClaseNoSeDicta')]
    public function test_el_plan_semanal_no_reserva_un_dia_en_que_la_clase_no_se_dicta(array $horario, string $diaSinClase, string $diaDeClase): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase($horario);
        $socio = $this->socio();

        $this->postJson('/api/app/classes/weekly/reserve', ['items' => [
            ['class_id' => $clase->id, 'session_date' => $diaSinClase],
            ['class_id' => $clase->id, 'session_date' => $diaDeClase],
        ]], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('summary.unavailable', 1)
            ->assertJsonPath('summary.reserved', 1)
            ->assertJsonPath('results.unavailable.0.session_date', $diaSinClase)
            ->assertJsonPath('results.reserved.0.session_date', $diaDeClase);

        $this->assertSame([$diaDeClase], $this->fechasDe($clase, $socio), 'Ninguna fila en un día sin clase.');
    }

    public function test_el_plan_semanal_cuenta_en_el_cupo_las_reservas_heredadas_sin_fecha(): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase(['day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00', 'max_capacity' => 3]);
        $this->reservar($clase, $this->socio(), null);
        $this->reservar($clase, $this->socio(), null);
        $this->reservar($clase, $this->socio(), self::MIERCOLES);
        $this->reservar($clase, $this->socio(), self::MIERCOLES_SIGUIENTE);
        $socio = $this->socio();

        $estaSemana = $this->enElPlanSemanal($clase, $socio, self::LUNES, self::MIERCOLES);
        $this->assertSame(3, $estaSemana['booked_spots'], 'Las dos heredadas y la del 7; la del 14 es de otra sesión.');
        $this->assertSame(0, $estaSemana['available_spots']);
        $this->assertTrue($estaSemana['is_full']);
        $this->assertFalse($estaSemana['can_reserve']);

        // Una heredada ocupa TODAS las ocurrencias de su clase, igual que al reservar.
        $siguiente = $this->enElPlanSemanal($clase, $socio, self::LUNES_SIGUIENTE, self::MIERCOLES_SIGUIENTE);
        $this->assertSame(3, $siguiente['booked_spots'], 'Las dos heredadas y la del 14.');

        // Lo que enseña el plan es lo que responde la reserva: llena.
        $this->postJson('/api/app/classes/weekly/reserve', ['items' => [
            ['class_id' => $clase->id, 'session_date' => self::MIERCOLES],
        ]], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('summary.full', 1)
            ->assertJsonPath('summary.reserved', 0);
        $this->assertSame([], $this->fechasDe($clase, $socio));
    }

    // ── Avisos al socio ─────────────────────────────────────────────────────

    public function test_reservar_la_misma_clase_dos_semanas_avisa_las_dos_reservas(): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase(['day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00']);
        $socio = $this->socio();

        $this->llamar('POST', self::RESERVA_APP, $clase, $socio)->assertOk();

        // Ya pasó la clase del 7: la reserva individual cae ahora en la del 14.
        $this->ahora('2026-10-08 10:00');
        $this->llamar('POST', self::RESERVA_APP, $clase, $socio)->assertOk();

        $this->assertSame([self::MIERCOLES, self::MIERCOLES_SIGUIENTE], $this->fechasDe($clase, $socio));
        $this->assertSame([
            "class_reserved_{$clase->id}_{$socio->id}_".self::MIERCOLES,
            "class_reserved_{$clase->id}_{$socio->id}_".self::MIERCOLES_SIGUIENTE,
        ], $this->avisosDeReserva($socio), 'Un aviso por sesión, cada uno con su fecha en la clave.');
    }

    public function test_repetir_la_reserva_de_la_misma_fecha_no_duplica_el_aviso(): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase(['day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00']);
        $socio = $this->socio();

        $this->llamar('POST', self::RESERVA_APP, $clase, $socio)->assertOk();
        $this->llamar('POST', self::RESERVA_APP, $clase, $socio)->assertStatus(422);
        $this->llamar('DELETE', self::RESERVA_APP, $clase, $socio)->assertOk();
        $this->llamar('POST', self::RESERVA_APP, $clase, $socio)->assertOk();

        $this->assertSame([self::MIERCOLES], $this->fechasDe($clase, $socio));
        $this->assertCount(1, $this->avisosDeReserva($socio), 'Un solo «Clase reservada» para la sesión del 7.');
    }
}
