<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $inicio = hrtime(true);
        $r = $this->postJson(self::RUTA, ['body' => 'hola', 'attachment_ids' => range(1, 10_000)], $h);
        $ms = (hrtime(true) - $inicio) / 1e6;

        $r->assertStatus(422);
        $this->assertSame(['attachment_ids'], array_keys($r->json('errors')));
        $this->assertLessThan(1000, $ms, sprintf('El 422 tardó %.1f ms (antes, 1,7 s).', $ms));
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->postJson(self::RUTA, ['body' => 'hola', 'attachment_ids' => [1, 0]], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['attachment_ids.1'], array_keys($r->json('errors')));
    }
}
