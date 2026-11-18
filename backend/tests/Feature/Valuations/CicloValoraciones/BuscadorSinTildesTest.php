<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Services\Valuations\ValuationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CONTRATO_CICLO_VALORACIONES.md §5: el buscador de socios de la agenda no
 * distingue mayúsculas ni tildes (á é í ó ú ü ñ), en SQLite y en PostgreSQL,
 * sin extensiones. La forma de la respuesta no cambia y el permiso pasa a ser
 * el de LEER la agenda.
 */
class BuscadorSinTildesTest extends TestCase
{
    use CicloFixtures;
    use RefreshDatabase;

    private array $rec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->rec = $this->reception();
        foreach (['María Gómez', 'José NÚÑEZ', 'Ana Muñoz', 'MARIANA LÓPEZ', 'Raúl Güiza', 'Pedro Pérez'] as $nombre) {
            $this->member($nombre);
        }
        // Escrito DESCOMPUESTO (NFD: «i» + U+0301), como lo guardan algunos teclados.
        $this->member("Luci\u{0301}a Ri\u{0301}os");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nombres(string $q, ?array $h = null): array
    {
        return array_column($this->getJson('/api/admin/valuations/members/search?q='.rawurlencode($q), $h ?? $this->rec)->assertOk()->json('data'), 'name');
    }

    public function test_mari_encuentra_maria_y_nunez_encuentra_nunez(): void
    {
        // El orden de `full_name` depende de la intercalación del motor: solo el conjunto.
        $this->assertEqualsCanonicalizing(['María Gómez', 'MARIANA LÓPEZ'], $this->nombres('Mari'));
        $this->assertSame(['José NÚÑEZ'], $this->nombres('NUÑEZ'));
        $this->assertSame(['José NÚÑEZ'], $this->nombres('Núñez'));
    }

    public function test_sin_tildes_ni_mayusculas_en_los_dos_sentidos(): void
    {
        $this->assertSame(['José NÚÑEZ'], $this->nombres('nunez'));
        $this->assertSame(['José NÚÑEZ'], $this->nombres('JOSE'));
        $this->assertSame(['Ana Muñoz'], $this->nombres('MUNOZ'));
        $this->assertEqualsCanonicalizing(['María Gómez', 'MARIANA LÓPEZ'], $this->nombres('MARÍA'));
        $this->assertSame(['MARIANA LÓPEZ'], $this->nombres('lopez'));
        $this->assertSame(['Raúl Güiza'], $this->nombres('guiza'));
        $this->assertSame(['Raúl Güiza'], $this->nombres('RAUL'));
        $this->assertSame(['Pedro Pérez'], $this->nombres('PÉREZ'));
        $this->assertSame(["Luci\u{0301}a Ri\u{0301}os"], $this->nombres('lucia rios'));
        $this->assertSame(["Luci\u{0301}a Ri\u{0301}os"], $this->nombres("Luci\u{0301}a"));
    }

    public function test_los_comodines_siguen_escapados_y_la_forma_no_cambia(): void
    {
        $this->assertSame([], $this->nombres('%%'));
        $this->assertSame([], $this->nombres('M_r'));
        $fila = $this->getJson('/api/admin/valuations/members/search?q=gomez', $this->rec)->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'document_tail', 'membership_status', 'membership_label', 'assigned_trainer'], array_keys($fila));
    }

    public function test_basta_con_leer_la_agenda(): void
    {
        // Sin `valuations.create`: antes, 403.
        $soloLee = $this->h($this->staffWith([ValuationAccess::VIEW_ALL]));
        $this->assertEqualsCanonicalizing(['María Gómez', 'MARIANA LÓPEZ'], $this->nombres('mari', $soloLee));

        // `view` sin entrenador vinculado no tiene alcance en la agenda: 403, como el resto.
        $sinAlcance = $this->h($this->staffWith([ValuationAccess::VIEW]));
        $this->getJson('/api/admin/valuations/members/search?q=mari', $sinAlcance)->assertForbidden();
    }
}
