<?php

namespace Tests\Feature\Valuations;

use App\Exceptions\ValuationException;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityBlock;
use App\Services\Valuations\ValuationAgenda;
use App\Services\Valuations\ValuationImporter;
use App\Support\Access\CrmPermission;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La FORMA de la API que consume el CRM (CONTRATO_AGENDA_VALORACIONES.md §4),
 * clave a clave, y el orden de los cerrojos de la creación.
 *
 * Con VALUATIONS_EXAMPLES_OUT=<ruta> vuelca las respuestas reales a ese
 * fichero (son los ejemplos del informe).
 */
class ValuationContractTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_formas_del_contrato(): void
    {
        $this->clock('2026-10-19 08:00');
        $andres = $this->trainer('Andrés Pérez');
        $this->rule($andres, 6, '07:00', '12:00');
        $this->rule($andres, 6, '14:00', '18:00');
        $laura = $this->member('Laura Gómez');
        $this->assign($laura, $andres);
        $this->klass($andres, ['name' => 'Spinning', 'day_of_week' => 'Sábado', 'start_time' => '15:00', 'end_time' => '16:00']);
        ValuationAvailabilityBlock::create([
            'trainer_id' => $andres->id, 'kind' => 'break', 'reason' => 'Almuerzo largo',
            'starts_at' => Carbon::parse('2026-10-24 11:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-24 11:30', 'America/Bogota')->utc(),
        ]);
        $rec = $this->reception();
        $ej = [];

        $ej['capabilities'] = $this->getJson('/api/admin/valuations/capabilities', $rec)->assertOk()->json();
        $this->assertSame(['can', 'own_trainer_id', 'settings', 'types', 'statuses', 'channels'], array_keys($ej['capabilities']['data']));
        $this->assertSame(['view', 'view_all', 'create', 'update', 'cancel', 'complete', 'manage_availability', 'manage_settings'], array_keys($ej['capabilities']['data']['can']));
        $this->assertSame(['default_duration_minutes', 'slot_interval_minutes', 'max_horizon_days', 'min_notice_minutes'], array_keys($ej['capabilities']['data']['settings']));
        $this->assertSame(['available' => false, 'reason' => 'Sin plantilla aprobada en Meta'], $ej['capabilities']['data']['channels']['whatsapp']);

        $crear = $this->payload($laura, $andres, '2026-10-24T09:00:00-05:00', ['notes' => 'Primera valoración']);
        $ej['create_request'] = $crear;
        $ej['create_201'] = $this->postJson('/api/admin/valuations', $crear, $rec)->assertStatus(201)->json();
        $ej['create_200_repeat'] = $this->postJson('/api/admin/valuations', $crear, $rec)->assertStatus(200)->json();
        $this->assertSame(
            ['uuid', 'type', 'type_label', 'status', 'status_label', 'starts_at', 'ends_at', 'duration_minutes', 'local_date', 'local_start', 'local_end', 'member', 'trainer', 'notes', 'cancellation_reason', 'version', 'source', 'created_by_name', 'created_at', 'allowed_actions'],
            array_keys($ej['create_201']['data']),
        );
        $this->assertSame(['id', 'name', 'is_assigned_to_member'], array_keys($ej['create_201']['data']['trainer']));
        $this->assertTrue($ej['create_201']['data']['trainer']['is_assigned_to_member']);

        $ej['list'] = $this->getJson('/api/admin/valuations?from=2026-10-19T00:00:00-05:00&to=2026-11-01T00:00:00-05:00', $rec)->assertOk()->json();
        $this->assertSame(['ok', 'data', 'meta'], array_keys($ej['list']));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($ej['list']['meta']));

        $ej['slots'] = $this->getJson("/api/admin/valuations/slots?trainer_id={$andres->id}&date=2026-10-24&duration_minutes=60", $rec)->assertOk()->json();
        $this->assertSame(['date', 'timezone', 'windows', 'slots', 'busy'], array_keys($ej['slots']['data']));
        $this->assertSame(['local_start', 'local_end', 'reason', 'label'], array_keys($ej['slots']['data']['busy'][0]));
        $this->assertEqualsCanonicalizing(['appointment', 'block', 'class'], array_values(array_unique(array_column($ej['slots']['data']['busy'], 'reason'))));

        $ej['conflict_409'] = $this->book($rec, $this->member('Pedro Ruiz'), $andres, '2026-10-24T09:30:00-05:00')->assertStatus(409)->json();
        $this->assertSame(['ok', 'code', 'message'], array_keys($ej['conflict_409']));
        $ej['class_conflict_409'] = $this->book($rec, $this->member('Ana'), $andres, '2026-10-24T15:00:00-05:00')->assertStatus(409)->json();
        $ej['validation_422'] = $this->postJson('/api/admin/valuations', ['member_id' => $laura->id], $rec)->assertStatus(422)->json();
        $this->assertSame(['ok', 'code', 'message', 'errors'], array_keys($ej['validation_422']));

        $uuid = $ej['create_201']['data']['uuid'];
        $this->postJson("/api/admin/valuations/{$uuid}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T10:00:00-05:00'], $rec)->assertOk();
        $ej['changed_409'] = $this->postJson("/api/admin/valuations/{$uuid}/cancel", ['expected_version' => 1, 'reason' => 'Prueba de versión'], $rec)->assertStatus(409)->json();
        $this->assertSame(['ok', 'code', 'message', 'data'], array_keys($ej['changed_409']));
        $ej['detail'] = $this->getJson("/api/admin/valuations/{$uuid}", $rec)->assertOk()->json();
        $ej['trainers'] = $this->getJson('/api/admin/valuations/trainers', $rec)->assertOk()->json();
        $ej['members_search'] = $this->getJson('/api/admin/valuations/members/search?q=laura', $rec)->assertOk()->json();
        $ej['availability'] = $this->getJson("/api/admin/valuations/trainers/{$andres->id}/availability", $this->h($this->superAdmin()))->assertOk()->json();
        $ej['settings'] = $this->getJson('/api/admin/valuations/settings', $rec)->assertOk()->json();
        $ej['forbidden_403'] = $this->getJson('/api/admin/valuations?from=2026-10-19T00:00:00-05:00&to=2026-10-20T00:00:00-05:00', $this->h($this->staff(CrmPermission::ROLE_ENTRENADOR)))->assertForbidden()->json();

        if ($salida = getenv('VALUATIONS_EXAMPLES_OUT')) {
            file_put_contents($salida, json_encode($ej, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * El SQL que se emite, en orden. `$accion` corre con el registro de consultas
     * encendido.
     *
     * @return list<string>
     */
    private function sqlDe(callable $accion): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $accion();
        $sql = array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return $sql;
    }

    private static function posicion(array $sql, string $patron): int
    {
        foreach ($sql as $i => $q) {
            if (preg_match($patron, $q)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * El cerrojo de una fila de `trainers`/`members`: en PostgreSQL `FOR NO KEY
     * UPDATE` (revisión de BD I1: con `FOR UPDATE` choca con la comprobación de
     * FK de cualquier INSERT que apunte a esa fila); SQLite no tiene cerrojos
     * de fila y no emite nada.
     */
    private function cerrojo(string $tabla): string
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        return '/^select "id" from "'.$tabla.'" where "id" = \? limit 1'.($pg ? ' for no key update' : '').'$/';
    }

    public function test_crear_bloquea_entrenador_despues_socio_y_comprueba_dentro(): void
    {
        $this->clock('2026-10-19 08:00');
        $andres = $this->trainer();
        $this->everyDay($andres);
        $laura = $this->member();
        $rec = $this->reception();

        $sql = $this->sqlDe(fn () => $this->book($rec, $laura, $andres, '2026-10-24T09:00:00-05:00')->assertCreated());

        $entrenador = self::posicion($sql, $this->cerrojo('trainers'));
        $socio = self::posicion($sql, $this->cerrojo('members'));
        $solape = self::posicion($sql, '/from "valuation_appointments" where "trainer_id" = \? and "status" in/');
        $insert = self::posicion($sql, '/^insert into "valuation_appointments"/');

        $this->assertGreaterThanOrEqual(0, $entrenador, 'se bloquea la fila del entrenador');
        $this->assertGreaterThan($entrenador, $socio, 'y DESPUÉS la del socio');
        $this->assertGreaterThan($socio, $solape, 'el solape se comprueba con las dos filas bloqueadas');
        $this->assertGreaterThan($solape, $insert);
        $this->assertSame([], preg_grep('/from "(trainers|members)" .* for update$/', $sql), 'ningún FOR UPDATE sobre entrenador ni socio');
    }

    public function test_reprogramar_e_importar_toman_el_mismo_cerrojo(): void
    {
        $this->clock('2026-10-19 08:00');
        $andres = $this->trainer('Andrés Pérez', ['document' => '80012345']);
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($andres);
        $this->everyDay($sofia);
        $laura = $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $rec = $this->reception();
        $u = $this->book($rec, $laura, $andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');

        // Reprogramar a otro entrenador: los dos entrenadores (por id) y después el socio.
        $sql = $this->sqlDe(fn () => $this->postJson("/api/admin/valuations/{$u}/reschedule", [
            'expected_version' => 1, 'starts_at' => '2026-10-24T10:00:00-05:00', 'trainer_id' => $sofia->id,
        ], $rec)->assertOk());
        $entrenadores = array_keys(preg_grep($this->cerrojo('trainers'), $sql));
        $socio = self::posicion($sql, $this->cerrojo('members'));
        $this->assertCount(2, $entrenadores, 'origen y destino');
        $this->assertGreaterThan(max($entrenadores), $socio);
        $this->assertSame([], preg_grep('/from "(trainers|members)" .* for update$/', $sql));

        // Importar con --apply: el mismo cerrojo, entrenadores y después socios.
        $ruta = tempnam(sys_get_temp_dir(), 'val-csv-');
        file_put_contents($ruta, "member_document,trainer_document,date,time,duration_minutes,type,notes\n1075123456,80012345,2026-10-27,09:00,60,initial,\n");
        try {
            $sql = $this->sqlDe(fn () => $this->assertSame(1, app(ValuationImporter::class)->run($ruta, true)['created']));
        } finally {
            @unlink($ruta);
        }
        $entrenador = self::posicion($sql, $this->cerrojo('trainers'));
        $socio = self::posicion($sql, $this->cerrojo('members'));
        $insert = self::posicion($sql, '/^insert into "valuation_appointments"/');
        $this->assertGreaterThanOrEqual(0, $entrenador);
        $this->assertGreaterThan($entrenador, $socio);
        $this->assertGreaterThan($socio, $insert);
        $this->assertSame([], preg_grep('/from "(trainers|members)" .* for update$/', $sql));
    }

    /**
     * La segunda puerta. En PostgreSQL, la restricción EXCLUDE rechaza dos citas
     * activas solapadas del mismo entrenador aunque alguien se salte el servicio,
     * y el servicio traduce ese 23P01 a 409 `slot_taken`. En SQLite no existe
     * (la migración la omite) y la única puerta es el bloqueo del servicio.
     */
    public function test_segunda_puerta_exclude_en_postgresql(): void
    {
        $this->clock('2026-10-19 08:00');
        $t = $this->trainer();
        $base = [
            'trainer_id' => $t->id, 'type' => 'initial', 'duration_minutes' => 60, 'source' => 'crm',
            'starts_at' => Carbon::parse('2026-10-24 09:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-24 10:00', 'America/Bogota')->utc(),
        ];
        ValuationAppointment::create($base + ['member_id' => $this->member('A')->id, 'status' => 'scheduled']);
        $pg = DB::connection()->getDriverName() === 'pgsql';

        $traducir = new \ReflectionMethod(ValuationAgenda::class, 'traducir');
        $agenda = app(ValuationAgenda::class);

        try {
            DB::transaction(fn () => ValuationAppointment::create(array_merge($base, [
                'member_id' => $this->member('B')->id, 'status' => 'confirmed',
                'starts_at' => Carbon::parse('2026-10-24 09:30', 'America/Bogota')->utc(),
                'ends_at' => Carbon::parse('2026-10-24 10:30', 'America/Bogota')->utc(),
            ])));
            $this->assertFalse($pg, 'en PostgreSQL la restricción tenía que rechazarla');
            $this->assertSame(2, ValuationAppointment::count(), 'en SQLite solo protege el servicio');
        } catch (QueryException $e) {
            $this->assertTrue($pg);
            $this->assertSame('23P01', $e->errorInfo[0]);
            $traducida = $traducir->invoke($agenda, $e);
            $this->assertInstanceOf(ValuationException::class, $traducida);
            $this->assertSame('slot_taken', $traducida->errorCode);
        }

        // Una cancelada no ocupa: la restricción solo mira las activas.
        ValuationAppointment::create($base + ['member_id' => $this->member('C')->id, 'status' => 'cancelled']);
        // Y los bordes que se tocan, tampoco.
        ValuationAppointment::create(array_merge($base, [
            'member_id' => $this->member('D')->id, 'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-10-24 10:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-24 11:00', 'America/Bogota')->utc(),
        ]));
        $this->assertGreaterThanOrEqual(3, ValuationAppointment::count());
    }
}
