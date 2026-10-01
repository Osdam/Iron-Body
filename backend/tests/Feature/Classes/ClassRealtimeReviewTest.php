<?php

namespace Tests\Feature\Classes;

use App\Http\Controllers\Api\Admin\ClassEnrollmentController;
use App\Http\Controllers\Api\MemberRealtimeController;
use App\Models\CatalogEvent;
use App\Models\ClassAttendance;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use App\Services\RealtimeEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que encontró la revisión de corrección sobre el tiempo real y el estado
 * de la clase para el socio.
 *
 *   · La app que volvía de segundo plano con un cursor viejo recibía una señal
 *     de clases por cada reserva de la pausa (se guardaban 7 días) y recargaba
 *     con cada una.
 *   · La app 1.0.x, que no reanuda el canal global, perdía las señales del
 *     hueco de cada reconexión.
 *   · «Marcar presente» seguía ofrecido cuando el entrenador ya había marcado.
 *   · El registro de hechos debe escribirse en un savepoint propio (PostgreSQL
 *     aborta la transacción entera ante un error).
 *   · La huella del CRM agregaba toda la asistencia histórica cada 2 s.
 */
class ClassRealtimeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 18:10', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function senal(string $type, Carbon $cuando, ?int $producto = null): CatalogEvent
    {
        return CatalogEvent::create([
            'type' => $type, 'product_id' => $producto, 'changed' => ['x'], 'version' => 1, 'created_at' => $cuando,
        ]);
    }

    public function test_las_senales_de_clases_viejas_se_podan_al_escribir_y_las_de_producto_no(): void
    {
        $vieja = $this->senal('reservation.created', now()->subMinutes(6));
        $producto = $this->senal('catalog.product.changed', now()->subMinutes(6), 9);

        RealtimeEvents::classesChanged(7, '2026-10-05', 'reservation.cancelled');

        $this->assertNull(CatalogEvent::find($vieja->id), 'Pasados 5 minutos ya no sirve para reanudar.');
        $this->assertNotNull(CatalogEvent::find($producto->id));
        $this->assertSame(1, CatalogEvent::where('type', 'reservation.cancelled')->count());
    }

    public function test_de_cada_lectura_sale_un_solo_aviso_de_clases_y_todos_los_de_producto(): void
    {
        $filas = collect([
            $a = $this->senal('class.updated', now()),
            $b = $this->senal('catalog.product.changed', now(), 1),
            $c = $this->senal('reservation.created', now()),
            $d = $this->senal('catalog.product.changed', now(), 2),
            $e = $this->senal('reservation.cancelled', now()),
        ]);

        $emitidos = array_map(fn (CatalogEvent $x) => $x->id, MemberRealtimeController::catalogToEmit($filas));

        $this->assertSame([$b->id, $d->id, $e->id], $emitidos, 'En orden de id, y de clases solo el último.');
        $this->assertNotContains($a->id, $emitidos);
        $this->assertNotContains($c->id, $emitidos);
    }

    public function test_el_cursor_inicial_del_canal_global_segun_el_cliente(): void
    {
        $antigua = $this->senal('catalog.product.changed', now()->subSeconds(30), 1);
        $reciente = $this->senal('reservation.created', now()->subSeconds(3));

        $this->assertSame(55, MemberRealtimeController::initialCatalogCursor(Request::create('/api/member/realtime', 'GET', ['after_catalog_id' => 55])));
        $this->assertSame($reciente->id, MemberRealtimeController::initialCatalogCursor(Request::create('/api/member/realtime')), 'Sin cursor: desde ahora.');
        $this->assertSame(
            $antigua->id,
            MemberRealtimeController::initialCatalogCursor(Request::create('/api/member/realtime', 'GET', ['after_id' => 12])),
            'La app 1.0.x (solo after_id) recibe las señales de los últimos segundos: cubren su reconexión.',
        );
    }

    public function test_marcar_presente_solo_se_ofrece_sin_marca_previa(): void
    {
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        $socio = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socia Marcada', 'document_number' => '5170001', 'phone' => '+573005170001',
            'access_hash' => 'tok-5170001', 'status' => Member::STATUS_ACTIVE,
        ]));
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => '2026-10-05']);
        ClassSession::create(['class_id' => $clase->id, 'session_date' => '2026-10-05', 'started_at' => now()->subMinutes(9)]);
        $vista = fn () => collect($this->getJson('/api/app/classes', ['Authorization' => 'Bearer tok-5170001'])->assertOk()->json('data'))
            ->firstWhere('id', (string) $clase->id);

        $this->assertTrue($vista()['can_check_in'], 'Sin marca: se ofrece.');

        ClassAttendance::create([
            'class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => '2026-10-05', 'status' => 'late', 'marked_at' => now(),
        ]);
        $this->assertFalse($vista()['can_check_in'], 'El entrenador ya marcó «tarde».');
    }

    public function test_el_listado_de_la_app_no_consulta_clase_a_clase(): void
    {
        $socio = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio Consultas', 'document_number' => '5170002', 'phone' => '+573005170002',
            'access_hash' => 'tok-5170002', 'status' => Member::STATUS_ACTIVE,
        ]));
        $consultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/app/classes', ['Authorization' => 'Bearer tok-5170002'])->assertOk();

            return count(DB::getQueryLog());
        };
        $nueva = fn (int $i) => MyClass::create([
            'name' => "Clase {$i}", 'type' => 'Funcional', 'day_of_week' => 'Martes', 'start_time' => '07:00',
            'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);

        $nueva(1);
        $nueva(2);
        $conDos = $consultas();
        foreach (range(3, 12) as $i) {
            $nueva($i);
        }

        $this->assertSame($conDos, $consultas(), 'Las mismas consultas con 2 que con 12 clases.');
        $this->assertNotNull($socio);
    }

    public function test_el_hecho_se_escribe_en_un_savepoint_propio_dentro_de_la_reserva(): void
    {
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        $socio = $this->givePlanWithClasses(Member::create(['full_name' => 'Socio Nivel', 'document_number' => '5170003', 'status' => Member::STATUS_ACTIVE]));
        $niveles = [];
        ClassReservation::created(function () use (&$niveles) {
            $niveles['reserva'] = DB::transactionLevel();
        });
        ClassEvent::creating(function () use (&$niveles) {
            $niveles['hecho'] = DB::transactionLevel();
        });

        $this->assertTrue(app(ClassBookingService::class)->enrollByStaff($socio, $clase, null)->ok);

        // En PostgreSQL un error dentro de una transacción la invalida entera: el
        // hecho va un nivel más adentro (savepoint) para que su fallo no tumbe la
        // reserva. SQLite no aborta, así que aquí se comprueba la estructura.
        $this->assertSame($niveles['reserva'] + 1, $niveles['hecho']);
    }

    public function test_la_huella_del_crm_mira_solo_la_ventana_reciente(): void
    {
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '18:00',
            'end_time' => '19:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        $socio = Member::create(['full_name' => 'Socio Histórico', 'document_number' => '5170004', 'status' => Member::STATUS_ACTIVE]);
        $antes = ClassEnrollmentController::classesSignature();

        ClassAttendance::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => '2026-08-03', 'status' => 'present', 'marked_at' => now()]);
        $this->assertSame($antes, ClassEnrollmentController::classesSignature(), 'Una marca de hace dos meses no la mueve.');

        ClassAttendance::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => '2026-10-05', 'status' => 'present', 'marked_at' => now()]);
        $this->assertNotSame($antes, ClassEnrollmentController::classesSignature(), 'Una de hoy sí.');
    }

    public function test_sin_la_tabla_de_hechos_el_listado_del_crm_responde_sin_cursor(): void
    {
        Schema::drop('class_events');

        $respuesta = $this->adminGetJson('/api/classes')->assertOk();

        $this->assertArrayNotHasKey('events_cursor', $respuesta->json());
    }
}
