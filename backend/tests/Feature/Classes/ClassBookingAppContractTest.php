<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Services\Classes\ClassBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El contrato de la APP con el dominio de reservas, fijado ANTES de mover la
 * lógica a {@see ClassBookingService}.
 *
 * La app publicada enseña estos textos literalmente y reacciona a estos
 * códigos: no se pueden cambiar sin publicar otra versión. Cada prueba de aquí
 * se ejecutó contra el commit anterior al refactor y pasa igual en los dos:
 * mover el código no puede cambiar lo que ve un socio.
 *
 * Hay DOS puertas y con textos distintos, las dos en producción:
 *   · `/api/classes/{id}/reserve|cancel` (alias heredado, la que usa la app)
 *   · `/api/app/classes/{id}/reserve` (la nueva, con notificaciones)
 */
class ClassBookingAppContractTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes 05/10/2026 10:00 en Bogotá. */
    private const NOW_UTC = '2026-10-05 15:00:00';

    /** La ocurrencia operativa de una clase de los miércoles. */
    private const OCCURRENCE = '2026-10-07';

    private int $doc = 800100100;

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

    private function socio(bool $conClases = true): Member
    {
        $doc = (string) $this->doc++;
        $m = Member::create([
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ]);

        return $conClases ? $this->givePlanWithClasses($m) : $m;
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    private function entrenador(): Trainer
    {
        return Trainer::create(['full_name' => 'Coach Contrato', 'document' => '555000111', 'status' => 'active']);
    }

    private function clase(int $cupo = 10, array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Spinning', 'type' => 'Spinning',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'status' => 'active', 'max_capacity' => $cupo, 'allow_online_booking' => true,
        ], $extra));
    }

    private function señalesDeClase(): int
    {
        return MemberRealtimeEvent::where('type', 'class.updated')->count();
    }

    // ── Puerta heredada: /api/classes/{id}/reserve ──────────────────────────

    public function test_legacy_reservar_crea_la_reserva_de_la_ocurrencia_y_avisa(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase(10, ['trainer_id' => $coach->id]);
        $socio = $this->socio();

        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('data.is_reserved', true)
            ->assertJsonPath('data.booked_spots', 1)
            ->assertJsonPath('data.available_spots', 9);

        $fila = ClassReservation::where('class_id', $clase->id)->where('member_id', $socio->id)->sole();
        $this->assertSame(self::OCCURRENCE, $fila->session_date->toDateString());

        $this->assertGreaterThan(0, $this->señalesDeClase(), 'La app de los socios debe enterarse del cupo.');
        $this->assertTrue(
            TrainerRealtimeEvent::where('trainer_id', $coach->id)->where('type', 'class.updated')->exists(),
            'El portal del entrenador debe enterarse.',
        );
    }

    public function test_legacy_reservar_dos_veces_responde_el_texto_de_siempre(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();

        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Ya tienes esta clase reservada.']);

        $this->assertSame(1, ClassReservation::count());
    }

    public function test_legacy_clase_llena_responde_el_texto_de_siempre(): void
    {
        $clase = $this->clase(1);
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($this->socio()))->assertOk();

        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($this->socio()))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No hay cupos disponibles.']);

        $this->assertSame(1, ClassReservation::count());
    }

    public function test_legacy_clase_sin_reserva_online_o_inactiva_no_se_reserva(): void
    {
        $sinOnline = $this->clase(10, ['allow_online_booking' => false]);
        $inactiva = $this->clase(10, ['status' => 'inactive']);
        $socio = $this->socio();

        foreach ([$sinOnline, $inactiva] as $clase) {
            $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Clase no disponible para reservas.']);
        }
        $this->assertSame(0, ClassReservation::count());
    }

    public function test_legacy_plan_sin_clases_responde_403_con_codigo(): void
    {
        $clase = $this->clase();

        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($this->socio(conClases: false)))
            ->assertStatus(403)
            ->assertJsonPath('code', 'classes_not_in_plan')
            ->assertJsonPath('message', 'Tu plan no incluye reserva de clases.');
    }

    public function test_legacy_cancelar_borra_la_reserva_y_avisa(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();
        $antes = $this->señalesDeClase();

        $this->postJson("/api/classes/{$clase->id}/cancel", [], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('data.is_reserved', false)
            ->assertJsonPath('data.booked_spots', 0);

        $this->assertSame(0, ClassReservation::count());
        $this->assertGreaterThan($antes, $this->señalesDeClase(), 'Liberar cupo también se anuncia.');
    }

    public function test_legacy_cancelar_sin_reserva_responde_el_texto_de_siempre(): void
    {
        $clase = $this->clase();

        $this->postJson("/api/classes/{$clase->id}/cancel", [], $this->auth($this->socio()))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No tienes reserva en esta clase.']);
    }

    public function test_legacy_no_se_cancela_una_clase_en_curso_ni_una_finalizada(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();

        $sesion = ClassSession::create([
            'class_id' => $clase->id, 'session_date' => self::OCCURRENCE, 'started_at' => now(),
        ]);
        $this->postJson("/api/classes/{$clase->id}/cancel", ['session_date' => self::OCCURRENCE], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'La clase ya está en curso.']);

        $sesion->update(['ended_at' => now()]);
        $this->postJson("/api/classes/{$clase->id}/cancel", ['session_date' => self::OCCURRENCE], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'La clase ya finalizó.']);

        $this->assertSame(1, ClassReservation::count(), 'La reserva sigue: nada se borró.');
    }

    // ── Puerta nueva: /api/app/classes/{id}/reserve ─────────────────────────

    public function test_app_reservar_notifica_al_socio_y_al_crm(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();

        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('data.is_reserved', true);

        $this->assertTrue(Notification::where('audience', Notification::AUDIENCE_MEMBER)
            ->where('member_id', $socio->id)->where('title', 'Clase reservada')->exists());
        $this->assertTrue(Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('title', 'Nueva reserva de clase')->exists());
        $this->assertGreaterThan(0, $this->señalesDeClase());
    }

    public function test_app_reservar_dos_veces_y_clase_llena_responden_sus_textos(): void
    {
        $clase = $this->clase(1);
        $socio = $this->socio();
        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();

        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Ya tienes una reserva para esta clase.']);

        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($this->socio()))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Clase completa.']);

        // Llenarla avisa al CRM.
        $this->assertTrue(Notification::where('audience', Notification::AUDIENCE_ADMIN)
            ->where('title', 'Clase sin cupos')->exists());
    }

    public function test_app_cancelar_notifica_y_sin_reserva_responde_su_texto(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();

        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertOk()
            ->assertJsonPath('data.is_reserved', false);
        $this->assertTrue(Notification::where('audience', Notification::AUDIENCE_MEMBER)
            ->where('member_id', $socio->id)->where('title', 'Reserva cancelada')->exists());

        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'No tienes reserva en esta clase.']);
    }

    public function test_app_no_se_cancela_una_clase_en_curso_ni_una_finalizada(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], $this->auth($socio))->assertOk();

        $sesion = ClassSession::create([
            'class_id' => $clase->id, 'session_date' => self::OCCURRENCE, 'started_at' => now(),
        ]);
        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", ['session_date' => self::OCCURRENCE], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'La clase ya está en curso.']);

        $sesion->update(['ended_at' => now()]);
        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", ['session_date' => self::OCCURRENCE], $this->auth($socio))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'La clase ya finalizó.']);

        $this->assertSame(1, ClassReservation::count());
    }

    // ── «Organizar mi semana» ───────────────────────────────────────────────

    public function test_plan_semanal_clasifica_cada_ocurrencia_y_avisa_una_sola_vez(): void
    {
        $coach = $this->entrenador();
        $normal = $this->clase(10, ['trainer_id' => $coach->id]);
        $otra = $this->clase(10, ['name' => 'Yoga', 'day_of_week' => 'Jueves', 'trainer_id' => $coach->id]);
        $sinOnline = $this->clase(10, ['name' => 'Privada', 'allow_online_booking' => false]);
        $enCurso = $this->clase(10, ['name' => 'En curso', 'day_of_week' => 'Lunes', 'start_time' => '09:00', 'end_time' => '11:00']);
        $cerrada = $this->clase(10, ['name' => 'Cerrada', 'day_of_week' => 'Lunes', 'start_time' => '06:00', 'end_time' => '07:00']);
        ClassSession::create(['class_id' => $enCurso->id, 'session_date' => '2026-10-05', 'started_at' => now()]);
        ClassSession::create(['class_id' => $cerrada->id, 'session_date' => '2026-10-05', 'started_at' => now(), 'ended_at' => now()]);

        $socio = $this->socio();
        $r = $this->postJson('/api/app/classes/weekly/reserve', ['items' => [
            ['class_id' => $normal->id, 'session_date' => self::OCCURRENCE],
            ['class_id' => $otra->id, 'session_date' => '2026-10-08'],
            ['class_id' => $sinOnline->id, 'session_date' => self::OCCURRENCE],
            ['class_id' => $enCurso->id, 'session_date' => '2026-10-05'],
            ['class_id' => $cerrada->id, 'session_date' => '2026-10-05'],
            ['class_id' => $normal->id, 'session_date' => '2026-09-30'],
        ]], $this->auth($socio))->assertOk();

        $r->assertJsonPath('summary.reserved', 2)
            ->assertJsonPath('summary.unavailable', 1)
            ->assertJsonPath('summary.live', 1)
            ->assertJsonPath('summary.closed', 1)
            ->assertJsonPath('summary.past', 1)
            ->assertJsonPath('summary.failed', 4);

        // Repetir la misma ocurrencia: «ya reservada», no una segunda fila.
        $this->postJson('/api/app/classes/weekly/reserve', ['items' => [
            ['class_id' => $normal->id, 'session_date' => self::OCCURRENCE],
        ]], $this->auth($socio))->assertOk()->assertJsonPath('summary.already', 1);
        $this->assertSame(2, ClassReservation::where('member_id', $socio->id)->count());

        // Dos reservas en un envío = UN aviso a cada socio y UNO al entrenador.
        $this->assertSame(1, MemberRealtimeEvent::where('member_id', $socio->id)->where('type', 'class.updated')->count());
        $this->assertSame(1, TrainerRealtimeEvent::where('trainer_id', $coach->id)->where('type', 'class.updated')->count());
    }
}
