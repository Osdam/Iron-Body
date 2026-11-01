<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): las etiquetas de la
 * bandeja (`POST /api/admin/marketing/inbox/conversations/{id}/tags`) validan
 * primero el tamaño de `add` y `remove` (tope 10, el de siempre) y después
 * cada etiqueta. Antes, 10 000 etiquetas tardaban 1,5 s en llegar al mismo 422.
 */
class MarketingInboxTagsListSizeTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/admin/marketing/inbox/conversations/1/tags';

    public function test_una_lista_por_encima_del_tope_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $this->postJson(self::RUTA, ['add' => 'x'], $h)->assertStatus(422);   // en frío, sin medir

        foreach (['add', 'remove'] as $lista) {
            $inicio = hrtime(true);
            $r = $this->postJson(self::RUTA, [$lista => array_fill(0, 10_000, 'etiqueta')], $h);
            $ms = (hrtime(true) - $inicio) / 1e6;

            $r->assertStatus(422);
            $this->assertSame([$lista], array_keys($r->json('errors')));
            $this->assertLessThan(1000, $ms, sprintf('%s: el 422 tardó %.1f ms (antes, 1,5 s).', $lista, $ms));
        }
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->postJson(self::RUTA, ['add' => ['vip', str_repeat('x', 41)], 'remove' => [str_repeat('y', 41)]], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['add.1', 'remove.0'], array_keys($r->json('errors')));
    }
}
