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
use App\Services\Classes\ClassBookingService;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\ClassSessionService;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que la mutación del backend dejó sin cubrir: cada prueba existe porque un
 * cambio concreto en el código pasaba la suite sin que nada fallara.
 */
class ClassMutationGapsTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 18:10', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ], $extra));
    }

    private function socioApp(string $doc): Member
    {
        return $this->givePlanWithClasses(Member::create([
            'full_name' => "Socio {$doc}", 'document_number' => $doc, 'phone' => '+57300'.$doc,
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
        ]));
    }

    /** Mutante B03: una fecha imposible no es una fecha. */
    public function test_una_fecha_de_calendario_imposible_no_se_acepta(): void
    {
        $booking = app(ClassBookingService::class);

        $this->assertNull($booking->plainDate('2026-02-30'));
        $this->assertNull($booking->plainDate('2026-13-01'));
        $this->assertSame('2026-02-28', $booking->plainDate('2026-02-28T10:00:00Z'), 'Con hora o zona se recorta al día.');
    }

    /** Mutante B16: las reservas heredadas sin fecha cuentan en los listados. */
    public function test_los_listados_cuentan_la_reserva_heredada_sin_fecha(): void
    {
        $clase = $this->clase();
        $socio = $this->socioApp('5180001');
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => null]);

        $publico = collect($this->getJson('/api/classes')->assertOk()->json('data'))->firstWhere('id', (string) $clase->id);
        $this->assertSame(1, $publico['booked_spots']);

        $app = collect($this->getJson('/api/app/classes', ['Authorization' => 'Bearer tok-5180001'])->assertOk()->json('data'))->firstWhere('id', (string) $clase->id);
        $this->assertTrue($app['is_reserved'], 'Es suya.');
        $this->assertSame(1, $app['booked_spots']);
    }

    /** Mutante B36: una sesión olvidada abierta hace días no admite check-in. */
    public function test_no_hay_check_in_en_una_sesion_olvidada_de_hace_dias(): void
    {
        $clase = $this->clase();
        $socio = $this->socioApp('5180002');
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => '2026-09-21']);
        ClassSession::create(['class_id' => $clase->id, 'session_date' => '2026-09-21', 'started_at' => Carbon::parse('2026-09-21 18:02', 'America/Bogota')->utc()]);

        $this->postJson("/api/app/classes/{$clase->id}/check-in", [], ['Authorization' => 'Bearer tok-5180002'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La clase aún no ha iniciado.');
        $this->assertSame(0, ClassAttendance::count());
    }

    /** Mutante B41: el detalle del entrenador no se queda en una sesión olvidada de otra semana. */
    public function test_el_detalle_no_cae_en_una_sesion_olvidada_de_otra_semana(): void
    {
        config(['trainer.flags.trainer_auth_enabled' => true, 'trainer.flags.trainer_classes_enabled' => true]);
        $coach = Trainer::create(['full_name' => 'Coach Olvidadizo', 'document' => '5180100', 'phone' => '+573005180100', 'status' => 'active']);
        app(IdentityLinkService::class)->backfillExisting();
        $coach->refresh()->syncRoles([TrainerRole::FUNCTIONAL]);
        $clase = $this->clase(['trainer_id' => $coach->id, 'day_of_week' => 'Miércoles']);
        ClassSession::create(['class_id' => $clase->id, 'session_date' => '2026-09-23', 'started_at' => Carbon::parse('2026-09-23 18:01', 'America/Bogota')->utc()]);
        $token = app(TrainerSessionService::class)->issueSession($coach, ['device_id' => 'mut-b41'])['token'];

        $this->getJson("/api/trainer/classes/{$clase->id}?session_date=".self::LUNES, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('session_date', '2026-10-07');
    }

    /** Mutante B53: la supervisión cuenta las reservas de CADA sesión, no las de toda la ventana. */
    public function test_la_supervision_no_suma_las_reservas_de_otras_sesiones_de_la_ventana(): void
    {
        $clase = $this->clase();
        foreach (['2026-09-28' => 2, self::LUNES => 1] as $fecha => $n) {
            ClassSession::create(['class_id' => $clase->id, 'session_date' => $fecha]);
            for ($i = 0; $i < $n; $i++) {
                $m = Member::create(['full_name' => "S {$fecha} {$i}", 'document_number' => '518'.str_replace('-', '', $fecha).$i, 'status' => Member::STATUS_ACTIVE]);
                ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => $fecha]);
            }
        }

        $filas = collect($this->adminGetJson('/api/admin/class-sessions')->assertOk()->json('data'))->keyBy('session_date');

        $this->assertSame(2, $filas['2026-09-28']['enrolled']);
        $this->assertSame(1, $filas[self::LUNES]['enrolled']);
    }

    /** Mutante B68: iniciar la sesión deja su hecho para el CRM. */
    public function test_iniciar_la_sesion_deja_su_hecho_para_el_crm(): void
    {
        $coach = Trainer::create(['full_name' => 'Coach Inicio', 'document' => '5180200', 'status' => 'active']);
        $clase = $this->clase(['trainer_id' => $coach->id]);

        app(ClassSessionService::class)->start($clase, $coach, Carbon::parse(self::LUNES), true);

        $hecho = ClassEvent::where('class_id', $clase->id)->sole();
        $this->assertSame('class.updated', $hecho->type);
        $this->assertSame('session.started', $hecho->reason);
        $this->assertSame('trainer', $hecho->source);
        $this->assertSame(self::LUNES, $hecho->session_date->toDateString());
    }

    /** Mutante B72: el listado se pide con orden estable (en SQLite el orden de inserción lo ocultaba). */
    public function test_el_listado_de_clases_se_pide_ordenado_por_id(): void
    {
        $this->clase();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->adminGetJson('/api/classes')->assertOk();

        $consulta = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'from "classes"') && str_contains($q, 'limit'));
        $this->assertNotNull($consulta);
        $this->assertMatchesRegularExpression('/order by "id" asc/i', $consulta);
    }
}
