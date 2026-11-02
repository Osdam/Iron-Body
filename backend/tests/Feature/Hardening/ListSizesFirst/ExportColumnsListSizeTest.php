<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Services\Exports\ExportCatalog;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): la exportación
 * (`GET /api/admin/exports/{dataset}`) no tenía tope en `columns`, y `distinct`
 * compara cada columna con todas: 10 000 columnas (en un cuerpo JSON de la
 * petición GET) tardaban 24 s en llegar al 422. Tope nuevo: las columnas del
 * dataset. No cambia nada legítimo: con `distinct` y la lista blanca, una
 * lista válida nunca pasa de ahí, y una más larga ya era un 422.
 */
class ExportColumnsListSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_lista_por_encima_de_las_columnas_del_dataset_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $this->getJson('/api/admin/exports/members?format=csv', $h)->assertStatus(422);   // en frío, sin medir
        $columnas = count(app(ExportCatalog::class)->get('members')->columnKeys());

        [$r, $atributos] = $this->conAtributosValidados(fn () => $this->json('GET', '/api/admin/exports/members', ['format' => 'csv', 'columns' => array_fill(0, 10_000, 'name')], $h));

        $r->assertStatus(422)->assertJsonPath('code', 'invalid_export')->assertJsonPath('message', "Este conjunto tiene {$columnas} columnas.");
        $this->assertSame(['columns'], array_keys($r->json('errors')));
        $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
    }

    public function test_todas_las_columnas_del_dataset_caben_y_lo_demas_valida_como_siempre(): void
    {
        $h = $this->actingAsAdmin();
        $todas = app(ExportCatalog::class)->get('members')->columnKeys();

        // El máximo legítimo: todas las columnas, una vez cada una.
        $this->get('/api/admin/exports/members?'.http_build_query(['format' => 'csv', 'columns' => $todas]), $h)->assertOk();

        // Dentro del tope, los errores de siempre: un repetido y una que no existe.
        $r = $this->getJson('/api/admin/exports/members?'.http_build_query(['format' => 'csv', 'columns' => ['name', 'name', 'no-existe']]), $h)
            ->assertStatus(422)->assertJsonPath('code', 'invalid_export');
        $this->assertSame(['columns.0', 'columns.1', 'columns.2'], array_keys($r->json('errors')));
        // Y si `columns` no es una lista, el error de siempre.
        $this->getJson('/api/admin/exports/members?format=csv&columns=name', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_export');
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
