<?php

namespace Tests\Feature\Classes;

use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El estado de una clase (activa, pausada, finalizada) visto desde el CRM.
 *
 * El recurso publica en `status` la DISPONIBILIDAD para la app (available,
 * full, unavailable…) y el CRM lo leía como estado: sus KPIs «Activas» y «Hoy»
 * daban 0, el filtro no encontraba nada, el interruptor no podía pausar una
 * clase y, peor, editar una clase pausada mandaba `status: 'unavailable'`, que
 * el backend traducía a 'active': cambiarle el nombre la reactivaba.
 */
class ClassStatusFromCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clase(string $estado): MyClass
    {
        return MyClass::create([
            'name' => "Clase {$estado}", 'type' => 'Funcional', 'day_of_week' => 'Lunes',
            'start_time' => '07:00', 'end_time' => '08:00', 'status' => $estado,
            'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    public function test_el_crm_recibe_el_estado_de_la_clase_aparte_de_la_disponibilidad(): void
    {
        $activa = $this->clase('active');
        $pausada = $this->clase('inactive');

        $filas = collect($this->adminGetJson('/api/classes')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('active', $filas[(string) $activa->id]['class_status']);
        $this->assertSame('available', $filas[(string) $activa->id]['status'], 'La app sigue leyendo la disponibilidad.');
        $this->assertSame('inactive', $filas[(string) $pausada->id]['class_status']);
        $this->assertSame('unavailable', $filas[(string) $pausada->id]['status']);
    }

    public function test_editar_una_clase_pausada_desde_una_pestana_vieja_no_la_reactiva(): void
    {
        $pausada = $this->clase('inactive');

        // Lo que manda el formulario del CRM anterior: la disponibilidad como estado.
        $this->adminPatchJson("/api/classes/{$pausada->id}", ['name' => 'Renombrada', 'status' => 'unavailable'])
            ->assertOk()
            ->assertJsonPath('data.class_status', 'inactive');

        $this->assertSame('inactive', $pausada->fresh()->status);
        $this->assertSame('Renombrada', $pausada->fresh()->name);
    }

    public function test_una_disponibilidad_sobre_una_activa_tampoco_cambia_nada(): void
    {
        $activa = $this->clase('active');

        foreach (['available', 'full', 'few_spots', 'reserved', ''] as $valor) {
            $this->adminPatchJson("/api/classes/{$activa->id}", ['status' => $valor])->assertOk();
            $this->assertSame('active', $activa->fresh()->status, "status='{$valor}'");
        }
    }

    public function test_el_interruptor_pausa_y_reactiva(): void
    {
        $clase = $this->clase('active');

        $this->adminPatchJson("/api/classes/{$clase->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.class_status', 'inactive');
        $this->adminPatchJson("/api/classes/{$clase->id}", ['status' => 'Activa'])->assertOk()->assertJsonPath('data.class_status', 'active');
    }

    public function test_un_alta_sin_estado_reconocible_nace_activa(): void
    {
        $this->adminPostJson('/api/classes', [
            'name' => 'Nueva', 'type' => 'Funcional', 'day_of_week' => 'Martes', 'start_time' => '09:00',
            'end_time' => '10:00', 'max_capacity' => 15, 'status' => 'available', 'is_recurring' => true,
        ])->assertCreated()->assertJsonPath('data.class_status', 'active');
    }
}
