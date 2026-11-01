<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): la disponibilidad de
 * valoraciones (`PUT /api/admin/valuations/trainers/{id}/availability`) valida
 * primero el tamaño de `rules` (tope 100, el de siempre) y después cada regla.
 * Antes, 10 000 reglas tardaban 100 s en llegar al mismo 422 (en producción,
 * hasta el corte de 30 s de PHP-FPM). El 422 conserva su forma
 * (`validation_failed`).
 */
class ValuationAvailabilityListSizeTest extends TestCase
{
    use RefreshDatabase;

    private function ruta(): string
    {
        $entrenador = Trainer::create(['full_name' => 'Entrenador', 'document' => '100200300', 'status' => 'active']);

        return "/api/admin/valuations/trainers/{$entrenador->id}/availability";
    }

    public function test_una_lista_por_encima_del_tope_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $ruta = $this->ruta();
        $this->putJson($ruta, [], $h)->assertStatus(422);   // en frío, sin medir

        $inicio = hrtime(true);
        $r = $this->putJson($ruta, ['rules' => array_fill(0, 10_000, ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00'])], $h);
        $ms = (hrtime(true) - $inicio) / 1e6;

        $r->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(['rules'], array_keys($r->json('errors')));
        $this->assertLessThan(1000, $ms, sprintf('El 422 tardó %.1f ms (antes, 100 s).', $ms));
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->putJson($this->ruta(), ['rules' => [
            ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00'],
            ['weekday' => 9, 'start_time' => '10:00', 'end_time' => '09:00'],
        ]], $this->actingAsAdmin())->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertSame(['rules.1.weekday', 'rules.1.end_time'], array_keys($r->json('errors')));
    }
}
