<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Models\Trainer;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
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

        [$r, $atributos] = $this->conAtributosValidados(fn () => $this->putJson($ruta, ['rules' => array_fill(0, 10_000, ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00'])], $h));

        $r->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(['rules'], array_keys($r->json('errors')));
        $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->putJson($this->ruta(), ['rules' => [
            ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00'],
            ['weekday' => 9, 'start_time' => '10:00', 'end_time' => '09:00'],
        ]], $this->actingAsAdmin())->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertSame(['rules.1.weekday', 'rules.1.end_time'], array_keys($r->json('errors')));
    }

    /**
     * La respuesta y el mayor número de atributos que llegó a validar un
     * validador de la petición. Con el comodín expandido sobre la lista, uno
     * (o más) por elemento; validando primero el tamaño, uno por lista. No
     * depende de la carga de la máquina, a diferencia del tiempo.
     *
     * @return array{0: TestResponse, 1: int}
     */
    private function conAtributosValidados(Closure $peticion): array
    {
        $maximo = 0;
        Validator::resolver(static function ($traductor, array $datos, array $reglas, array $mensajes, array $atributos) use (&$maximo): LaravelValidator {
            $validador = new LaravelValidator($traductor, $datos, $reglas, $mensajes, $atributos);
            $maximo = max($maximo, count($validador->getRules()));

            return $validador;
        });

        return [$peticion(), $maximo];
    }
}
