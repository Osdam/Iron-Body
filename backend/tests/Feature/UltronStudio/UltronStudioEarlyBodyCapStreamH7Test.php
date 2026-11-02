<?php

namespace Tests\Feature\UltronStudio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TEST7 (Hito 7): el tope temprano de 64 KB del Studio
 * (`ValidatePostSizeWithStudioCap`) para un cuerpo que NO es JSON y llega sin
 * `Content-Length` (como con `Transfer-Encoding: chunked`): se lee del flujo,
 * como mucho 64 KB + 1 bytes, y se mide.
 *
 * La prueba de B7 lo envía a una ruta que existe, donde el tope de la ruta
 * (`LimitRequestBodySize`) da el MISMO 413 byte a byte: si el tope temprano
 * dejara de medir el flujo, nadie lo notaría (la mutación VPS-05 de TEST7
 * sobrevivía). Bajo el prefijo pero en una ruta que NO existe no hay tope de
 * ruta: el 413 solo puede venir del tope temprano, y el límite exacto también.
 */
class UltronStudioEarlyBodyCapStreamH7Test extends TestCase
{
    use RefreshDatabase;

    private const EL_413 = ['ok' => false, 'code' => 'payload_too_large', 'message' => 'La petición supera el tamaño máximo (64 KB).'];

    /** Un POST con el cuerpo tal cual, sin `Content-Length` y sin sesión. */
    private function enviarSinLongitud(string $ruta, string $cuerpo, string $tipo): TestResponse
    {
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => $tipo]);
        unset($server['CONTENT_LENGTH']);

        return $this->call('POST', $ruta, [], [], [], $server, $cuerpo);
    }

    public function test_un_cuerpo_que_no_es_json_y_sin_longitud_se_mide_en_el_flujo_aunque_la_ruta_no_exista(): void
    {
        $ruta = '/api/admin/ultron-studio/no-existe-h7';

        // Un byte por encima del tope: el 413 del tope temprano (no hay ruta, así que no hay otro tope).
        $this->enviarSinLongitud($ruta, str_repeat('a', 65537), 'text/plain')
            ->assertStatus(413)->assertExactJson(self::EL_413)->assertHeader('Cache-Control', 'no-store, private');
        // Muy por encima, igual (solo se leen 64 KB + 1 del flujo).
        $this->enviarSinLongitud($ruta, str_repeat('a', 300_000), 'application/octet-stream')
            ->assertStatus(413)->assertExactJson(self::EL_413);

        // Justo en el tope pasa el tope temprano y llega al enrutado: la ruta no existe.
        $this->enviarSinLongitud($ruta, str_repeat('a', 65536), 'text/plain')->assertStatus(404);
    }
}
