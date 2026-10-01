<?php

namespace Tests\Feature\Classes;

use App\Models\Admin;
use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La lista del entrenador enseña la MISMA sesión que cuentan la app y el CRM.
 *
 * EL FALLO DE PRODUCCIÓN (30-09-2026). La app de los socios mostraba la clase 21
 * —lunes 17:22— con 1/20, y el entrenador, al abrir su detalle un miércoles,
 * veía «Sin inscritos». La app del entrenador pide la lista de HOY (fecha del
 * dispositivo) y la reserva estaba, como debe, en la PRÓXIMA ocurrencia: el
 * lunes. El backend aceptaba cualquier fecha sin comprobar que la clase se
 * dictara ese día. Pasaba igual con reservas hechas desde la app y desde el CRM.
 *
 * Todo aquí usa la app del entrenador tal y como está publicada: manda la
 * fecha de hoy del dispositivo en cada llamada.
 */
class TrainerSessionDateTest extends TestCase
{
    use RefreshDatabase;

    /** Miércoles 30/09/2026 10:00 en Bogotá. */
    private const MIERCOLES = '2026-09-30 10:00';

    /** El lunes siguiente: la ocurrencia que reservan la app y el CRM. */
    private const LUNES = '2026-10-05';

    private Trainer $coach;

    private string $token;

    private MyClass $clase;

    private int $doc = 610100100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::MIERCOLES, 'America/Bogota')->utc());
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
        ]);

        $this->coach = Trainer::create([
            'full_name' => 'Coach Lunes', 'document' => '321', 'phone' => '+573009998321', 'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $this->coach->refresh()->syncRoles([TrainerRole::FUNCTIONAL]);
        $this->token = $this->login('321');

        $this->clase = MyClass::create([
            'name' => 'Funcional lunes', 'type' => 'Funcional', 'trainer_id' => $this->coach->id,
            'day_of_week' => 'Lunes', 'start_time' => '17:22', 'end_time' => '18:22',
            'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function login(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 't'.$document])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 't'.$document,
        ])->assertOk()->json('token');
    }

    private function entrenador(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function socio(): Member
    {
        $doc = (string) $this->doc++;

        return $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socia '.$doc, 'document_number' => $doc, 'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
        ]));
    }

    private function hoyDelDispositivo(): string
    {
        return Carbon::now('America/Bogota')->toDateString();
    }

    private function detalle(?string $fecha = null)
    {
        $q = $fecha !== null ? "?session_date={$fecha}" : '';

        return $this->getJson("/api/trainer/classes/{$this->clase->id}{$q}", $this->entrenador());
    }

    // ── El caso de producción ───────────────────────────────────────────────

    public function test_reserva_desde_la_app_aparece_en_la_lista_del_entrenador_aunque_hoy_no_haya_clase(): void
    {
        $socia = $this->socio();
        $this->postJson("/api/classes/{$this->clase->id}/reserve", [], ['Authorization' => 'Bearer '.$socia->access_hash])
            ->assertOk()
            ->assertJsonPath('data.booked_spots', 1);

        // La app del entrenador pide la fecha de HOY (miércoles).
        $this->detalle($this->hoyDelDispositivo())
            ->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonPath('requested_session_date', '2026-09-30')
            ->assertJsonPath('is_today', false)
            ->assertJsonPath('data.enrolled', 1)
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.member_id', $socia->id)
            ->assertJsonPath('participants.0.full_name', $socia->full_name);
    }

    public function test_inscripcion_desde_el_crm_tambien(): void
    {
        $socia = $this->socio();
        $this->postJson("/api/admin/classes/{$this->clase->id}/enrollments", ['user_id' => $socia->user_id], $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertOk()
            ->assertJsonPath('data.session_date', self::LUNES);

        $this->detalle($this->hoyDelDispositivo())
            ->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.member_id', $socia->id);
    }

    public function test_agenda_detalle_app_y_crm_cuentan_lo_mismo(): void
    {
        $this->postJson("/api/classes/{$this->clase->id}/reserve", [], ['Authorization' => 'Bearer '.$this->socio()->access_hash])->assertOk();
        $this->postJson("/api/admin/classes/{$this->clase->id}/enrollments", ['user_id' => $this->socio()->user_id], $this->adminAs(Admin::ROLE_RECEPCION))->assertOk();

        $agenda = collect($this->getJson('/api/trainer/classes', $this->entrenador())->assertOk()->json('data'))->firstWhere('id', $this->clase->id);
        $detalle = $this->detalle($this->hoyDelDispositivo())->assertOk();
        $crm = $this->getJson("/api/admin/classes/{$this->clase->id}/enrollments", $this->adminAs(Admin::ROLE_RECEPCION))->assertOk();
        $app = collect($this->getJson('/api/app/classes', ['Authorization' => 'Bearer '.$this->socio()->access_hash])->assertOk()->json('data'))
            ->firstWhere('id', (string) $this->clase->id);

        $this->assertSame(2, $agenda['enrolled'], 'agenda del entrenador');
        $this->assertSame(2, $detalle->json('data.enrolled'), 'cupo del detalle');
        $this->assertCount(2, $detalle->json('participants'), 'lista del entrenador');
        $this->assertSame(2, $crm->json('data.booked'), 'CRM');
        $this->assertCount(2, $crm->json('data.enrollments'), 'lista del CRM');
        $this->assertSame(2, $app['booked_spots'], 'app del socio');
    }

    // ── La fecha de la sesión ───────────────────────────────────────────────

    public function test_una_fecha_que_si_es_ocurrencia_se_respeta(): void
    {
        $socia = $this->socio();
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $socia->id, 'session_date' => '2026-10-12']);

        $this->detalle('2026-10-12')
            ->assertOk()
            ->assertJsonPath('session_date', '2026-10-12')
            ->assertJsonCount(1, 'participants');
        $this->detalle(self::LUNES)->assertOk()->assertJsonCount(0, 'participants');
    }

    public function test_sin_fecha_o_con_basura_se_usa_la_ocurrencia_operativa(): void
    {
        foreach ([null, 'no-es-fecha', '2026-02-30', '2026-10-05T23:00:00Z'] as $pedida) {
            $this->getJson("/api/trainer/classes/{$this->clase->id}".($pedida !== null ? '?session_date='.urlencode($pedida) : ''), $this->entrenador())
                ->assertOk()
                ->assertJsonPath('session_date', self::LUNES);
        }
    }

    public function test_despues_de_las_siete_de_la_noche_no_se_salta_al_dia_siguiente(): void
    {
        // Lunes 20:30 en Bogotá = martes 01:30 en UTC. La clase de hoy es HOY.
        Carbon::setTestNow(Carbon::parse('2026-10-05 20:30', 'America/Bogota')->utc());
        $socia = $this->socio();
        $this->postJson("/api/classes/{$this->clase->id}/reserve", [], ['Authorization' => 'Bearer '.$socia->access_hash])->assertOk();

        $this->detalle()->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonPath('is_today', true)
            ->assertJsonCount(1, 'participants');
    }

    // ── Lo que ve el entrenador de cada inscrito ────────────────────────────

    public function test_cada_inscrito_trae_documento_enmascarado_estado_y_hora_de_inscripcion(): void
    {
        $socia = $this->socio();
        $this->postJson("/api/classes/{$this->clase->id}/reserve", [], ['Authorization' => 'Bearer '.$socia->access_hash])->assertOk();
        $reserva = ClassReservation::sole();

        $p = $this->detalle($this->hoyDelDispositivo())->assertOk()->json('participants.0');

        $this->assertSame('•••'.substr($socia->document_number, -4), $p['document']);
        $this->assertSame('confirmed', $p['booking_status']);
        $this->assertSame($reserva->id, $p['reservation_id']);
        $this->assertSame($reserva->reserved_at->toIso8601String(), $p['reserved_at']);
        $this->assertNull($p['status'], 'asistencia aún sin marcar');
    }

    // ── Asistencia ──────────────────────────────────────────────────────────

    public function test_la_asistencia_solo_se_marca_el_dia_de_la_clase_y_a_quien_reservo_esa_sesion(): void
    {
        $socia = $this->socio();
        $this->postJson("/api/classes/{$this->clase->id}/reserve", [], ['Authorization' => 'Bearer '.$socia->access_hash])->assertOk();
        $marcar = fn (string $fecha, int $memberId) => $this->postJson("/api/trainer/classes/{$this->clase->id}/attendance", [
            'member_id' => $memberId, 'session_date' => $fecha, 'status' => 'present',
        ], $this->entrenador());

        // Miércoles: la clase no se dicta hoy.
        // Con la fecha del dispositivo (miércoles) se trabaja sobre la sesión que
        // el entrenador VE, la del lunes: no se marca antes de tiempo.
        $marcar($this->hoyDelDispositivo(), $socia->id)->assertStatus(422)->assertJsonPath('message', 'La asistencia se marca el día de la clase, no antes.');
        // El lunes que viene todavía no ha llegado.
        $marcar(self::LUNES, $socia->id)->assertStatus(422)->assertJsonPath('message', 'La asistencia se marca el día de la clase, no antes.');
        $this->assertSame(0, ClassAttendance::count());

        // El lunes, a quien reservó ese lunes, sí.
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 17:40', 'America/Bogota')->utc());
        $marcar(self::LUNES, $socia->id)->assertOk()->assertJsonPath('participants.0.status', 'present');

        // Y no a quien reservó OTRA semana, aunque sea la misma clase.
        $otra = $this->socio();
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $otra->id, 'session_date' => '2026-10-12']);
        $marcar(self::LUNES, $otra->id)->assertStatus(422)->assertJsonPath('message', 'El miembro no está inscrito en esta sesión de la clase.');
    }

    // ── Iniciar y finalizar ─────────────────────────────────────────────────

    public function test_no_se_inicia_una_clase_que_hoy_no_se_dicta(): void
    {
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => $this->hoyDelDispositivo(), 'face_verified' => true], $this->entrenador())
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_today');
        $this->assertSame(0, ClassSession::count(), 'Antes quedaba una sesión suelta en un día sin clase.');
    }

    public function test_iniciar_despues_de_las_siete_guarda_la_sesion_del_dia_del_gimnasio(): void
    {
        // Lunes 19:30 Bogotá = martes 00:30 UTC. Sin fecha, antes se guardaba
        // la sesión del MARTES (now() en UTC) y la app no la veía en curso.
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 19:30', 'America/Bogota')->utc());

        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['face_verified' => true], $this->entrenador())
            ->assertOk()
            ->assertJsonPath('session.session_date', self::LUNES);
        $this->assertSame([self::LUNES], ClassSession::pluck('session_date')->map->toDateString()->all());
    }

    public function test_finalizar_pasada_la_medianoche_cierra_la_sesion_en_curso(): void
    {
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 23:30', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->entrenador())->assertOk();

        // 00:10 del martes: el dispositivo ya dice martes.
        Carbon::setTestNow(Carbon::parse('2026-10-06 00:10', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/end", ['session_date' => '2026-10-06', 'face_verified' => true], $this->entrenador())
            ->assertOk()
            ->assertJsonPath('session.session_date', self::LUNES);

        $this->assertNotNull(ClassSession::sole()->ended_at);
    }

    public function test_pasada_la_medianoche_el_detalle_sigue_en_la_sesion_abierta(): void
    {
        $socio = $this->socio();
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $socio->id, 'session_date' => self::LUNES]);
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $this->socio()->id, 'session_date' => '2026-10-12']);
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 23:30', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->entrenador())->assertOk();

        // 00:10 del martes: el dispositivo pide el martes, que no es día de la clase.
        Carbon::setTestNow(Carbon::parse('2026-10-06 00:10', 'America/Bogota')->utc());
        $detalle = $this->detalle('2026-10-06')->assertOk()
            ->assertJsonPath('session_date', self::LUNES)
            ->assertJsonPath('is_today', false)
            ->assertJsonPath('session.is_live', true);
        $this->assertSame([$socio->id], array_column($detalle->json('participants'), 'member_id'));

        // Y se puede pasar lista de esa sesión abierta.
        $this->postJson("/api/trainer/classes/{$this->clase->id}/attendance", [
            'member_id' => $socio->id, 'session_date' => self::LUNES, 'status' => 'present',
        ], $this->entrenador())->assertOk()->assertJsonPath('is_today', false);

        // Cerrada la sesión, vuelve a la próxima ocurrencia.
        $this->postJson("/api/trainer/classes/{$this->clase->id}/end", ['session_date' => '2026-10-06', 'face_verified' => true], $this->entrenador())->assertOk();
        $this->detalle('2026-10-06')->assertOk()->assertJsonPath('session_date', '2026-10-12');
    }

    public function test_tras_la_renovacion_la_sesion_dictada_conserva_a_quien_asistio(): void
    {
        $fue = $this->socio();
        $noFue = $this->socio();
        foreach ([$fue, $noFue] as $s) {
            ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $s->id, 'session_date' => self::LUNES]);
        }
        $this->clase->update(['renewal_hours' => 8]);

        Carbon::setTestNow(Carbon::parse(self::LUNES.' 17:20', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->entrenador())->assertOk();
        $this->postJson("/api/trainer/classes/{$this->clase->id}/attendance", [
            'member_id' => $fue->id, 'session_date' => self::LUNES, 'status' => 'present',
        ], $this->entrenador())->assertOk();
        $this->postJson("/api/trainer/classes/{$this->clase->id}/end", ['session_date' => self::LUNES, 'face_verified' => true], $this->entrenador())->assertOk();

        // 8 horas después se renueva: las reservas de esa sesión se liberan.
        Carbon::setTestNow(Carbon::parse('2026-10-06 02:30', 'America/Bogota')->utc());
        $this->artisan('classes:renew')->assertSuccessful();
        $this->assertSame(0, ClassReservation::where('class_id', $this->clase->id)->count());

        $lista = collect($this->detalle(self::LUNES)->assertOk()->assertJsonPath('session_date', self::LUNES)->json('participants'));

        $this->assertSame([$fue->id], $lista->pluck('member_id')->all(), 'Quien asistió sigue en la lista; quien no, se fue con su reserva.');
        $this->assertSame('present', $lista[0]['status']);
        $this->assertSame('released', $lista[0]['booking_status']);
        $this->assertNull($lista[0]['reservation_id']);

        // Y el entrenador puede corregir esa marca.
        $this->putJson("/api/trainer/classes/{$this->clase->id}/attendance", [
            'member_id' => $fue->id, 'session_date' => self::LUNES, 'status' => 'late', 'note' => 'Llegó tarde',
        ], $this->entrenador())->assertOk();
    }

    public function test_el_aviso_de_clase_iniciada_llega_solo_a_quien_reservo_esa_sesion(): void
    {
        $deHoy = $this->socio();
        $deOtraSemana = $this->socio();
        $deAgosto = $this->socio();
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $deHoy->id, 'session_date' => self::LUNES]);
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $deOtraSemana->id, 'session_date' => '2026-10-12']);
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $deAgosto->id, 'session_date' => '2026-08-10']);

        Carbon::setTestNow(Carbon::parse(self::LUNES.' 17:20', 'America/Bogota')->utc());
        $this->postJson("/api/trainer/classes/{$this->clase->id}/start", ['session_date' => self::LUNES, 'face_verified' => true], $this->entrenador())->assertOk();

        $avisados = Notification::where('audience', Notification::AUDIENCE_MEMBER)->pluck('member_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->assertSame([$deHoy->id], $avisados);
    }
}
