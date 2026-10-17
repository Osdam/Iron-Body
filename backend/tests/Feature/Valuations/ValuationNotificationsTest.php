<?php

namespace Tests\Feature\Valuations;

use App\Http\Controllers\Crm\NotificationController;
use App\Models\Admin;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La bandeja EXISTENTE del CRM con la columna `required_permission` (contrato
 * §0.9 y §5): lo que la sesión no puede ver no aparece, no cuenta, no viaja por
 * el stream y no se puede marcar. Y lo de siempre (`required_permission` nulo)
 * se comporta exactamente igual que antes.
 */
class ValuationNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private array $recepcion;

    private array $entrenador;

    /** Una notificación «de siempre», sin permiso exigido, que ven todos los roles (tipo `system`). */
    private Notification $general;

    private Notification $valoracion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $andres = $this->trainer('Andrés Pérez');
        $this->everyDay($andres);
        $this->recepcion = $this->reception();
        $this->entrenador = $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR, $andres));

        // Una notificación «de siempre» (sin permiso exigido) y una de la agenda,
        // nacida del flujo real (agendar). Es de `system` y no de `payment`: desde
        // las alertas sonoras generales, un pago solo lo ve quien ve pagos (P-1,
        // ver AdminNotificationVisibilityTest), y aquí se prueba otra cosa.
        $this->general = app(NotificationService::class)->createAdminNotification([
            'type' => 'system', 'title' => 'Aviso del sistema', 'message' => 'Para todos', 'event_key' => 'prueba-general-1',
        ]);
        $uuid = $this->book($this->recepcion, $this->member('Laura Gómez'), $andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->valoracion = Notification::where('type', 'valuation')->firstOrFail();
        $this->assertSame($uuid, $this->valoracion->action_payload['appointment_uuid']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_aviso_interno_de_la_agenda_exige_view_all_y_no_lleva_el_documento(): void
    {
        $n = $this->valoracion;
        $this->assertSame('admin', $n->audience);
        $this->assertSame('valuations.view_all', $n->required_permission);
        $this->assertSame('valuation_detail', $n->action_type);
        $this->assertNull($n->member_id);
        $this->assertNull($n->document);
        $this->assertSame('Valoración agendada', $n->title);
        $this->assertSame('Nueva valoración — Laura G. con Andrés, 24 de octubre a las 9:00 a. m.', $n->message);
        $this->assertNull($this->general->required_permission);
    }

    public function test_index_y_contador_filtran_por_permiso(): void
    {
        $conPermiso = $this->getJson('/api/admin/notifications', $this->recepcion)->assertOk();
        $this->assertEqualsCanonicalizing([$this->general->uuid, $this->valoracion->uuid], array_column($conPermiso->json('data'), 'uuid'));
        $this->assertSame(2, $conPermiso->json('unread_count'));
        $this->getJson('/api/admin/notifications/unread-count', $this->recepcion)->assertJsonPath('unread_count', 2);

        $sinPermiso = $this->getJson('/api/admin/notifications', $this->entrenador)->assertOk();
        $this->assertSame([$this->general->uuid], array_column($sinPermiso->json('data'), 'uuid'));
        $this->assertSame(1, $sinPermiso->json('unread_count'));
        $this->getJson('/api/admin/notifications/unread-count', $this->entrenador)->assertJsonPath('unread_count', 1);
        // Ni buscándola.
        $this->assertSame([], $this->getJson('/api/admin/notifications?search=Laura', $this->entrenador)->json('data'));
    }

    public function test_categoria_valuations(): void
    {
        foreach (['valuations', 'valoraciones', 'valuation'] as $cat) {
            $this->assertSame([$this->valoracion->uuid], array_column($this->getJson('/api/admin/notifications?category='.$cat, $this->recepcion)->json('data'), 'uuid'), $cat);
        }
        $this->assertSame([], $this->getJson('/api/admin/notifications?category=valuations', $this->entrenador)->json('data'));
    }

    public function test_lo_invisible_no_se_marca_ni_sola_ni_con_marcar_todas(): void
    {
        $this->postJson("/api/admin/notifications/{$this->valoracion->uuid}/read", [], $this->entrenador)->assertStatus(404);
        $this->assertSame('unread', $this->valoracion->fresh()->status);

        $this->postJson('/api/admin/notifications/read-all', [], $this->entrenador)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('read', $this->general->fresh()->status);
        $this->assertSame('unread', $this->valoracion->fresh()->status, 'marcar todas no toca lo que no ve');

        $this->postJson("/api/admin/notifications/{$this->valoracion->uuid}/read", [], $this->recepcion)->assertOk();
        $this->assertSame('read', $this->valoracion->fresh()->status);
    }

    public function test_las_notificaciones_existentes_se_comportan_igual_para_todos(): void
    {
        // La de siempre: la ve, la cuenta y la marca cualquier cuenta, como antes.
        foreach ([$this->recepcion, $this->entrenador, $this->h($this->superAdmin())] as $h) {
            $this->assertContains($this->general->uuid, array_column($this->getJson('/api/admin/notifications', $h)->json('data'), 'uuid'));
        }
        $this->postJson("/api/admin/notifications/{$this->general->uuid}/read", [], $this->entrenador)->assertOk()->assertJsonPath('data.status', 'read');

        // Crear a mano ya NO es de cualquier sesión (P-2): exige `content.manage`.
        // Quien puede, la crea igual que antes y sin permiso exigido.
        $this->postJson('/api/admin/notifications', ['title' => 'Nota', 'message' => 'Interna', 'audience' => 'admin'], $this->entrenador)->assertForbidden();
        $this->postJson('/api/admin/notifications', ['title' => 'Nota', 'message' => 'Interna', 'audience' => 'admin'], $this->h($this->superAdmin()))->assertCreated();
        $this->assertNull(Notification::where('title', 'Nota')->value('required_permission'));
        $this->assertSame(['uuid', 'type', 'audience', 'title', 'message', 'status', 'priority', 'document', 'member_id', 'action_type', 'action_url', 'action_payload', 'metadata', 'should_popup', 'read_at', 'created_at', 'time_ago'],
            array_keys($this->getJson('/api/admin/notifications', $this->recepcion)->json('data.0')), 'la forma pública no cambia');
    }

    /**
     * Revisión de seguridad V-1. `type=valuation` es del sistema: a mano, una
     * `valuation` + `high` hacía sonar la alerta de la agenda en todo el CRM y
     * la veía cualquiera (sin permiso exigido). Ahora 422, con cualquier
     * prioridad y con cualquier cuenta, y sin escribir nada. Lo demás, igual.
     */
    public function test_crear_a_mano_una_notificacion_de_valoracion_se_rechaza(): void
    {
        $root = $this->h($this->superAdmin());
        $antes = Notification::where('type', 'valuation')->count();

        // Desde P-2 la recepción y el entrenador ni siquiera pueden crear a mano (403);
        // quien sí puede recibe 422 para el tipo reservado.
        foreach ([[$this->entrenador, 403], [$this->recepcion, 403], [$root, 422]] as [$h, $esperado]) {
            foreach (['valuation', 'Valuation', ' VALUATION '] as $tipo) {
                foreach ([['priority' => 'high', 'audience' => 'admin'], ['audience' => 'admin'], ['priority' => 'high'], ['required_permission' => 'valuations.view_all', 'audience' => 'admin']] as $extra) {
                    $r = $this->postJson('/api/admin/notifications', array_merge(['title' => 'Valoración próxima', 'message' => 'Falsa', 'type' => $tipo], $extra), $h)
                        ->assertStatus($esperado);
                    if ($esperado === 422) {
                        $r->assertJsonValidationErrors('type');
                    }
                }
            }
        }
        $this->assertSame($antes, Notification::where('type', 'valuation')->count(), 'ninguna fila nueva');
        $this->assertSame(0, Notification::where('title', 'Valoración próxima')->count());

        // Las manuales de siempre: igual que antes (tipo libre, prioridad alta,
        // interna o al socio) y SIN permiso exigido aunque se envíe uno.
        $this->postJson('/api/admin/notifications', ['title' => 'Aviso alto', 'message' => 'Interna', 'audience' => 'admin', 'priority' => 'high', 'type' => 'system', 'required_permission' => 'valuations.view_all'], $root)
            ->assertCreated()->assertJsonPath('data.type', 'system')->assertJsonPath('data.priority', 'high');
        $this->assertNull(Notification::where('title', 'Aviso alto')->value('required_permission'));
        $this->postJson('/api/admin/notifications', ['title' => 'Promo', 'message' => 'Para todos'], $root)
            ->assertCreated()->assertJsonPath('data.type', 'promotion')->assertJsonPath('data.audience', 'member');
        // A mano solo `system` y `promotion` (lista de permitidos): un tipo cualquiera ya no entra.
        $this->postJson('/api/admin/notifications', ['title' => 'Otro tipo', 'message' => 'x', 'type' => 'valuations_report', 'audience' => 'admin'], $root)
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    /**
     * El stream: el lote que empuja en cada vuelta sale de `streamBatch()` del
     * controlador REAL, con los permisos de la sesión que lo abre. No se lee el
     * cuerpo del SSE porque `SseStream` cierra todos los búferes de salida (en
     * una prueba se llevaría también los de PHPUnit); la prueba de punta a punta
     * con un servidor real y `curl` está en el guion de la carrera (informe).
     */
    public function test_el_stream_no_empuja_lo_que_la_sesion_no_puede_ver(): void
    {
        $controlador = app(NotificationController::class);
        $lote = new \ReflectionMethod($controlador, 'streamBatch');
        $permisosDe = fn (string $rol) => CrmPermission::defaultsFor($rol);

        $sin = $lote->invoke($controlador, $permisosDe(CrmPermission::ROLE_ENTRENADOR), 0)->pluck('uuid')->all();
        $this->assertSame([$this->general->uuid], $sin);

        $con = $lote->invoke($controlador, $permisosDe(Admin::ROLE_RECEPCION), 0)->pluck('uuid')->all();
        $this->assertSame([$this->general->uuid, $this->valoracion->uuid], $con);

        // Y el cursor sigue mandando igual que antes.
        $this->assertSame([$this->valoracion->uuid], $lote->invoke($controlador, $permisosDe(Admin::ROLE_RECEPCION), $this->general->id)->pluck('uuid')->all());

        // El stream real abre con el vale de una sesión sin el permiso.
        $ticket = $this->getJson('/api/admin/stream-ticket', $this->entrenador)->assertOk()->json('data.ticket');
        $this->assertNotNull($ticket);
    }
}
