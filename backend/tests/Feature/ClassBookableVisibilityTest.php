<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una clase que el backend no aceptaría reservar no puede anunciarse como
 * disponible.
 *
 * Caso real: duplicar una clase desde el CRM crea "Copia de …" con
 * `status = inactive` (borrador) conservando `allow_online_booking`. Es un
 * borrador legítimo, pero el listado que consume la app —`GET /classes`, el de
 * ClassController— no filtraba por estado, así que la clase salía como
 * "Disponible" con botón "Reservar clase" y al pulsar devolvía
 * `ApiException(422): Clase no disponible para reservas.`
 */
class ClassBookableVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio Clases',
            'document_number' => '770770770',
            'phone' => '+573007707707',
            'access_hash' => 'tok-770',
            'status' => Member::STATUS_ACTIVE,
        ]));
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function makeClass(array $attrs = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'IRON POWERFLOW',
            'type' => 'Funcional',
            'day_of_week' => 'Lunes',
            'start_time' => '06:00',
            'end_time' => '07:00',
            'status' => 'active',
            'max_capacity' => 20,
            'allow_online_booking' => true,
        ], $attrs));
    }

    public function test_una_copia_en_borrador_no_aparece_en_el_listado_de_la_app(): void
    {
        $this->makeClass();
        $this->makeClass(['name' => 'Copia de IRON POWERFLOW', 'status' => 'inactive']);

        $data = $this->getJson('/api/classes', $this->auth())->assertOk()->json('data');

        $names = collect($data)->pluck('name')->all();
        $this->assertContains('IRON POWERFLOW', $names);
        $this->assertNotContains('Copia de IRON POWERFLOW', $names);
    }

    public function test_una_clase_con_reserva_online_apagada_tampoco_aparece(): void
    {
        $this->makeClass(['name' => 'Solo presencial', 'allow_online_booking' => false]);

        $data = $this->getJson('/api/classes', $this->auth())->assertOk()->json('data');

        $this->assertNotContains('Solo presencial', collect($data)->pluck('name')->all());
    }

    public function test_el_crm_sigue_viendo_los_borradores(): void
    {
        $this->makeClass();
        $this->makeClass(['name' => 'Copia de IRON POWERFLOW', 'status' => 'inactive']);

        // Sin contexto de miembro (sesión admin del CRM) el catálogo completo
        // sigue disponible: gestionar un borrador exige poder verlo.
        $data = $this->getJson('/api/classes')->assertOk()->json('data');

        $this->assertContains('Copia de IRON POWERFLOW', collect($data)->pluck('name')->all());
    }

    public function test_el_recurso_nunca_anuncia_reservable_lo_que_no_lo_es(): void
    {
        $draft = $this->makeClass(['name' => 'Borrador', 'status' => 'inactive']);

        // Aunque se consulte el detalle directamente, la card no puede ofrecer
        // el botón de reservar.
        $card = $this->getJson("/api/classes/{$draft->id}", $this->auth())->assertOk()->json('data');

        $this->assertFalse($card['can_reserve']);
        $this->assertSame('unavailable', $card['status']);
    }

    public function test_reservar_un_borrador_sigue_rechazandose(): void
    {
        $draft = $this->makeClass(['name' => 'Borrador', 'status' => 'inactive']);

        $this->postJson("/api/classes/{$draft->id}/reserve", [], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clase no disponible para reservas.');
    }

    public function test_una_copia_activada_si_se_puede_reservar(): void
    {
        // El flujo válido: el admin revisa el borrador y lo activa.
        $copy = $this->makeClass(['name' => 'Copia de IRON POWERFLOW', 'status' => 'inactive']);
        $copy->update(['status' => 'active']);

        $names = collect($this->getJson('/api/classes', $this->auth())->assertOk()->json('data'))
            ->pluck('name')->all();
        $this->assertContains('Copia de IRON POWERFLOW', $names);

        $this->postJson("/api/classes/{$copy->id}/reserve", [], $this->auth())->assertOk();
    }
}
