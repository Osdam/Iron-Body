<?php

namespace Tests\Feature\Classes;

use App\Models\Admin;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Services\Trainer\ClassAttendanceService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que encontró la revisión final del módulo de clases.
 *
 *   · Una cuenta del CRM con rol Entrenador que cambia el día de una clase:
 *     el aviso «Tu reserva cambió» de un socio que no tenía asignado salía sin
 *     socio y se publicaba para TODOS.
 *   · Cualquier sesión del CRM veía notas internas y borradores, aunque no
 *     tuviera acceso al módulo de clases.
 *   · Un documento de cuatro caracteres o menos llegaba entero al entrenador.
 *   · Las escrituras de clases del socio no tenían límite, y cada una avisa a
 *     todas las apps conectadas.
 *   · La supervisión aceptaba cualquier rango de fechas.
 */
class ClassReviewFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles 30/09/2026 10:00 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function socio(string $nombre, string $doc): Member
    {
        return Member::create(['full_name' => $nombre, 'document_number' => $doc, 'status' => Member::STATUS_ACTIVE]);
    }

    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Miércoles', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true,
            'is_recurring' => true, 'notes' => 'Llave del salón en recepción',
        ], $extra));
    }

    public function test_un_entrenador_del_crm_que_cambia_el_dia_avisa_a_cada_socio_y_a_nadie_mas(): void
    {
        $coach = Trainer::create(['full_name' => 'Coach Propio', 'document' => '8800100', 'status' => 'active']);
        $clase = $this->clase(['trainer_id' => $coach->id]);
        $asignado = $this->socio('Socio Asignado', '8800002');
        $ajeno = $this->socio('Socio Ajeno', '8800003');
        $this->socio('Testigo Sin Reserva', '8800001');
        MemberTrainerAssignment::create([
            'member_id' => $asignado->id, 'trainer_id' => $coach->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now(),
        ]);
        foreach ([$asignado, $ajeno] as $m) {
            ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => '2026-09-30', 'reserved_at' => now()]);
        }
        $cuenta = Admin::create([
            'name' => 'Cuenta Coach', 'email' => 'coach-propio@ironbody.test', 'password' => 'secret-password',
            'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $coach->id, 'status' => 'active',
        ]);

        // El lunes de esta semana ya pasó: las dos reservas se cancelan con aviso.
        $this->patchJson("/api/classes/{$clase->id}", ['day_of_week' => 'Lunes'], $this->actingAsAdmin($cuenta))->assertOk();

        $avisos = Notification::where('title', 'Tu reserva cambió')->get();
        $this->assertSame(0, $avisos->whereNull('member_id')->count(), 'Ningún aviso sin destinatario (se publicaría para todos).');
        $this->assertEqualsCanonicalizing([$asignado->id, $ajeno->id], $avisos->pluck('member_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_una_sesion_del_crm_sin_acceso_a_clases_ve_lo_mismo_que_el_publico(): void
    {
        $this->clase();
        $this->clase(['name' => 'Borrador', 'status' => 'inactive']);

        $respuesta = $this->getJson('/api/classes', $this->adminWithPermissions(['moderation.view']))->assertOk();

        $this->assertSame(['Funcional'], array_column($respuesta->json('data'), 'name'));
        $this->assertArrayNotHasKey('notes', $respuesta->json('data.0'));
        $this->assertArrayNotHasKey('events_cursor', $respuesta->json());

        $conAcceso = $this->getJson('/api/classes', $this->adminWithPermissions(['classes.view']))->assertOk();
        $this->assertCount(2, $conAcceso->json('data'));
        $this->assertArrayHasKey('events_cursor', $conAcceso->json());
    }

    public function test_un_documento_corto_se_tapa_entero(): void
    {
        $this->assertSame('•••', ClassAttendanceService::maskDocument('987'));
        $this->assertSame('••••', ClassAttendanceService::maskDocument(' 4321 '));
        $this->assertSame('•••5678', ClassAttendanceService::maskDocument('1012345678'));
        $this->assertNull(ClassAttendanceService::maskDocument('  '));
    }

    public function test_las_escrituras_de_clases_tienen_tope_por_socio(): void
    {
        $clase = $this->clase(['max_capacity' => 50]);
        $insistente = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio Insistente', 'document_number' => '9100001', 'phone' => '+573009100001',
            'access_hash' => 'tok-9100001', 'status' => Member::STATUS_ACTIVE,
        ]));
        $otro = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Otro Socio', 'document_number' => '9100002', 'phone' => '+573009100002',
            'access_hash' => 'tok-9100002', 'status' => Member::STATUS_ACTIVE,
        ]));

        $codigos = [];
        for ($i = 0; $i < 31; $i++) {
            $metodo = $i % 2 === 0 ? 'postJson' : 'deleteJson';
            $codigos[] = $this->{$metodo}("/api/app/classes/{$clase->id}/reserve", [], ['Authorization' => 'Bearer tok-9100001'])->status();
        }

        $this->assertNotContains(429, array_slice($codigos, 0, 30), 'Treinta por minuto pasan.');
        $this->assertSame(429, $codigos[30]);
        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], ['Authorization' => 'Bearer tok-9100001'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_class_writes')
            ->assertJsonPath('message', 'Hiciste muchos cambios seguidos en tus clases. Espera un minuto e inténtalo de nuevo.');
        $this->postJson("/api/app/classes/{$clase->id}/reserve", [], ['Authorization' => 'Bearer tok-9100002'])->assertOk();
        $this->assertNotNull($otro->fresh());
        $this->assertNotNull($insistente->fresh());
    }

    public function test_la_supervision_rechaza_rangos_invertidos_o_demasiado_largos(): void
    {
        $this->adminGetJson('/api/admin/class-sessions?from=2026-09-30&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->adminGetJson('/api/admin/class-sessions?from=2026-01-01&to=2026-09-30')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->adminGetJson('/api/admin/class-sessions?from=2026-07-01&to=2026-09-30')->assertOk();
    }
}
