<?php

namespace Tests\Feature\Classes;

use App\Models\Admin;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Services\Classes\ClassBookingService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La lista de inscritos del CRM: GET /api/classes/{id}/reservations.
 *
 * Antes devolvía TODAS las reservas de la clase, de cualquier semana, y
 * `booked_spots` las sumaba: no cuadraba con el cupo de la app ni con el del
 * entrenador. Enseñaba email y teléfono a cualquiera que pudiera ver clases, y
 * un inscrito que quien mira no puede ver (cuenta de entrenador, socio fuera de
 * su alcance) tumbaba la respuesta con un 500.
 *
 * Ahora es la lista de UNA ocurrencia —la operativa, o la que se pida— con la
 * MISMA condición que cuenta el cupo: reservas de esa fecha más las heredadas
 * sin fecha. Por eso cada lista se cruza con el cupo del dominio
 * ({@see ClassBookingService::bookedCount()}) y no con un número escrito aquí.
 *
 * Un socio dado de baja no deja reservas huérfanas: la FK
 * `class_reservations.member_id → members` es ON DELETE CASCADE. La reserva sin
 * socio que sí llega al controlador es la de un inscrito que quien mira no
 * puede ver, y esa es la que se prueba.
 *
 * Reloj: siempre un instante UTC, como corre producción; el día es el de
 * Bogotá.
 */
class ClassReservationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Bogota';

    /** Lunes 05/10/2026 10:00 en Bogotá (15:00 UTC). */
    private const LUNES = '2026-10-05 10:00';

    /** Ocurrencia operativa de una clase de los miércoles ese lunes. */
    private const OPERATIVA = '2026-10-07';

    private int $doc = 700200300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj(self::LUNES);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    /** Congela el reloj en esa hora de pared de Bogotá, como instante UTC. */
    private function reloj(string $horaEnBogota): void
    {
        Carbon::setTestNow(Carbon::parse($horaEnBogota, self::TZ)->utc());
    }

    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Spinning', 'type' => 'Spinning',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true,
        ], $extra));
    }

    private function socio(string $nombre): Member
    {
        $doc = (string) $this->doc++;

        return Member::create([
            'full_name' => $nombre,
            'document_number' => $doc,
            'email' => 'socio-'.$doc.'@ironbody.test',
            'phone' => '+57300'.substr($doc, -7),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    /** Una reserva con su fecha de sesión (null = heredada) y su hora de llegada en Bogotá. */
    private function reserva(MyClass $clase, Member $socio, ?string $fecha, string $llegadaEnBogota): ClassReservation
    {
        return ClassReservation::create([
            'class_id' => $clase->id,
            'member_id' => $socio->id,
            'session_date' => $fecha,
            // Instante UTC: Eloquent guarda la hora de pared del Carbon sin convertirla.
            'reserved_at' => Carbon::parse($llegadaEnBogota, self::TZ)->utc(),
        ]);
    }

    /**
     * Una clase de los miércoles con reservas en cuatro semanas y una heredada
     * sin fecha. Dos de la misma sesión se crean en orden inverso al de
     * llegada: la lista va por llegada, no por id. Y otra clase del mismo día,
     * también con una heredada, que no puede colarse nunca.
     *
     * @return array{0: MyClass, 1: array<string, ClassReservation>}
     */
    private function claseConVariasSemanas(): array
    {
        $clase = $this->clase();

        $reservas = [
            'heredada' => $this->reserva($clase, $this->socio('Heredada'), null, '2026-06-01 08:00'),
            'semana_pasada' => $this->reserva($clase, $this->socio('Semana pasada'), '2026-09-30', '2026-09-28 09:00'),
            'llega_tarde' => $this->reserva($clase, $this->socio('Llega tarde'), '2026-10-07', '2026-10-01 09:00'),
            'llega_pronto' => $this->reserva($clase, $this->socio('Llega pronto'), '2026-10-07', '2026-09-29 09:00'),
            'semana_que_viene' => $this->reserva($clase, $this->socio('Semana que viene'), '2026-10-14', '2026-10-02 09:00'),
            'dentro_de_dos' => $this->reserva($clase, $this->socio('Dentro de dos'), '2026-10-21', '2026-10-03 09:00'),
        ];

        $otra = $this->clase(['name' => 'Yoga', 'type' => 'Yoga']);
        $this->reserva($otra, $this->socio('Otra clase heredada'), null, '2026-06-02 08:00');
        $this->reserva($otra, $this->socio('Otra clase miércoles'), '2026-10-07', '2026-10-01 10:00');

        return [$clase, $reservas];
    }

    private function lista(MyClass $clase, array $headers, ?string $fecha = null): TestResponse
    {
        $query = $fecha === null ? '' : '?'.http_build_query(['session_date' => $fecha]);

        return $this->getJson("/api/classes/{$clase->id}/reservations{$query}", $headers);
    }

    /** @return list<int> los ids de reserva, en el orden en que salen */
    private function ids(TestResponse $respuesta): array
    {
        return array_column($respuesta->json('reservations'), 'reservation_id');
    }

    /**
     * @param  array<string, ClassReservation>  $reservas
     * @param  list<string>  $claves
     * @return list<int>
     */
    private function idsDe(array $reservas, array $claves): array
    {
        return array_map(fn (string $clave) => $reservas[$clave]->id, $claves);
    }

    /** El cupo ocupado según el dominio: lo que cuenta la app al reservar. */
    private function cupo(MyClass $clase, string $fecha): int
    {
        return app(ClassBookingService::class)->bookedCount($clase, $fecha);
    }

    /** El permiso mínimo para leer la lista. */
    private function verClases(): array
    {
        return $this->adminWithPermissions(['classes.view']);
    }

    // ── Qué ocurrencia se lista ─────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function relojes(): array
    {
        return [
            'lunes 10:00, antes de la clase' => [
                '2026-10-05 10:00', '2026-10-07', ['heredada', 'llega_pronto', 'llega_tarde'],
            ],
            // Ya es jueves en UTC: con el día del servidor saldría la del 14.
            'miércoles 19:30 con la clase en curso (jueves 00:30 UTC)' => [
                '2026-10-07 19:30', '2026-10-07', ['heredada', 'llega_pronto', 'llega_tarde'],
            ],
            'jueves 08:00, la del miércoles ya pasó' => [
                '2026-10-08 08:00', '2026-10-14', ['heredada', 'semana_que_viene'],
            ],
        ];
    }

    #[DataProvider('relojes')]
    public function test_por_defecto_lista_la_ocurrencia_operativa_y_las_heredadas_sin_fecha(
        string $ahora,
        string $operativa,
        array $esperadas,
    ): void {
        $this->reloj($ahora);
        [$clase, $reservas] = $this->claseConVariasSemanas();

        $respuesta = $this->lista($clase, $this->verClases())->assertOk();

        $this->assertSame(
            $this->idsDe($reservas, $esperadas),
            $this->ids($respuesta),
            'Solo esa sesión y las heredadas de esta clase, por orden de llegada: ni otras semanas, ni las pasadas, ni otra clase.',
        );
        $this->assertSame(count($esperadas), $respuesta->json('booked_spots'));
        $this->assertSame(
            $this->cupo($clase, $operativa),
            $respuesta->json('booked_spots'),
            'La lista cuenta lo mismo que el cupo al reservar.',
        );
        $respuesta
            ->assertJsonPath('session_date', $operativa)
            ->assertJsonPath('class_id', $clase->id)
            ->assertJsonPath('class_name', 'Spinning')
            ->assertJsonPath('max_capacity', 20);

        // Y lo mismo que enseña la ficha de la clase en la app.
        $this->getJson("/api/classes/{$clase->id}")
            ->assertOk()
            ->assertJsonPath('data.booked_spots', count($esperadas));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function otrasOcurrencias(): array
    {
        return [
            'la de la semana que viene' => ['2026-10-14', ['heredada', 'semana_que_viene']],
            'una que ya pasó' => ['2026-09-30', ['heredada', 'semana_pasada']],
        ];
    }

    #[DataProvider('otrasOcurrencias')]
    public function test_con_session_date_de_otra_ocurrencia_lista_la_de_esa_fecha(string $fecha, array $esperadas): void
    {
        [$clase, $reservas] = $this->claseConVariasSemanas();

        $respuesta = $this->lista($clase, $this->verClases(), $fecha)->assertOk();

        $this->assertSame(
            $this->idsDe($reservas, $esperadas),
            $this->ids($respuesta),
            'Las de esa fecha y las heredadas, nada de otras semanas.',
        );
        $this->assertSame(count($esperadas), $respuesta->json('booked_spots'));
        $this->assertSame($this->cupo($clase, $fecha), $respuesta->json('booked_spots'));
        $respuesta->assertJsonPath('session_date', $fecha);
    }

    public function test_cada_fila_dice_de_que_sesion_es(): void
    {
        [$clase, $reservas] = $this->claseConVariasSemanas();

        // La heredada no tiene fecha propia: ocupa todas las sesiones, y así se
        // distingue en la lista.
        foreach ([
            [null, [
                $reservas['heredada']->id => null,
                $reservas['llega_pronto']->id => '2026-10-07',
                $reservas['llega_tarde']->id => '2026-10-07',
            ]],
            ['2026-10-14', [
                $reservas['heredada']->id => null,
                $reservas['semana_que_viene']->id => '2026-10-14',
            ]],
        ] as [$pedida, $esperado]) {
            $filas = $this->lista($clase, $this->verClases(), $pedida)
                ->assertOk()
                ->json('reservations');

            foreach ($filas as $fila) {
                $this->assertArrayHasKey('session_date', $fila, 'Cada fila dice de qué sesión es.');
            }
            $this->assertSame($esperado, array_column($filas, 'session_date', 'reservation_id'));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function fechasMalFormadas(): array
    {
        return [
            'fecha imposible' => ['2026-02-30'],
            'sin ceros a la izquierda' => ['2026-10-7'],
            'con hora' => ['2026-10-07T19:00:00'],
        ];
    }

    #[DataProvider('fechasMalFormadas')]
    public function test_una_session_date_mal_formada_es_error_de_validacion(string $fecha): void
    {
        $clase = $this->clase();
        $this->reserva($clase, $this->socio('Socio Oculto'), self::OPERATIVA, '2026-10-01 09:00');

        $this->lista($clase, $this->verClases(), $fecha)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_date')
            ->assertJsonMissingPath('reservations');
    }

    // ── Qué datos del socio viajan ──────────────────────────────────────────

    /** @return array<string, array{0: list<string>, 1: bool}> */
    public static function permisosDeContacto(): array
    {
        return [
            'solo ver clases' => [['classes.view'], false],
            'ver clases y fichas de socios' => [['classes.view', 'members.view'], true],
        ];
    }

    #[DataProvider('permisosDeContacto')]
    public function test_email_y_telefono_solo_con_permiso_de_ver_fichas(array $permisos, bool $veContacto): void
    {
        $clase = $this->clase();
        $socio = $this->socio('Socia Con Contacto');
        $reserva = $this->reserva($clase, $socio, self::OPERATIVA, '2026-10-01 09:00');

        $respuesta = $this->lista($clase, $this->adminWithPermissions($permisos))->assertOk();
        $fila = $respuesta->json('reservations.0');

        // El resto de la fila no depende del permiso.
        $this->assertSame($reserva->id, $fila['reservation_id']);
        $this->assertTrue(
            Carbon::parse($fila['reserved_at'])->equalTo(Carbon::parse('2026-10-01 09:00', self::TZ)),
            'La hora de llegada es el instante real, sin desfase de zona.',
        );
        $this->assertSame($socio->id, $fila['member']['id']);
        $this->assertSame($socio->member_uuid, $fila['member']['uuid']);
        $this->assertSame('Socia Con Contacto', $fila['member']['full_name']);

        if ($veContacto) {
            $this->assertSame($socio->email, $fila['member']['email']);
            $this->assertSame($socio->phone, $fila['member']['phone']);

            return;
        }

        $this->assertNull($fila['member']['email'] ?? null);
        $this->assertNull($fila['member']['phone'] ?? null);
        // Tampoco en otra clave: el dato no viaja.
        $respuesta->assertDontSee($socio->email)->assertDontSee($socio->phone);
    }

    // ── Quién puede leerla ──────────────────────────────────────────────────

    /** @return array<string, array{0: list<string>}> */
    public static function permisosSinVerClases(): array
    {
        return [
            'ningún permiso' => [[]],
            'solo fichas de socios' => [['members.view']],
            'solo inscribir en clases' => [['classes.enroll']],
        ];
    }

    #[DataProvider('permisosSinVerClases')]
    public function test_sin_classes_view_es_403_y_no_trae_la_lista(array $permisos): void
    {
        $clase = $this->clase();
        $this->reserva($clase, $this->socio('Socio Oculto'), self::OPERATIVA, '2026-10-01 09:00');

        $this->lista($clase, $this->adminWithPermissions($permisos))
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'classes.view')
            ->assertJsonMissingPath('reservations')
            ->assertDontSee('Socio Oculto');
    }

    public function test_sin_sesion_es_401_y_no_trae_la_lista(): void
    {
        $clase = $this->clase();
        $this->reserva($clase, $this->socio('Socio Oculto'), self::OPERATIVA, '2026-10-01 09:00');

        $this->lista($clase, [])
            ->assertUnauthorized()
            ->assertJsonMissingPath('reservations')
            ->assertDontSee('Socio Oculto');
    }

    public function test_el_token_de_un_socio_de_la_app_no_abre_la_lista(): void
    {
        $clase = $this->clase();
        $curioso = $this->socio('Socio Curioso');
        $this->reserva($clase, $this->socio('Socio Oculto'), self::OPERATIVA, '2026-10-01 09:00');

        $this->lista($clase, ['Authorization' => 'Bearer '.$curioso->access_hash])
            ->assertForbidden()
            ->assertJsonMissingPath('reservations')
            ->assertDontSee('Socio Oculto');
    }

    // ── Reservas sin socio ──────────────────────────────────────────────────

    public function test_dar_de_baja_al_socio_borra_sus_reservas_en_cascada_y_la_lista_sigue_respondiendo(): void
    {
        $clase = $this->clase();
        $sigue = $this->reserva($clase, $this->socio('Socio Que Sigue'), self::OPERATIVA, '2026-10-01 09:00');
        $baja = $this->socio('Socio Dado De Baja');
        $suya = $this->reserva($clase, $baja, self::OPERATIVA, '2026-10-02 09:00');
        $suyaHeredada = $this->reserva($clase, $baja, null, '2026-06-01 08:00');

        // Lo mismo que hace «Dar de baja» en el CRM (UserController::destroy).
        $baja->delete();

        // La FK `class_reservations.member_id → members` es ON DELETE CASCADE:
        // no queda ninguna reserva apuntando a un socio que ya no existe.
        $this->assertFalse(
            ClassReservation::whereKey([$suya->id, $suyaHeredada->id])->exists(),
            'Las reservas se van con el socio (FK en cascada).',
        );

        $respuesta = $this->lista($clase, $this->adminWithPermissions(['classes.view', 'members.view']))
            ->assertOk()
            ->assertJsonPath('booked_spots', 1)
            ->assertDontSee('Socio Dado De Baja');

        $this->assertSame([$sigue->id], $this->ids($respuesta));
        $this->assertSame($this->cupo($clase, self::OPERATIVA), $respuesta->json('booked_spots'));
    }

    public function test_con_cuenta_de_entrenador_el_inscrito_fuera_de_su_alcance_sale_sin_datos_y_no_rompe_la_lista(): void
    {
        // La cuenta de entrenador solo ve a sus socios (scope global de Member):
        // la reserva de otro llega al controlador con `member` a null. Antes,
        // `$r->member->id` daba un 500 y el entrenador se quedaba sin lista.
        $coach = Trainer::create(['full_name' => 'Coach Lista', 'document' => '700999001', 'status' => 'active']);
        $clase = $this->clase(['trainer_id' => $coach->id]);
        $suyo = $this->socio('Socio Del Coach');
        $ajeno = $this->socio('Socio Ajeno Zeta');
        MemberTrainerAssignment::create([
            'member_id' => $suyo->id, 'trainer_id' => $coach->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now(),
        ]);
        $delSuyo = $this->reserva($clase, $suyo, self::OPERATIVA, '2026-10-01 09:00');
        $delAjeno = $this->reserva($clase, $ajeno, self::OPERATIVA, '2026-10-02 09:00');
        $cuenta = Admin::create([
            'name' => 'Cuenta del coach', 'email' => 'coach-lista@ironbody.test', 'password' => 'secret-password',
            'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $coach->id, 'status' => 'active',
        ]);

        $respuesta = $this->lista($clase, $this->actingAsAdmin($cuenta))->assertOk();

        // El cupo es de la clase, no del alcance de quien mira.
        $this->assertSame([$delSuyo->id, $delAjeno->id], $this->ids($respuesta));
        $this->assertSame($this->cupo($clase, self::OPERATIVA), $respuesta->json('booked_spots'));
        $respuesta
            ->assertJsonPath('reservations.0.member.full_name', 'Socio Del Coach')
            ->assertJsonPath('reservations.1.member', null)
            ->assertDontSee('Socio Ajeno Zeta')
            ->assertDontSee($ajeno->email)
            ->assertDontSee($ajeno->phone);
    }

    public function test_un_dia_en_que_la_clase_no_se_dicta_no_tiene_lista(): void
    {
        $clase = MyClass::create([
            'name' => 'Solo miércoles', 'type' => 'Funcional', 'day_of_week' => 'Miércoles', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);

        $this->adminGetJson("/api/classes/{$clase->id}/reservations?session_date=2026-10-08")
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_an_occurrence');
    }
}
