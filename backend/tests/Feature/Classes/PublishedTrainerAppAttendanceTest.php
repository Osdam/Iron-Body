<?php

namespace Tests\Feature\Classes;

use App\Models\ClassAttendance;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contrato de la app del ENTRENADOR tal y como está publicada (Iron_Body_App
 * 3a4b4d3): fija `session_date` UNA vez al abrir el detalle con la fecha local
 * del teléfono (`_today()` = DateTime.now()) y la manda igual en cada llamada:
 *
 *   GET  /api/trainer/classes/{id}?session_date=…
 *   POST /api/trainer/classes/{id}/start       {session_date, face_verified: true}
 *   POST /api/trainer/classes/{id}/attendance  {member_id, session_date, status}
 *   PUT  /api/trainer/classes/{id}/attendance  {member_id, session_date, status}
 *   POST /api/trainer/classes/{id}/end         {session_date, face_verified: true}
 *
 * No lee el `session_date` de las respuestas. El servidor resuelve la sesión
 * (la que el entrenador VE) y todas las operaciones trabajan sobre ESA misma.
 * El fallo: pasada la medianoche la lista enseñaba la sesión de anoche, aún en
 * curso, y marcar con la fecha literal del teléfono daba 422.
 */
class PublishedTrainerAppAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    private const MARTES = '2026-10-06';

    private Trainer $coach;

    private string $token;

    private int $doc = 620100100;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
        ]);
        $this->reloj(self::LUNES.' 08:00');
        // Sin identidad: las banderas del portal son globales (como en producción).
        $this->coach = Trainer::create(['full_name' => 'Coach Publicado', 'document' => '620', 'phone' => '+573006200620', 'status' => 'active']);
        $this->coach->syncRoles([TrainerRole::FUNCTIONAL]);
        $this->token = app(TrainerSessionService::class)->issueSession($this->coach, ['device_id' => 'publicada'])['token'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    /** `_today()` de la app publicada en un teléfono de Colombia. */
    private function hoyDelTelefono(): string
    {
        return Carbon::now('America/Bogota')->toDateString();
    }

    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional noche', 'type' => 'Funcional', 'trainer_id' => $this->coach->id,
            'day_of_week' => 'Lunes', 'start_time' => '18:00', 'end_time' => '19:00',
            'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ], $extra));
    }

    private function inscrito(MyClass $clase, string $fecha = self::LUNES): Member
    {
        $doc = (string) $this->doc++;
        $m = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio '.$doc, 'document_number' => $doc, 'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
        ]));
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => $fecha]);

        return $m;
    }

    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json'];
    }

    // Las cinco llamadas, con el payload EXACTO de la app publicada.
    private function detalle(MyClass $c, string $fecha)
    {
        return $this->getJson("/api/trainer/classes/{$c->id}?session_date={$fecha}", $this->auth());
    }

    private function iniciar(MyClass $c, string $fecha, ?string $token = null)
    {
        return $this->postJson("/api/trainer/classes/{$c->id}/start", ['session_date' => $fecha, 'face_verified' => true], $this->auth($token));
    }

    private function marcar(MyClass $c, Member $m, string $fecha, string $status = 'present', ?string $token = null)
    {
        return $this->postJson("/api/trainer/classes/{$c->id}/attendance", ['member_id' => $m->id, 'session_date' => $fecha, 'status' => $status], $this->auth($token));
    }

    private function corregir(MyClass $c, Member $m, string $fecha, string $status)
    {
        return $this->putJson("/api/trainer/classes/{$c->id}/attendance", ['member_id' => $m->id, 'session_date' => $fecha, 'status' => $status], $this->auth());
    }

    private function finalizar(MyClass $c, string $fecha)
    {
        return $this->postJson("/api/trainer/classes/{$c->id}/end", ['session_date' => $fecha, 'face_verified' => true], $this->auth());
    }

    private function presentes(array $participants): int
    {
        return count(array_filter($participants, fn ($p) => ($p['status'] ?? null) === 'present'));
    }

    // ── El día de la clase ──────────────────────────────────────────────────

    public function test_el_dia_de_la_clase_abre_inicia_marca_presente_y_tarde_sin_tocar_el_cupo(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase);
        $luis = $this->inscrito($clase);
        $this->reloj(self::LUNES.' 17:55');
        $hoy = $this->hoyDelTelefono();

        $detalle = $this->detalle($clase, $hoy)->assertOk()->assertJsonPath('session_date', self::LUNES);
        $this->assertCount(2, $detalle->json('participants'));
        $this->assertSame(0, $this->presentes($detalle->json('participants')));
        $cupo = $this->getJson("/api/classes/{$clase->id}")->json('data.booked_spots');

        $this->iniciar($clase, $hoy)->assertOk()->assertJsonPath('session.session_date', self::LUNES)
            ->assertJsonStructure(['message', 'session' => ['session_date', 'started_at', 'ended_at']]);

        $r = $this->marcar($clase, $ana, $hoy)->assertOk()
            ->assertJsonStructure(['message', 'session_date', 'participants'])
            ->assertJsonPath('session_date', self::LUNES);
        $this->assertSame(1, $this->presentes($r->json('participants')), 'Presentes 0 → 1.');

        $r = $this->marcar($clase, $luis, $hoy, 'late')->assertOk();
        $this->assertSame('late', collect($r->json('participants'))->firstWhere('member_id', $luis->id)['status']);

        $this->assertSame(
            [[$ana->id, 'present'], [$luis->id, 'late']],
            ClassAttendance::orderBy('member_id')->get()->map(fn ($a) => [$a->member_id, $a->status])->all(),
        );
        $this->assertSame([self::LUNES], ClassAttendance::pluck('session_date')->map->toDateString()->unique()->values()->all());
        $this->assertSame($cupo, $this->getJson("/api/classes/{$clase->id}")->json('data.booked_spots'), 'Marcar asistencia no mueve el cupo.');
    }

    // ── El fallo de la app publicada ────────────────────────────────────────

    public function test_pasada_la_medianoche_la_app_publicada_marca_corrige_y_finaliza_la_sesion_que_ve(): void
    {
        $clase = $this->clase(['start_time' => '23:30', 'end_time' => '23:59']);
        $ana = $this->inscrito($clase);

        // 23:59 del lunes en Bogotá (04:59 del martes en UTC): se inicia la del lunes.
        $this->reloj(self::LUNES.' 23:59');
        $this->iniciar($clase, $this->hoyDelTelefono())->assertOk()->assertJsonPath('session.session_date', self::LUNES);

        // 00:01 del martes: la app abre el detalle y su «hoy» ya es el martes,
        // que no es día de la clase. La lista enseña la sesión de anoche, en curso…
        $this->reloj(self::MARTES.' 00:01');
        $telefono = $this->hoyDelTelefono();
        $this->assertSame(self::MARTES, $telefono);
        $this->detalle($clase, $telefono)->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonPath('session.is_live', true)
            ->assertJsonPath('participants.0.member_id', $ana->id);

        // …y marcar con la fecha del teléfono trabaja sobre ESA sesión (antes: 422).
        $this->marcar($clase, $ana, $telefono)->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonPath('participants.0.status', 'present');
        $this->corregir($clase, $ana, $telefono, 'late')->assertOk()->assertJsonPath('participants.0.status', 'late');
        $this->finalizar($clase, $telefono)->assertOk()->assertJsonPath('session.session_date', self::LUNES);

        $marca = ClassAttendance::sole();
        $this->assertSame(self::LUNES, $marca->session_date->toDateString());
        $this->assertSame('late', $marca->status);
        $this->assertNotNull(ClassSession::sole()->ended_at);
        $this->assertSame(0, ClassSession::whereDate('session_date', self::MARTES)->count(), 'Nada queda guardado en el martes.');
    }

    public function test_un_dia_sin_clase_ve_la_proxima_pero_no_inicia_ni_marca_antes_de_tiempo(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase, '2026-10-12');
        $this->reloj('2026-10-07 10:00'); // miércoles
        $telefono = $this->hoyDelTelefono();

        $this->detalle($clase, $telefono)->assertOk()->assertJsonPath('session_date', '2026-10-12')
            ->assertJsonPath('participants.0.member_id', $ana->id);
        $this->marcar($clase, $ana, $telefono)->assertStatus(422)
            ->assertJsonPath('message', 'La asistencia se marca el día de la clase, no antes.');
        $this->iniciar($clase, $telefono)->assertStatus(422)->assertJsonPath('code', 'not_today');

        $this->assertSame(0, ClassAttendance::count());
        $this->assertSame(0, ClassSession::count());
    }

    public function test_clase_de_fecha_unica_el_mismo_dia(): void
    {
        $clase = $this->clase([
            'is_recurring' => false, 'start_time' => '19:00', 'end_time' => '20:00',
            'date_time' => self::LUNES.' 19:00:00',
        ]);
        $ana = $this->inscrito($clase);
        $this->reloj(self::LUNES.' 18:55');
        $hoy = $this->hoyDelTelefono();

        $this->detalle($clase, $hoy)->assertOk()->assertJsonPath('session_date', self::LUNES);
        $this->iniciar($clase, $hoy)->assertOk()->assertJsonPath('session.session_date', self::LUNES);
        $this->marcar($clase, $ana, $hoy)->assertOk()->assertJsonPath('participants.0.status', 'present');
    }

    // ── Doble toque y carrera ───────────────────────────────────────────────

    public function test_doble_toque_misma_marca_es_idempotente_y_otra_marca_se_corrige(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase);
        $this->reloj(self::LUNES.' 18:05');
        $hoy = $this->hoyDelTelefono();

        $this->marcar($clase, $ana, $hoy)->assertOk();
        $hechos = ClassEvent::count();
        $this->marcar($clase, $ana, $hoy)->assertOk()->assertJsonPath('participants.0.status', 'present');

        $this->assertSame(1, ClassAttendance::count(), 'Nunca dos registros.');
        $this->assertSame($hechos, ClassEvent::count(), 'La repetición no vuelve a avisar.');
        $this->marcar($clase, $ana, $hoy, 'late')->assertStatus(409);
        $this->assertSame('present', ClassAttendance::sole()->status, 'Una marca distinta no se pisa: se corrige con PUT.');
    }

    public function test_dos_peticiones_a_la_vez_un_registro_y_ninguna_da_500(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase);
        $this->reloj(self::LUNES.' 18:05');

        // La otra petición guarda justo entre la comprobación y el INSERT de esta.
        $carrera = function (string $status) use ($clase, $ana): void {
            ClassAttendance::creating(function () use ($clase, $ana, $status): void {
                if (DB::table('class_attendances')->where('member_id', $ana->id)->doesntExist()) {
                    DB::table('class_attendances')->insert([
                        'class_id' => $clase->id, 'member_id' => $ana->id, 'session_date' => self::LUNES,
                        'status' => $status, 'marked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        };

        $carrera('present');
        $this->marcar($clase, $ana, self::LUNES)->assertOk()->assertJsonPath('participants.0.status', 'present');
        $this->assertSame(1, ClassAttendance::count());

        ClassAttendance::query()->delete();
        ClassAttendance::flushEventListeners();
        $carrera('late');
        $this->marcar($clase, $ana, self::LUNES)->assertStatus(409);
        $this->assertSame('late', ClassAttendance::sole()->status, 'Gana la primera; estado final determinista.');
    }

    // ── Autorización y reglas que se mantienen ──────────────────────────────

    public function test_otro_entrenador_no_marca_ni_inicia(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase);
        $otro = Trainer::create(['full_name' => 'Coach Ajeno', 'document' => '621', 'phone' => '+573006210621', 'status' => 'active']);
        $otro->syncRoles([TrainerRole::FUNCTIONAL]);
        $tokenAjeno = app(TrainerSessionService::class)->issueSession($otro, ['device_id' => 'ajeno'])['token'];
        $this->reloj(self::LUNES.' 18:05');

        $this->iniciar($clase, self::LUNES, $tokenAjeno)->assertStatus(403);
        $this->marcar($clase, $ana, self::LUNES, 'present', $tokenAjeno)->assertStatus(403);
        $this->assertSame(0, ClassAttendance::count() + ClassSession::count());
    }

    public function test_no_inscrito_cancelado_o_liberado_no_se_marca(): void
    {
        $clase = $this->clase();
        $ana = $this->inscrito($clase);
        $cancelo = $this->inscrito($clase);
        $extrano = $this->inscrito($clase, '2026-10-12'); // reservó OTRA sesión
        $this->reloj(self::LUNES.' 18:05');
        $hoy = $this->hoyDelTelefono();

        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", [], ['Authorization' => 'Bearer '.$cancelo->access_hash]);
        $this->marcar($clase, $cancelo, $hoy)->assertStatus(422)->assertJsonPath('message', 'El miembro no está inscrito en esta sesión de la clase.');
        $this->marcar($clase, $extrano, $hoy)->assertStatus(422);

        // Liberada (la renovación borra la reserva y deja la marca): se lista
        // para corregirla, no ocupa cupo y no se vuelve a marcar.
        $this->marcar($clase, $ana, $hoy)->assertOk();
        ClassReservation::where('member_id', $ana->id)->delete();
        $d = $this->detalle($clase, $hoy)->assertOk();
        $this->assertSame('released', collect($d->json('participants'))->firstWhere('member_id', $ana->id)['booking_status']);
        $this->assertSame(0, $d->json('data.enrolled'));
        $this->marcar($clase, $ana, $hoy)->assertStatus(422);
        $this->assertSame(1, ClassAttendance::count());
    }
}
