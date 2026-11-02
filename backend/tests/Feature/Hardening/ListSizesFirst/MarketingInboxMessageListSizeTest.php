<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): el envío manual de la
 * bandeja (`POST /api/admin/marketing/inbox/conversations/{id}/messages`)
 * valida primero el tamaño de `attachment_ids` (tope `max_per_send`, el de
 * siempre) y después cada id. Antes, 10 000 ids tardaban 1,7 s en llegar al
 * mismo 422.
 */
class MarketingInboxMessageListSizeTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/admin/marketing/inbox/conversations/1/messages';

    public function test_una_lista_de_adjuntos_por_encima_del_tope_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $this->postJson(self::RUTA, [], $h)->assertStatus(422);   // en frío, sin medir

        [$r, $atributos] = $this->conAtributosValidados(fn () => $this->postJson(self::RUTA, ['body' => 'hola', 'attachment_ids' => range(1, 10_000)], $h));

        $r->assertStatus(422);
        $this->assertSame(['attachment_ids'], array_keys($r->json('errors')));
        $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->postJson(self::RUTA, ['body' => 'hola', 'attachment_ids' => [1, 0]], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['attachment_ids.1'], array_keys($r->json('errors')));
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
