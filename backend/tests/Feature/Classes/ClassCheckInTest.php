<?php

namespace Tests\Feature\Classes;

use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El check-in del socio, por las dos puertas de la app.
 *
 * Antes valía una reserva de CUALQUIER semana y pisaba la marca del entrenador:
 * quien él había marcado ausente se pasaba a presente con su propio check-in.
 * Ahora exige la reserva de la sesión en curso, respeta la marca que ya haya y
 * se lo dice al socio en vez de contestar «Asistencia registrada».
 */
class ClassCheckInTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    private MyClass $clase;

    private Member $socio;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 18:10', 'America/Bogota')->utc());
        $this->clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes',
            'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'active',
            'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        $this->socio = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socia Puntual', 'document_number' => '3130001', 'phone' => '+573003130001',
            'access_hash' => 'tok-3130001', 'status' => Member::STATUS_ACTIVE,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    public static function puertas(): array
    {
        return [['/api/app/classes/%d/check-in'], ['/api/classes/%d/check-in']];
    }

    private function checkIn(string $puerta)
    {
        return $this->postJson(sprintf($puerta, $this->clase->id), [], ['Authorization' => 'Bearer '.$this->socio->access_hash]);
    }

    private function reservar(string $fecha = self::LUNES): void
    {
        ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $this->socio->id, 'session_date' => $fecha]);
    }

    private function iniciar(bool $cerrada = false): void
    {
        ClassSession::create([
            'class_id' => $this->clase->id, 'session_date' => self::LUNES,
            'started_at' => Carbon::parse(self::LUNES.' 18:01', 'America/Bogota')->utc(),
            'ended_at' => $cerrada ? Carbon::parse(self::LUNES.' 18:05', 'America/Bogota')->utc() : null,
        ]);
    }

    private function marca(): ?string
    {
        return ClassAttendance::where('class_id', $this->clase->id)->where('member_id', $this->socio->id)->value('status');
    }

    #[DataProvider('puertas')]
    public function test_sin_sesion_iniciada_no_hay_check_in(string $puerta): void
    {
        $this->reservar();

        $this->checkIn($puerta)->assertStatus(422)->assertJsonPath('message', 'La clase aún no ha iniciado.');
        $this->assertNull($this->marca());
    }

    #[DataProvider('puertas')]
    public function test_una_reserva_de_otra_semana_no_vale_para_la_de_hoy(string $puerta): void
    {
        $this->reservar('2026-10-12');
        $this->iniciar();

        $this->checkIn($puerta)->assertStatus(422)->assertJsonPath('message', 'No tienes reserva en esta clase.');
        $this->assertNull($this->marca());
    }

    #[DataProvider('puertas')]
    public function test_con_la_clase_cerrada_no_hay_check_in(string $puerta): void
    {
        $this->reservar();
        $this->iniciar(cerrada: true);

        $this->checkIn($puerta)->assertStatus(422)->assertJsonPath('message', 'La clase ya finalizó.');
    }

    #[DataProvider('puertas')]
    public function test_en_curso_y_con_reserva_queda_presente_una_sola_vez(string $puerta): void
    {
        $this->reservar();
        $this->iniciar();

        $this->checkIn($puerta)->assertOk()->assertJsonPath('message', 'Asistencia registrada.');
        $this->checkIn($puerta)->assertOk()->assertJsonPath('message', 'Tu asistencia ya estaba registrada.');

        $this->assertSame('present', $this->marca());
        $this->assertSame(1, ClassAttendance::count());
    }

    #[DataProvider('puertas')]
    public function test_la_marca_de_ausente_del_entrenador_se_respeta_y_se_dice(string $puerta): void
    {
        $this->reservar();
        $this->iniciar();
        ClassAttendance::create([
            'class_id' => $this->clase->id, 'member_id' => $this->socio->id, 'session_date' => self::LUNES,
            'status' => 'absent', 'marked_at' => now(),
        ]);

        $this->checkIn($puerta)
            ->assertStatus(422)
            ->assertJsonPath('message', 'El entrenador ya registró tu asistencia como ausente. Si llegaste, pídele que la corrija.');

        $this->assertSame('absent', $this->marca(), 'El socio no puede pasarse a presente.');
    }

    #[DataProvider('puertas')]
    public function test_la_marca_de_tarde_del_entrenador_se_respeta(string $puerta): void
    {
        $this->reservar();
        $this->iniciar();
        ClassAttendance::create([
            'class_id' => $this->clase->id, 'member_id' => $this->socio->id, 'session_date' => self::LUNES,
            'status' => 'late', 'marked_at' => now(),
        ]);

        $this->checkIn($puerta)->assertOk()->assertJsonPath('message', 'Tu asistencia ya estaba registrada.');
        $this->assertSame('late', $this->marca());
    }
}
