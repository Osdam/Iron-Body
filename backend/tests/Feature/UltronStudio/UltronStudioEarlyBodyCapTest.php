<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Middleware\LimitRequestBodySize;
use App\Http\Middleware\ValidatePostSizeWithStudioCap;
use App\Models\Admin;
use App\Models\UltronStudio\StudioDraft;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hito 7, B7-d: el tope de 64 KB del Studio actúa ANTES de `TrimStrings` y
 * `ConvertEmptyStringsToNull` (pendiente de la Enmienda 2 del contrato G).
 *
 * Hasta ahora el tope era solo de ruta (`LimitRequestBodySize:65536`), y los
 * dos globales de recorte recorrían antes el cuerpo entero: con 8 MB de textos
 * cortos, 1,4 millones de textos por cada uno y segundos de CPU antes del 413.
 * Los espías cuentan los textos que recorre cada uno.
 */
class UltronStudioEarlyBodyCapTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private const EL_413 = ['ok' => false, 'code' => 'payload_too_large', 'message' => 'La petición supera el tamaño máximo (64 KB).'];

    /** Un JSON de `PUT draft` de `$bytes` bytes exactos, con el relleno en textos `" x "` (lo que más le cuesta a TrimStrings). */
    private static function cuerpo(int $bytes, bool $textosCortos = true): string
    {
        $prefijo = '{"baseRevision":0,"content":{"relleno":';
        $sufijo = '}}';
        $hueco = $bytes - strlen($prefijo) - strlen($sufijo);
        if (! $textosCortos) {
            return $prefijo.'"'.str_repeat('x', $hueco - 2).'"'.$sufijo;
        }
        $n = intdiv($hueco - 1, 6);   // `" x "` y su coma: 6 bytes; `[` y `]`, 2.
        $lista = '['.implode(',', array_fill(0, $n, '" x "')).']';

        return $prefijo.$lista.str_repeat(' ', $hueco - strlen($lista)).$sufijo;
    }

    /**
     * Una petición con el cuerpo tal cual. `$longitud`: la cabecera
     * `Content-Length` que se declara (null: ninguna, como con
     * `Transfer-Encoding: chunked`).
     *
     * @param  array<string, string>  $cabeceras
     */
    private function enviar(string $metodo, string $ruta, string $cuerpo, ?int $longitud, array $cabeceras = [], string $tipo = 'application/json'): TestResponse
    {
        $server = $this->transformHeadersToServerVars($cabeceras + ['Accept' => 'application/json', 'Content-Type' => $tipo]);
        if ($longitud !== null) {
            $server['CONTENT_LENGTH'] = (string) $longitud;
        }

        return $this->call($metodo, $ruta, [], [], [], $server, $cuerpo);
    }

    /** @return array{0: TrimStrings&object{textos:int}, 1: ConvertEmptyStringsToNull&object{textos:int}} */
    private function espias(): array
    {
        $recorte = new class extends TrimStrings
        {
            public int $textos = 0;

            protected function transform($key, $value)
            {
                $this->textos++;

                return parent::transform($key, $value);
            }
        };
        $vacios = new class extends ConvertEmptyStringsToNull
        {
            public int $textos = 0;

            protected function transform($key, $value)
            {
                $this->textos++;

                return parent::transform($key, $value);
            }
        };
        $this->app->instance(TrimStrings::class, $recorte);
        $this->app->instance(ConvertEmptyStringsToNull::class, $vacios);

        return [$recorte, $vacios];
    }

    public function test_un_cuerpo_de_8_mb_es_413_sin_que_los_globales_de_recorte_lo_recorran(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $this->studio('GET', 'status', $ana)->assertOk();
        // Por debajo del `post_max_size` de producción (8M): llega a PHP y a Laravel.
        $cuerpo = self::cuerpo(8 * 1024 * 1024 - 1024);
        $this->assertSame(8 * 1024 * 1024 - 1024, strlen($cuerpo));
        $this->assertGreaterThan(1_300_000, substr_count($cuerpo, '" x "'));

        // Ahora: 413 sin que ninguno de los dos recorra un solo texto.
        [$recorte, $vacios] = $this->espias();
        $inicio = hrtime(true);
        $ahora = $this->enviar('PUT', '/api/admin/ultron-studio/draft', $cuerpo, strlen($cuerpo), $this->as($ana));
        $ms = (hrtime(true) - $inicio) / 1e6;
        $ahora->assertStatus(413)->assertExactJson(self::EL_413);
        $this->assertSame([0, 0], [$recorte->textos, $vacios->textos], 'Los globales de recorte recorrieron el cuerpo antes del tope.');
        // Holgado a propósito (medido: 43–46 ms; recorrerlo tardaba 2,7–3,3 s).
        $this->assertLessThan(1000, $ms, sprintf('El 413 tardó %.1f ms.', $ms));

        // Antes del Hito 7 (sin el tope global): el mismo 413, el de la ruta, pero
        // después de que cada uno de los dos recorriera los 1,4 millones de textos.
        [$recorte, $vacios] = $this->espias();
        $this->withoutMiddleware(ValidatePostSizeWithStudioCap::class);
        $antes = $this->enviar('PUT', '/api/admin/ultron-studio/draft', $cuerpo, strlen($cuerpo), $this->as($ana));
        $antes->assertStatus(413);
        $this->assertSame($antes->getContent(), $ahora->getContent(), 'El 413 del tope global no es, byte a byte, el de la ruta.');
        $this->assertGreaterThan(1_300_000, $recorte->textos);
        $this->assertGreaterThan(1_300_000, $vacios->textos);
        $this->assertSame(0, StudioDraft::query()->count());
    }

    public function test_sin_content_length_o_con_uno_que_miente_se_mide_el_cuerpo_que_llega(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        [$recorte] = $this->espias();
        $grande = self::cuerpo(65537, textosCortos: false);

        // JSON sin cabecera (chunked) y JSON que declara 10 bytes.
        $this->enviar('PUT', '/api/admin/ultron-studio/draft', $grande, null, $this->as($ana))->assertStatus(413)->assertExactJson(self::EL_413);
        $this->enviar('PUT', '/api/admin/ultron-studio/draft', $grande, 10, $this->as($ana))->assertStatus(413)->assertExactJson(self::EL_413);
        // Un cuerpo que no es JSON se lee del flujo, con tope.
        $this->enviar('POST', '/api/admin/ultron-studio/rules', str_repeat('a', 200_000), null, $this->as($ana), 'text/plain')
            ->assertStatus(413)->assertExactJson(self::EL_413);
        $this->assertSame(0, $recorte->textos);

        // Justo en el tope pasa los dos topes y llega a la validación del borrador.
        $justo = self::cuerpo(65536, textosCortos: false);
        $this->assertSame(65536, strlen($justo));
        $this->enviar('PUT', '/api/admin/ultron-studio/draft', $justo, null, $this->as($ana))->assertStatus(422);
        $this->enviar('PUT', '/api/admin/ultron-studio/draft', $justo, 65536, $this->as($ana))->assertStatus(422);
        $this->assertSame(0, StudioDraft::query()->count());
    }

    /**
     * El 413 sale detrás de HandleCors (el CRM es de otro origen y tiene que
     * poder leerlo) y con `no-store` (B7-a). El tamaño se mira antes que la
     * credencial: sin sesión, también 413 (antes era 401 después de recorrer
     * el cuerpo).
     */
    public function test_el_413_lleva_cors_y_no_store_tambien_sin_sesion(): void
    {
        $cuerpo = self::cuerpo(70_000);
        foreach ([[], $this->as($this->person(Admin::ROLE_ADMINISTRADOR, 'Ana'))] as $sesion) {
            $this->enviar('PUT', '/api/admin/ultron-studio/draft', $cuerpo, strlen($cuerpo), $sesion + ['Origin' => 'https://ironbodyneiva.cloud'])
                ->assertStatus(413)
                ->assertExactJson(self::EL_413)
                ->assertHeader('Access-Control-Allow-Origin', 'https://ironbodyneiva.cloud')
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        // Una ruta que no existe bajo el prefijo también: el tope es del prefijo.
        $this->enviar('POST', '/api/admin/ultron-studio/no-existe', $cuerpo, strlen($cuerpo))->assertStatus(413)->assertExactJson(self::EL_413);
    }

    public function test_fuera_del_studio_no_cambia_nada_y_post_max_size_sigue_igual(): void
    {
        $sesion = $this->as($this->person(Admin::ROLE_SUPER_ADMIN, 'Root'));
        $cuerpo = self::cuerpo(70_000);

        // Otra ruta administrativa con un cuerpo de 70 KB: su respuesta de siempre.
        $this->enviar('POST', '/api/admin/audit-logs', $cuerpo, strlen($cuerpo), $sesion)->assertStatus(422);
        // Un prefijo que se le parece no es el del Studio.
        $this->enviar('POST', '/api/admin/ultron-studiox/draft', $cuerpo, strlen($cuerpo), $sesion)->assertStatus(404);

        // `post_max_size` (ValidatePostSize, en el que delega): fuera del Studio,
        // su 413 de siempre; en el Studio manda el tope del Studio.
        $declarado = 9 * 1024 * 1024;
        $this->enviar('POST', '/api/admin/audit-logs', '{}', $declarado, $sesion)->assertStatus(413)->assertJsonPath('code', 'ocr_image_too_large');
        $this->enviar('PUT', '/api/admin/ultron-studio/draft', '{}', $declarado, $sesion)->assertStatus(413)->assertExactJson(self::EL_413);
    }

    /** El tope global y el de la ruta son el mismo, y el prefijo del global cubre las 56 rutas del Studio. */
    public function test_el_tope_global_es_el_de_las_56_rutas_del_studio(): void
    {
        $rutas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn (Route $r): bool => str_starts_with($r->uri(), 'api/admin/ultron-studio'));

        $this->assertCount(56, $rutas);
        foreach ($rutas as $ruta) {
            $this->assertTrue(Str::is(ValidatePostSizeWithStudioCap::STUDIO_PATHS, $ruta->uri()), "{$ruta->uri()} queda fuera del prefijo del tope global.");
            $this->assertContains(LimitRequestBodySize::class.':'.ValidatePostSizeWithStudioCap::STUDIO_MAX_BYTES, $ruta->gatherMiddleware(), "{$ruta->uri()} no tiene el tope de ruta del mismo tamaño.");
        }
    }
}
