<?php

namespace Tests\Feature\Classes;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Http\Controllers\Api\Admin\ClassEnrollmentController;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CatalogEvent;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MemberRiskLock;
use App\Models\MemberSuspension;
use App\Models\MemberTrainerAssignment;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Models\TrainerRole;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use App\Services\Classes\ClassBookingService;
use App\Services\Identity\IdentityLinkService;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Moderation\ModerationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inscribir desde el CRM es la MISMA reserva que hace la app.
 *
 * Lo que se protege aquí no es un endpoint: es que el mostrador sea un
 * consumidor más del dominio de reservas. Mismo cupo, mismas reglas, misma
 * fila en `class_reservations`, los mismos avisos para la app y el
 * entrenador. Cada prueba mira el EFECTO (la fila, la respuesta de la app, la
 * traza), no qué método se llamó.
 */
class ClassEnrollmentFromCrmTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes 05/10/2026 10:00 en Bogotá. */
    private const NOW_UTC = '2026-10-05 15:00:00';

    /** Ocurrencia operativa de una clase de los miércoles. */
    private const OCCURRENCE = '2026-10-07';

    private int $doc = 900100100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function socio(bool $conClases = true, array $extra = []): Member
    {
        $doc = (string) $this->doc++;
        $m = Member::create(array_merge([
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ], $extra));

        if ($conClases) {
            return $this->givePlanWithClasses($m);
        }

        // Cuenta de app SIN plan de clases: tiene User, así que se le puede
        // buscar en el CRM, pero su plan no incluye clases.
        $user = User::create([
            'name' => $m->full_name, 'email' => 'sin-plan-'.$doc.'@ironbody.test',
            'password' => bcrypt('secret-password'), 'document' => $doc, 'status' => 'active',
        ]);
        $m->forceFill(['user_id' => $user->id])->save();

        return $m->refresh();
    }

    private function clase(int $cupo = 20, array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Spinning', 'type' => 'Spinning',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'status' => 'active', 'max_capacity' => $cupo, 'allow_online_booking' => true,
        ], $extra));
    }

    private function recepcion(): array
    {
        return $this->adminAs(Admin::ROLE_RECEPCION);
    }

    private function inscribir(MyClass $clase, Member $socio, array $extra = [], ?array $headers = null)
    {
        return $this->postJson(
            "/api/admin/classes/{$clase->id}/enrollments",
            array_merge(['user_id' => $socio->user_id], $extra),
            $headers ?? $this->recepcion(),
        );
    }

    private function quitar(MyClass $clase, Member $socio, string $fecha = self::OCCURRENCE, ?array $headers = null)
    {
        return $this->deleteJson(
            "/api/admin/classes/{$clase->id}/enrollments/{$socio->id}?session_date={$fecha}",
            [],
            $headers ?? $this->recepcion(),
        );
    }

    private function appAuth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    /** Avisos a la app de TODOS los socios (canal global, una fila por hecho). */
    private function señales(): int
    {
        return CatalogEvent::where(function ($q): void {
            $q->where('type', 'like', 'class.%')->orWhere('type', 'like', 'reservation.%');
        })->count();
    }

    private function deudaVencida(Member $socio): void
    {
        config(['caja.payment_terms.gym' => 15]);
        $cajero = Admin::create([
            'name' => 'Cajero', 'email' => 'cajero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        app(CashShiftService::class)->open($cajero, CashShiftType::GYM);
        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: CashShiftType::GYM,
            concept: 'Plan Premium',
            total: Money::fromAmount(200000),
            actor: $cajero,
            dueAt: Carbon::parse('2026-10-01'),
        );
    }

    // ── La inscripción ES la reserva de la app ─────────────────────────────

    public function test_recepcion_inscribe_y_la_app_ve_la_reserva_como_suya(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();

        $this->inscribir($clase, $socio)
            ->assertOk()
            ->assertJsonPath('outcome', 'reserved')
            ->assertJsonPath('data.session_date', self::OCCURRENCE)
            ->assertJsonPath('data.booked', 1)
            ->assertJsonPath('data.available', 19)
            ->assertJsonPath('data.enrollments.0.member_id', $socio->id)
            ->assertJsonPath('data.enrollments.0.user_id', $socio->user_id);

        // La misma fila que habría creado la app: misma fecha, mismo socio.
        $fila = ClassReservation::where('class_id', $clase->id)->sole();
        $this->assertSame($socio->id, (int) $fila->member_id);
        $this->assertSame(self::OCCURRENCE, $fila->session_date->toDateString());

        // Y la app del socio la ve reservada, con el cupo descontado.
        $enApp = collect($this->getJson('/api/app/classes', $this->appAuth($socio))->assertOk()->json('data'))
            ->firstWhere('id', (string) $clase->id);
        $this->assertTrue($enApp['is_reserved']);
        $this->assertSame(1, $enApp['booked_spots']);

        // Y puede cancelarla ella misma desde la app, como cualquier reserva.
        $this->postJson("/api/classes/{$clase->id}/cancel", [], $this->appAuth($socio))->assertOk();
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_el_buscador_emite_users_id_y_se_inscribe_al_socio_correcto(): void
    {
        // Un User sin socio desplaza la numeración: a partir de aquí users.id y
        // members.id NO coinciden. Justo el caso en que `Member::find(user_id)`
        // inscribiría a otra persona.
        User::create([
            'name' => 'Sin socio', 'email' => 'relleno@ironbody.test',
            'password' => bcrypt('x'), 'document' => '1', 'status' => 'active',
        ]);
        $ana = $this->socio();
        $beto = $this->socio();
        $this->assertSame($beto->id, $ana->user_id, 'Precondición: el user_id de Ana es el members.id de Beto.');

        $clase = $this->clase();
        $this->inscribir($clase, $ana)->assertOk();

        $this->assertTrue(ClassReservation::where('member_id', $ana->id)->exists());
        $this->assertFalse(ClassReservation::where('member_id', $beto->id)->exists());
    }

    public function test_una_persona_sin_cuenta_de_socio_no_se_inscribe(): void
    {
        $clase = $this->clase();

        $this->postJson("/api/admin/classes/{$clase->id}/enrollments", ['user_id' => 999999], $this->recepcion())
            ->assertStatus(422)
            ->assertJsonPath('code', 'member_not_found');
        $this->assertSame(0, ClassReservation::count());
    }

    // ── Idempotencia ───────────────────────────────────────────────────────

    public function test_inscribir_dos_veces_es_exito_sin_segunda_fila_ni_segundo_aviso(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();

        $this->inscribir($clase, $socio, [], $cabeceras)->assertOk()->assertJsonPath('outcome', 'reserved');
        $señales = $this->señales();
        $trazas = AuditLog::where('action', 'enroll')->count();

        $this->inscribir($clase, $socio, [], $cabeceras)
            ->assertOk()
            ->assertJsonPath('outcome', 'already')
            ->assertJsonPath('data.booked', 1);

        $this->assertSame(1, ClassReservation::count());
        $this->assertSame($señales, $this->señales(), '«Ya estaba» no movió ningún cupo: no se anuncia.');
        $this->assertSame($trazas, AuditLog::where('action', 'enroll')->count(), 'Ni se audita lo que no pasó.');
    }

    public function test_si_ya_reservo_desde_la_app_el_mostrador_no_duplica(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($socio))->assertOk();

        $this->inscribir($clase, $socio)->assertOk()->assertJsonPath('outcome', 'already');
        $this->assertSame(1, ClassReservation::count());
    }

    // ── Cupo compartido con la app ─────────────────────────────────────────

    public function test_la_app_ocupa_el_ultimo_cupo_y_el_mostrador_no_lo_sobrepasa(): void
    {
        $clase = $this->clase(1);
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($this->socio()))->assertOk();

        $this->inscribir($clase, $this->socio())
            ->assertStatus(422)
            ->assertJsonPath('code', 'full')
            ->assertJsonPath('message', 'No hay cupos disponibles.');
        $this->assertSame(1, ClassReservation::count());
    }

    public function test_el_mostrador_ocupa_el_ultimo_cupo_y_la_app_no_lo_sobrepasa(): void
    {
        $clase = $this->clase(1);
        $this->inscribir($clase, $this->socio())->assertOk();

        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($this->socio()))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No hay cupos disponibles.']);
        $this->assertSame(1, ClassReservation::count());
    }

    public function test_diecinueve_de_veinte_mas_dos_termina_en_veinte(): void
    {
        $clase = $this->clase(20);
        $cabeceras = $this->recepcion();
        for ($i = 0; $i < 19; $i++) {
            $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertOk();
        }

        $primero = $this->inscribir($clase, $this->socio(), [], $cabeceras);
        $segundo = $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($this->socio()));

        $primero->assertOk()->assertJsonPath('data.booked', 20)->assertJsonPath('data.available', 0);
        $segundo->assertStatus(422);
        $this->assertSame(20, ClassReservation::where('class_id', $clase->id)->count());
    }

    public function test_quitar_libera_el_cupo_para_otro(): void
    {
        $clase = $this->clase(1);
        $ana = $this->socio();
        $beto = $this->socio();
        $cabeceras = $this->recepcion();

        $this->inscribir($clase, $ana, [], $cabeceras)->assertOk();
        $this->inscribir($clase, $beto, [], $cabeceras)->assertStatus(422)->assertJsonPath('code', 'full');

        $this->quitar($clase, $ana, headers: $cabeceras)->assertOk()->assertJsonPath('outcome', 'removed');
        $this->inscribir($clase, $beto, [], $cabeceras)->assertOk()->assertJsonPath('outcome', 'reserved');

        $this->assertSame([$beto->id], ClassReservation::pluck('member_id')->map(fn ($id) => (int) $id)->all());
    }

    // ── Las MISMAS reglas que la app ───────────────────────────────────────

    public function test_plan_sin_clases_no_se_inscribe(): void
    {
        $clase = $this->clase();

        $this->inscribir($clase, $this->socio(conClases: false))
            ->assertStatus(422)
            ->assertJsonPath('code', 'classes_not_in_plan');
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_membresia_vencida_no_se_inscribe(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $socio->user->forceFill(['membership_end_date' => '2026-10-01'])->save();

        $this->inscribir($clase, $socio)->assertStatus(422)->assertJsonPath('code', 'classes_not_in_plan');
    }

    public function test_cuenta_suspendida_eliminada_o_bloqueada_no_se_inscribe(): void
    {
        $clase = $this->clase();

        $suspendida = $this->socio(extra: ['status' => Member::STATUS_SUSPENDED]);
        $eliminada = $this->socio(extra: ['status' => Member::STATUS_DELETED]);
        $conCerrojo = $this->socio();
        MemberRiskLock::create([
            'member_id' => $conCerrojo->id, 'reason' => 'riesgo',
            'status' => MemberRiskLock::STATUS_ACTIVE, 'locked_until' => now()->addDay(),
            'created_by' => MemberRiskLock::BY_SYSTEM,
        ]);
        $sancionada = $this->socio();
        MemberSuspension::create([
            'member_id' => $sancionada->id, 'scope' => ModerationScope::FULL_APP_ACCESS,
            'reason' => 'moderación', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3),
        ]);

        foreach ([$suspendida, $eliminada, $conCerrojo, $sancionada] as $socio) {
            $this->inscribir($clase, $socio)
                ->assertStatus(422)
                ->assertJsonPath('code', 'account_blocked');
        }
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_saldo_vencido_no_se_inscribe_igual_que_en_la_app(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->deudaVencida($socio);

        $this->inscribir($clase, $socio)
            ->assertStatus(422)
            ->assertJsonPath('code', 'membership_payment_overdue');

        // La app lo retiene con el mismo código: los dos canales dicen lo mismo.
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($socio))
            ->assertStatus(403)
            ->assertJsonPath('code', 'membership_payment_overdue');

        $this->assertSame(0, ClassReservation::count());
    }

    public function test_clase_inactiva_no_se_inscribe(): void
    {
        $clase = $this->clase(20, ['status' => 'inactive']);

        $this->inscribir($clase, $this->socio())->assertStatus(422)->assertJsonPath('code', 'class_unavailable');
    }

    public function test_sin_reserva_online_el_mostrador_si_inscribe_y_la_app_no(): void
    {
        // `allow_online_booking` dice si el socio reserva POR SU CUENTA. Inscribir
        // en persona es justamente la reserva que no es online.
        $clase = $this->clase(20, ['allow_online_booking' => false]);

        $this->inscribir($clase, $this->socio())->assertOk();
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->appAuth($this->socio()))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Clase no disponible para reservas.']);
    }

    // ── Fechas ─────────────────────────────────────────────────────────────

    public function test_fecha_explicita_de_otra_semana_se_respeta(): void
    {
        $clase = $this->clase();

        $this->inscribir($clase, $this->socio(), ['session_date' => '2026-10-14'])
            ->assertOk()
            ->assertJsonPath('data.session_date', '2026-10-14');
        $this->assertSame('2026-10-14', ClassReservation::sole()->session_date->toDateString());
    }

    public function test_un_dia_en_que_la_clase_no_se_dicta_se_rechaza(): void
    {
        $clase = $this->clase(); // miércoles

        $this->inscribir($clase, $this->socio(), ['session_date' => '2026-10-08']) // jueves
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_an_occurrence');
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_una_fecha_pasada_se_rechaza(): void
    {
        $clase = $this->clase();

        $this->inscribir($clase, $this->socio(), ['session_date' => '2026-09-30'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'past');
    }

    public function test_una_fecha_mal_formada_es_error_de_validacion(): void
    {
        $clase = $this->clase();

        $this->inscribir($clase, $this->socio(), ['session_date' => '07/10/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session_date');
    }

    public function test_clase_en_curso_o_finalizada_no_admite_inscripcion_ni_baja(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();
        $this->inscribir($clase, $socio, [], $cabeceras)->assertOk();

        $sesion = ClassSession::create(['class_id' => $clase->id, 'session_date' => self::OCCURRENCE, 'started_at' => now()]);
        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertStatus(422)->assertJsonPath('code', 'live');
        $this->quitar($clase, $socio, headers: $cabeceras)->assertStatus(422)->assertJsonPath('code', 'live');

        $sesion->update(['ended_at' => now()]);
        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertStatus(422)->assertJsonPath('code', 'closed');
        $this->quitar($clase, $socio, headers: $cabeceras)->assertStatus(422)->assertJsonPath('code', 'closed');

        $this->assertSame(1, ClassReservation::count());
    }

    public function test_el_listado_dice_si_se_puede_inscribir_y_por_que_no(): void
    {
        $clase = $this->clase(1);
        $cabeceras = $this->recepcion();

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $cabeceras)
            ->assertOk()
            ->assertJsonPath('data.session_date', self::OCCURRENCE)
            ->assertJsonPath('data.can_enroll', true)
            ->assertJsonPath('data.upcoming_dates', ['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28']);

        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertOk()->assertJsonPath('data.can_enroll', false);

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments?session_date=2026-09-30", $cabeceras)
            ->assertOk()
            ->assertJsonPath('data.can_enroll', false)
            ->assertJsonPath('data.blocked_reason', 'past');
    }

    // ── Baja ───────────────────────────────────────────────────────────────

    public function test_quitar_es_idempotente_y_exige_la_fecha(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();
        $this->inscribir($clase, $socio, [], $cabeceras)->assertOk();

        $this->deleteJson("/api/admin/classes/{$clase->id}/enrollments/{$socio->id}", [], $cabeceras)
            ->assertStatus(422)
            ->assertJsonValidationErrors('session_date');

        $this->quitar($clase, $socio, headers: $cabeceras)->assertOk()->assertJsonPath('outcome', 'removed')->assertJsonPath('data.booked', 0);
        $señales = $this->señales();
        $trazas = AuditLog::where('action', 'unenroll')->count();

        $this->quitar($clase, $socio, headers: $cabeceras)->assertOk()->assertJsonPath('outcome', 'already');
        $this->assertSame(0, ClassReservation::count());
        $this->assertSame($señales, $this->señales(), 'Quitar a quien ya no estaba no se anuncia.');
        $this->assertSame($trazas, AuditLog::where('action', 'unenroll')->count(), 'Ni se audita.');
    }

    public function test_una_reserva_heredada_sin_fecha_cuenta_para_el_cupo_y_para_el_doble(): void
    {
        // Reservas anteriores a `session_date`: ocupan la ocurrencia que toque.
        $clase = $this->clase(2);
        $viejo = $this->socio();
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $viejo->id]);
        $cabeceras = $this->recepcion();

        $this->inscribir($clase, $viejo, [], $cabeceras)->assertOk()->assertJsonPath('outcome', 'already');
        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertOk()->assertJsonPath('data.booked', 2);
        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertStatus(422)->assertJsonPath('code', 'full');
    }

    public function test_el_bloqueo_de_la_clase_esta_en_el_nucleo(): void
    {
        // SQLite compila `lockForUpdate()` a nada, así que ninguna prueba que
        // EJECUTE la reserva aquí puede ver el bloqueo. Se fija en la fuente, y
        // la concurrencia real se prueba contra PostgreSQL (ver el acta de QA).
        $fuente = file_get_contents(app_path('Services/Classes/ClassBookingService.php'));
        $this->assertMatchesRegularExpression(
            '/MyClass::whereKey\(\$class->getKey\(\)\)->lockForUpdate\(\)->first\(\)/',
            $fuente,
        );
    }

    public function test_quitar_una_reserva_heredada_sin_fecha_la_saca_de_la_lista(): void
    {
        // Una reserva anterior a `session_date` ocupa la ocurrencia: sale en la
        // lista y cuenta en el cupo. Quitarla tiene que sacarla de ahí; si no,
        // recepción leería «ya no estaba» con la persona aún en la lista.
        $clase = $this->clase();
        $viejo = $this->socio();
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $viejo->id]);
        $cabeceras = $this->recepcion();
        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $cabeceras)->assertJsonPath('data.booked', 1);

        $this->quitar($clase, $viejo, headers: $cabeceras)
            ->assertOk()
            ->assertJsonPath('outcome', 'removed')
            ->assertJsonPath('data.booked', 0)
            ->assertJsonPath('data.enrollments', []);
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_quitar_una_fecha_no_toca_las_reservas_de_otras_semanas(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();
        $this->inscribir($clase, $socio, [], $cabeceras)->assertOk();
        $this->inscribir($clase, $socio, ['session_date' => '2026-10-14'], $cabeceras)->assertOk();

        $this->quitar($clase, $socio, '2026-10-14', $cabeceras)->assertOk();

        $this->assertSame([self::OCCURRENCE], ClassReservation::get()->map(fn ($r) => $r->session_date->toDateString())->all());
    }

    // ── Permisos ───────────────────────────────────────────────────────────

    public function test_ver_inscritos_y_modificarlos_son_permisos_distintos(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $soloVer = $this->adminWithPermissions(['classes.view']);

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $soloVer)->assertOk();
        $this->inscribir($clase, $socio, [], $soloVer)->assertForbidden();
        $this->quitar($clase, $socio, headers: $soloVer)->assertForbidden();
        $this->assertSame(0, ClassReservation::count());

        // `classes.enroll` basta para inscribir, sin poder tocar horarios.
        $inscribe = $this->adminWithPermissions(['classes.view', 'classes.enroll']);
        $this->inscribir($clase, $socio, [], $inscribe)->assertOk();
        $this->patchJson("/api/classes/{$clase->id}", ['name' => 'Otro nombre'], $inscribe)->assertForbidden();
        $this->quitar($clase, $socio, headers: $inscribe)->assertOk();

        // Quien administra las clases también inscribe.
        $this->inscribir($clase, $socio, [], $this->adminWithPermissions(['classes.manage']))->assertOk();
    }

    public function test_recepcion_inscribe_por_defecto_y_administrativo_ni_ve(): void
    {
        $clase = $this->clase();

        $this->inscribir($clase, $this->socio())->assertOk();

        $administrativo = $this->adminAs(Admin::ROLE_ADMINISTRATIVO);
        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $administrativo)->assertForbidden();
        $this->inscribir($clase, $this->socio(), [], $administrativo)->assertForbidden();
    }

    public function test_con_permiso_de_inscribir_pero_sin_ver_la_respuesta_no_trae_la_lista(): void
    {
        // Quitar a alguien que no estaba es un 200 sin efectos: si trajera la
        // lista, sería una forma de leerla sin `classes.view`.
        $clase = $this->clase();
        $this->inscribir($clase, $this->socio())->assertOk();
        $soloInscribe = $this->adminWithPermissions(['classes.enroll']);
        $otro = $this->socio();

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $soloInscribe)->assertForbidden();
        $this->quitar($clase, $otro, headers: $soloInscribe)
            ->assertOk()
            ->assertJsonPath('outcome', 'already')
            ->assertJsonPath('data.booked', 1)
            ->assertJsonPath('data.enrollments', []);
        $this->inscribir($clase, $otro, [], $soloInscribe)
            ->assertOk()
            ->assertJsonPath('data.booked', 2)
            ->assertJsonPath('data.enrollments', []);
    }

    public function test_el_token_de_automatizaciones_lee_pero_no_inscribe(): void
    {
        $clase = $this->clase();

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $this->sharedTokenHeaders())->assertOk();
        $this->inscribir($clase, $this->socio(), [], $this->sharedTokenHeaders())->assertForbidden();
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_sin_sesion_no_hay_nada(): void
    {
        $clase = $this->clase();

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments")->assertUnauthorized();
        $this->postJson("/api/admin/classes/{$clase->id}/enrollments", ['user_id' => 1])->assertUnauthorized();
    }

    public function test_el_documento_solo_lo_ve_quien_puede_ver_fichas(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->inscribir($clase, $socio)->assertOk();

        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $this->recepcion())
            ->assertJsonPath('data.enrollments.0.document', $socio->document_number);
        $this->getJson("/api/admin/classes/{$clase->id}/enrollments", $this->adminWithPermissions(['classes.view']))
            ->assertJsonPath('data.enrollments.0.full_name', $socio->full_name)
            ->assertJsonPath('data.enrollments.0.document', null);
    }

    public function test_el_mapa_de_autorizacion_resuelve_las_rutas_nuevas(): void
    {
        $permiso = function (string $metodo, string $uri) {
            $ruta = Route::getRoutes()->match(Request::create($uri, $metodo));

            return AuthorizationMap::resolve($ruta);
        };

        $this->assertSame('classes.view', $permiso('GET', '/api/admin/classes/stream'));
        $this->assertSame('classes.view', $permiso('GET', '/api/admin/classes/1/enrollments'));
        $this->assertSame(['classes.enroll', 'classes.manage'], $permiso('POST', '/api/admin/classes/1/enrollments'));
        $this->assertSame(['classes.enroll', 'classes.manage'], $permiso('DELETE', '/api/admin/classes/1/enrollments/1'));
    }

    // ── Auditoría y fallos ─────────────────────────────────────────────────

    public function test_inscribir_y_quitar_quedan_auditados_con_quien_lo_hizo(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();

        $reservaId = $this->inscribir($clase, $socio, [], $cabeceras)->assertOk()->json('data.enrollments.0.reservation_id');
        $this->quitar($clase, $socio, headers: $cabeceras)->assertOk();

        $alta = AuditLog::where('action', 'enroll')->sole();
        $this->assertSame('Clases', $alta->module);
        $this->assertSame(Admin::ROLE_RECEPCION, $alta->actor_role);
        $this->assertSame((string) $clase->id, $alta->entity_id);
        $this->assertSame($socio->id, $alta->metadata['member_id']);
        $this->assertSame(self::OCCURRENCE, $alta->metadata['session_date']);
        $this->assertIsInt($reservaId);
        $this->assertSame($reservaId, $alta->metadata['reservation_id']);

        $baja = AuditLog::where('action', 'unenroll')->sole();
        $this->assertSame($socio->id, $baja->metadata['member_id']);
        $this->assertSame(1, $baja->metadata['rows']);
    }

    public function test_si_la_traza_no_se_puede_escribir_no_queda_reserva_ni_aviso(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $cabeceras = $this->recepcion();
        $this->app->instance(AuditTrail::class, new class extends AuditTrail
        {
            public function record(Request $request, array $evento): void
            {
                throw new \RuntimeException('auditoría caída');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->inscribir($clase, $socio, [], $cabeceras);
            $this->fail('La inscripción no puede confirmarse sin traza.');
        } catch (\RuntimeException $e) {
            $this->assertSame('auditoría caída', $e->getMessage());
        }

        $this->assertSame(0, ClassReservation::count(), 'Sin traza no hay reserva: todo o nada.');
        $this->assertSame(0, $this->señales(), 'Y no se anuncia una reserva que no existe.');
    }

    // ── Tiempo real ────────────────────────────────────────────────────────

    public function test_inscribir_avisa_a_la_app_y_al_entrenador_y_un_rechazo_no(): void
    {
        $coach = Trainer::create(['full_name' => 'Coach', 'document' => '777000111', 'status' => 'active']);
        $clase = $this->clase(1, ['trainer_id' => $coach->id]);
        $otro = $this->socio();
        $cabeceras = $this->recepcion();

        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertOk();
        // Todos los socios activos ven el cupo nuevo, no solo el inscrito: UNA
        // fila global que el stream entrega a cada uno. Ninguna fila por socio.
        $this->assertTrue(CatalogEvent::where('type', 'reservation.created')->where('class_id', $clase->id)->exists());
        $this->assertFalse(MemberRealtimeEvent::where('member_id', $otro->id)->exists(), 'Sin fan-out por socio.');
        $this->assertSame(1, TrainerRealtimeEvent::where('trainer_id', $coach->id)->where('type', 'class.updated')->count());

        $antes = $this->señales();
        $this->inscribir($clase, $this->socio(), [], $cabeceras)->assertStatus(422);
        $this->assertSame($antes, $this->señales(), 'Un rechazo no cambió nada: no se anuncia.');

        $this->quitar($clase, ClassReservation::sole()->member, headers: $cabeceras)->assertOk();
        $this->assertGreaterThan($antes, $this->señales(), 'Liberar un cupo sí se anuncia.');
        $this->assertSame(2, TrainerRealtimeEvent::where('trainer_id', $coach->id)->where('type', 'class.updated')->count());
    }

    public function test_si_inscribe_una_cuenta_de_entrenador_el_aviso_llega_a_todos_los_socios(): void
    {
        // La cuenta de entrenador ve solo a sus socios (scope global). El aviso
        // de cupo no es leer datos de nadie: tiene que llegar a todos.
        $coach = Trainer::create(['full_name' => 'Coach Alcance', 'email' => 'coach-alcance@ironbody.test', 'status' => 'active']);
        $suyo = $this->socio();
        $ajeno = $this->socio();
        MemberTrainerAssignment::create([
            'member_id' => $suyo->id, 'trainer_id' => $coach->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now(),
        ]);
        $cuenta = Admin::create([
            'name' => 'Cuenta entrenador', 'email' => 'cuenta-coach@ironbody.test', 'password' => 'secret-password',
            'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $coach->id, 'status' => 'active',
        ]);
        $clase = $this->clase();

        $this->inscribir($clase, $suyo, [], $this->actingAsAdmin($cuenta))->assertOk();

        // El aviso es GLOBAL (una fila que el stream entrega a cada socio): el
        // alcance del entrenador no puede recortarlo.
        $this->assertTrue(
            CatalogEvent::where('type', 'reservation.created')->where('class_id', $clase->id)->exists(),
            'Un socio que no es del entrenador también tiene que ver el cupo nuevo.',
        );
    }

    public function test_el_aviso_sale_despues_del_commit_y_nunca_de_un_rollback(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();

        // try/finally: si una aserción falla con la transacción abierta, sin el
        // rollback el error se arrastraría a las pruebas siguientes.
        DB::beginTransaction();
        try {
            $resultado = app(ClassBookingService::class)->enrollByStaff($socio, $clase, null);
            $this->assertTrue($resultado->ok);
            $this->assertSame(0, $this->señales(), 'Dentro de la transacción todavía no se ha anunciado nada.');
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, ClassReservation::count());
        $this->assertSame(0, $this->señales(), 'Lo que se revierte no se anuncia.');
    }

    public function test_la_huella_del_canal_cambia_con_cada_cambio_real_y_no_con_los_que_no(): void
    {
        $clase = $this->clase(2);
        $ana = $this->socio();
        $beto = $this->socio();
        $cabeceras = $this->recepcion();
        $huella = fn () => ClassEnrollmentController::classesSignature();

        $h0 = $huella();
        $this->inscribir($clase, $ana, [], $cabeceras)->assertOk();
        $h1 = $huella();
        $this->assertNotSame($h0, $h1, 'Una inscripción mueve la huella.');

        $this->inscribir($clase, $ana, [], $cabeceras)->assertOk()->assertJsonPath('outcome', 'already');
        $this->assertSame($h1, $huella(), '«Ya estaba» no mueve la huella.');

        // Baja + alta en el mismo instante: el conteo queda igual y la fecha de
        // modificación también (reloj congelado). La huella tiene que moverse.
        $this->quitar($clase, $ana, headers: $cabeceras)->assertOk();
        $this->inscribir($clase, $beto, [], $cabeceras)->assertOk();
        $h2 = $huella();
        $this->assertNotSame($h1, $h2);

        ClassSession::create(['class_id' => $clase->id, 'session_date' => self::OCCURRENCE, 'started_at' => now()]);
        $this->assertNotSame($h2, $huella(), 'Que el entrenador inicie la clase cambia lo que se puede hacer.');
    }

    public function test_la_huella_no_expone_contenido(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ClassEnrollmentController::classesSignature());
    }

    // ── Entrenador ─────────────────────────────────────────────────────────

    public function test_el_entrenador_ve_en_su_lista_al_inscrito_por_recepcion(): void
    {
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
        ]);
        $coach = Trainer::create([
            'full_name' => 'Coach Lista', 'document' => '310', 'phone' => '+573009998810', 'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $coach->refresh()->syncRoles([TrainerRole::FUNCTIONAL]);
        $access = $this->postJson('/api/trainer/auth/access', ['document' => '310', 'device_id' => 't310'])->assertOk();
        $token = $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'), 'code' => $access->json('dev_code'), 'device_id' => 't310',
        ])->assertOk()->json('token');

        $clase = $this->clase(20, ['trainer_id' => $coach->id]);
        $socio = $this->socio();
        $this->inscribir($clase, $socio)->assertOk();

        $this->getJson("/api/trainer/classes/{$clase->id}?session_date=".self::OCCURRENCE, ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.member_id', $socio->id);
    }

    // ── El contador ficticio ya no existe ──────────────────────────────────

    public function test_el_numero_de_inscritos_no_se_escribe_a_mano(): void
    {
        $clase = $this->clase();
        $this->inscribir($clase, $this->socio())->assertOk();
        $notificaciones = Notification::count();

        $this->patchJson("/api/classes/{$clase->id}", ['enrolled_count' => 15], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrolled_count');

        $this->assertSame(0, (int) $clase->fresh()->enrolled_count);
        $this->assertSame($notificaciones, Notification::count(), 'Sin avisos «Clase actualizada» a los inscritos.');

        // Tampoco con valores vacíos: `prohibited` los dejaba pasar y acababan
        // en un 500 contra la columna NOT NULL.
        foreach ([null, '', 0] as $valor) {
            $this->patchJson("/api/classes/{$clase->id}", ['enrolled_count' => $valor], $this->adminHeaders())
                ->assertStatus(422)
                ->assertJsonValidationErrors('enrolled_count');
        }

        // Editar la clase de verdad sigue funcionando.
        $this->patchJson("/api/classes/{$clase->id}", ['name' => 'Spinning PM'], $this->adminHeaders())->assertOk();
    }
}
