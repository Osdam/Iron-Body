<?php

namespace Tests\Feature\Classes;

use App\Models\AuditLog;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El catálogo de clases y su aforo.
 *
 *   · La lectura del catálogo es pública: sin sesión de administrador no
 *     trae borradores ni las notas internas.
 *   · El orden es estable (el CRM pide las páginas en paralelo) y la app, que
 *     solo lee la primera página, recibe todas las clases.
 *   · `date_time` es la próxima sesión, también en una recurrente con fecha de
 *     inicio de vigencia.
 *   · Bajar el aforo por debajo de lo ya inscrito se rechaza.
 */
class ClassCatalogAndCapacityTest extends TestCase
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

    private function clase(array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '06:00',
            'end_time' => '07:00', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true, 'notes' => 'Llave del salón en recepción',
        ], $extra));
    }

    private function reservas(MyClass $clase, int $n, ?string $fecha): void
    {
        static $doc = 0;
        for ($i = 0; $i < $n; $i++) {
            $doc++;
            $m = Member::create(['full_name' => "Socio {$doc}", 'document_number' => '7300'.$doc, 'status' => Member::STATUS_ACTIVE]);
            ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => $fecha]);
        }
    }

    public function test_sin_sesion_no_salen_borradores_ni_notas_internas(): void
    {
        $publica = $this->clase();
        $this->clase(['name' => 'Borrador', 'status' => 'inactive']);

        $anonimo = collect($this->getJson('/api/classes')->assertOk()->json('data'));
        $this->assertSame([(string) $publica->id], $anonimo->pluck('id')->all());
        $this->assertArrayNotHasKey('notes', $anonimo->first());
        $this->assertArrayNotHasKey('notes', $this->getJson("/api/classes/{$publica->id}")->assertOk()->json('data'));

        $crm = collect($this->adminGetJson('/api/classes')->assertOk()->json('data'));
        $this->assertCount(2, $crm);
        $this->assertSame('Llave del salón en recepción', $crm->firstWhere('id', (string) $publica->id)['notes']);
        $this->assertSame('Llave del salón en recepción', $this->adminGetJson("/api/classes/{$publica->id}")->assertOk()->json('data.notes'));
    }

    public function test_orden_estable_y_la_app_recibe_mas_de_veinte(): void
    {
        foreach (range(1, 23) as $i) {
            $this->clase(['name' => "Clase {$i}"]);
        }

        $app = $this->getJson('/api/classes')->assertOk();
        $this->assertCount(23, $app->json('data'), 'La app solo lee la primera página.');

        $ids = [];
        foreach ([1, 2] as $pagina) {
            $ids = array_merge($ids, array_column($this->adminGetJson("/api/classes?page={$pagina}&per_page=12")->assertOk()->json('data'), 'id'));
        }
        $this->assertSame(MyClass::orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all(), $ids, 'Cada clase una vez, en orden.');
    }

    public function test_una_recurrente_con_vigencia_publica_su_proxima_sesion(): void
    {
        $vigente = $this->clase(['date_time' => '2026-01-10 09:00:00']);
        $futura = $this->clase(['name' => 'Desde noviembre', 'date_time' => '2026-11-02 00:00:00']);

        $datos = collect($this->getJson('/api/classes')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('2026-10-05T06:00:00', $datos[(string) $vigente->id]['date_time'], 'No la fecha desde la que rige.');
        $this->assertSame('2026-11-02T06:00:00', $datos[(string) $futura->id]['date_time']);
    }

    public function test_manda_el_entrenador_asignado_y_no_el_texto_viejo(): void
    {
        $antes = Trainer::create(['full_name' => 'Coach Anterior', 'document' => '8181001', 'status' => 'active']);
        $ahora = Trainer::create(['full_name' => 'Coach Nuevo', 'document' => '8181002', 'status' => 'active']);
        $clase = $this->clase(['trainer_id' => $antes->id, 'instructor' => 'Coach Anterior']);

        $this->adminPatchJson("/api/classes/{$clase->id}", ['trainer_id' => $ahora->id])->assertOk();

        $this->assertSame('Coach Nuevo', collect($this->getJson('/api/classes')->json('data'))->firstWhere('id', (string) $clase->id)['instructor']);
        $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socia Plan', 'document_number' => '8181003', 'phone' => '+573008181003',
            'access_hash' => 'tok-8181003', 'status' => Member::STATUS_ACTIVE,
        ]));
        $semana = $this->getJson('/api/app/classes/weekly?week_start=2026-10-05', ['Authorization' => 'Bearer tok-8181003'])->assertOk();
        $entrada = collect($semana->json('days'))->flatMap(fn ($d) => $d['classes'])->firstWhere('class_id', $clase->id);
        $this->assertSame('Coach Nuevo', $entrada['instructor'], '«Organizar mi semana» también.');
    }

    public function test_no_se_baja_el_aforo_por_debajo_de_lo_inscrito(): void
    {
        $clase = $this->clase(['max_capacity' => 20]);
        $this->reservas($clase, 6, '2026-10-05');
        $this->reservas($clase, 3, '2026-10-12');
        $this->reservas($clase, 2, null); // heredadas: ocupan todas las sesiones
        $this->reservas($clase, 12, '2026-09-28'); // pasadas: no cuentan

        $this->adminPatchJson("/api/classes/{$clase->id}", ['max_capacity' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_capacity')
            ->assertJsonPath('message', 'La sesión del 2026-10-05 ya tiene 8 inscritos: el aforo no puede quedar por debajo. Quita inscritos antes o elige un aforo de 8 o más.');
        $this->assertSame(20, (int) $clase->fresh()->max_capacity);

        $this->adminPatchJson("/api/classes/{$clase->id}", ['max_capacity' => 8])->assertOk();
        $this->adminPatchJson("/api/classes/{$clase->id}", ['max_capacity' => 30])->assertOk();
        $this->assertSame(30, (int) $clase->fresh()->max_capacity);
    }

    public function test_la_auditoria_guarda_de_cuanto_a_cuanto_cambio_el_aforo_y_el_horario(): void
    {
        $clase = $this->clase();

        $this->adminPatchJson("/api/classes/{$clase->id}", ['max_capacity' => 25, 'start_time' => '06:30'])->assertOk();

        $cambios = collect(AuditLog::query()->where('entity_id', $clase->id)->latest('id')->value('changes'))->keyBy('field');
        $this->assertSame(['field' => 'max_capacity', 'from' => '20', 'to' => '25'], $cambios['max_capacity']);
        $this->assertSame('06:30', $cambios['start_time']['to']);
    }

    public function test_la_supervision_rechaza_fechas_mal_formadas(): void
    {
        $this->adminGetJson('/api/admin/class-sessions?from=ayer&to=2026-09-30')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->adminGetJson('/api/admin/class-sessions?from=2026-09-23&to=2026-09-30')->assertOk();
    }
}
