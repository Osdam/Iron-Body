<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Services\Valuations\ValuationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTRATO_CICLO_VALORACIONES.md §4: `POST /api/admin/valuations` con
 * `as_next_follow_up: true` no crea una segunda cita futura: 409
 * `member_has_future_appointment` con la existente. Sin el campo, todo igual.
 * La carrera de dos peticiones a la vez, en PostgreSQL con dos procesos, está
 * en tests/Concurrency/ValoracionCita.
 */
class ProximaCitaTest extends TestCase
{
    use CicloFixtures;
    use RefreshDatabase;

    private array $rec;

    private Trainer $andres;

    private Trainer $ana;

    private Member $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->everyDay($this->andres);
        $this->ana = $this->trainer('Ana Rojas');
        $this->everyDay($this->ana);
        $this->laura = $this->member('Laura Gómez');
        $this->rec = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function citasDe(Member $m): int
    {
        return ValuationAppointment::where('member_id', $m->id)->count();
    }

    public function test_con_una_cita_futura_responde_409_con_la_existente(): void
    {
        $existente = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');

        $this->book($this->rec, $this->laura, $this->ana, '2026-11-20T10:00:00-05:00', ['as_next_follow_up' => true, 'type' => 'follow_up'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'member_has_future_appointment')
            ->assertJsonPath('existing', [
                'uuid' => $existente,
                'starts_at' => '2026-10-24T14:00:00+00:00',
                'trainer' => ['id' => $this->andres->id, 'name' => 'Andrés Pérez'],
                'status' => 'scheduled',
            ]);
        $this->assertSame(1, $this->citasDe($this->laura));
    }

    public function test_sin_el_campo_se_comporta_como_siempre(): void
    {
        $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->book($this->rec, $this->laura, $this->ana, '2026-11-20T10:00:00-05:00')->assertCreated();
        $this->book($this->rec, $this->laura, $this->ana, '2026-11-21T10:00:00-05:00', ['as_next_follow_up' => false])->assertCreated();
        $this->assertSame(3, $this->citasDe($this->laura));
    }

    public function test_sin_cita_futura_crea_y_la_pasada_abierta_o_la_cancelada_no_bloquean(): void
    {
        // Una de hoy que ya pasó (sigue «programada») y una futura cancelada.
        $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-19T09:00:00-05:00');
        $cancelada = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-25T09:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$cancelada}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $this->rec)->assertOk();
        $this->clock('2026-10-19 11:00');

        $this->book($this->rec, $this->laura, $this->ana, '2026-11-20T10:00:00-05:00', ['as_next_follow_up' => true, 'type' => 'follow_up'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'follow_up');
    }

    public function test_la_repeticion_del_mismo_client_request_id_devuelve_la_misma_cita(): void
    {
        $cuerpo = $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['as_next_follow_up' => true]);
        $uuid = $this->postJson('/api/admin/valuations', $cuerpo, $this->rec)->assertCreated()->json('data.uuid');

        // El doble clic: la idempotencia sigue siendo `client_request_id`, no un 409.
        $this->postJson('/api/admin/valuations', $cuerpo, $this->rec)->assertOk()->assertJsonPath('data.uuid', $uuid);
        $this->assertSame(1, $this->citasDe($this->laura));
    }

    public function test_la_cita_fuera_del_alcance_no_se_describe(): void
    {
        $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $deAna = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::CREATE], $this->ana));

        $this->book($deAna, $this->laura, $this->ana, '2026-11-20T10:00:00-05:00', ['as_next_follow_up' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'member_has_future_appointment')
            ->assertJsonPath('existing', null);
    }

    public function test_el_campo_se_valida(): void
    {
        $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['as_next_follow_up' => 'quizá'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['as_next_follow_up']]);
    }

    /**
     * Las dos comprobaciones van bajo el cerrojo del socio que ya toma crear:
     * se lee el SQL emitido (en SQLite `lockForUpdate` no hace nada y la
     * carrera no se ve; la de verdad, con dos procesos, es la de PostgreSQL).
     */
    public function test_la_busqueda_de_la_cita_futura_va_despues_del_cerrojo_del_socio(): void
    {
        $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        DB::enableQueryLog();
        $this->book($this->rec, $this->laura, $this->ana, '2026-11-20T10:00:00-05:00', ['as_next_follow_up' => true, 'client_request_id' => (string) Str::uuid()])->assertStatus(409);
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // El cerrojo del socio es `select "id" from "members" where "id" = ? limit 1`,
        // con `for no key update` en PostgreSQL (SQLite no emite cláusula de cerrojo).
        $cerrojo = array_key_first(array_filter($sql, fn ($q) => str_starts_with($q, 'select "id" from "members" where "id" = ? limit 1')));
        $futura = array_key_first(array_filter($sql, fn ($q) => str_contains($q, 'from "valuation_appointments"') && str_contains($q, '"starts_at" >')));
        $this->assertNotNull($cerrojo, 'se bloquea al socio');
        $this->assertNotNull($futura, 'se busca la cita futura');
        $this->assertGreaterThan($cerrojo, $futura);
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertStringEndsWith('for no key update', $sql[$cerrojo]);
        }
    }
}
