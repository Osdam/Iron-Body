<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Outreach\Run\Stream\OutreachBrakesTopic;
use App\Services\Outreach\Run\Stream\OutreachJobsTopic;
use App\Services\Outreach\Run\Stream\OutreachOutcomesTopic;
use App\Services\Outreach\Run\Stream\OutreachRunsTopic;
use App\Services\UltronStudio\Stream\StreamCursors;
use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\Stream\StreamTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Los temas `outreach.*` del canal SSE (contrato H9, «Paquete SSE9»; T24):
 * cursor monótono, reanudación sin repetir ni perder, el permiso del GET de
 * lista y ningún dato personal en los eventos.
 */
class OutreachStreamTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<StreamTopic> */
    private function topics(): array
    {
        return [new OutreachRunsTopic, new OutreachJobsTopic, new OutreachOutcomesTopic, new OutreachBrakesTopic];
    }

    public function test_los_cuatro_temas_tienen_su_nombre_del_contrato_el_permiso_de_la_lista_y_nacen_vacios(): void
    {
        $nombres = [];
        foreach ($this->topics() as $t) {
            $nombres[] = $t->name();
            $this->assertSame(OutreachAccess::VIEW, $t->permission());
            $this->assertMatchesRegularExpression(StreamCursors::TOPIC, $t->name());
            $this->assertMatchesRegularExpression(StreamCursors::CURSOR, $t->cursor());
            $this->assertSame([], $t->since($t->cursor(), 50));
            $this->assertSame([], $t->since('cursor-roto', 50), 'un cursor roto no reemite la tabla');
        }
        $this->assertSame(['outreach.runs', 'outreach.jobs', 'outreach.outcomes', 'outreach.brakes'], $nombres);
    }

    public function test_los_eventos_llegan_en_orden_se_reanudan_sin_repetir_y_no_llevan_datos_personales(): void
    {
        $gente = $this->threePeople();
        $cabezas = [];
        foreach ($this->topics() as $t) {
            $cabezas[$t->name()] = $t->cursor();
        }
        $p = $this->plannedJobWorld(self::utcAt(self::NOW));
        (new OutreachDispatcher)->dispatch(now(), null, true);
        $parada = (new OutreachStop)->stopAll('Parada de la prueba', $gente['ana']);

        $ultimos = [];
        foreach ($this->topics() as $t) {
            $eventos = $t->since($cabezas[$t->name()], 50);
            $this->assertNotEmpty($eventos, $t->name());
            $anterior = null;
            foreach ($eventos as $e) {
                $this->assertInstanceOf(StreamEvent::class, $e);
                $this->assertSame([], array_diff(array_keys($e->data), StreamEvent::KEYS));
                $this->assertArrayHasKey('type', $e->data);
                $this->assertArrayHasKey('id', $e->data);
                $this->assertArrayHasKey('at', $e->data);
                $this->assertTrue(Str::isUuid((string) $e->data['id']), $t->name().': el id es el uuid de la fila');
                $json = json_encode($e->data);
                $this->assertStringNotContainsString('3001234567', $json);
                $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $json, 'ni seudónimos de persona o teléfono');
                $this->assertStringNotContainsString('Ana', $json);
                if ($anterior !== null) {
                    $this->assertNotSame($anterior, $e->cursor, 'cursores distintos');
                }
                $anterior = $e->cursor;
            }
            $ultimos[$t->name()] = $anterior;
            $this->assertSame([], $t->since($anterior, 50), $t->name().': reanudar desde el último no repite');
            $this->assertSame($anterior, $t->cursor(), 'la cabeza es el último emitido');
        }
        $this->assertSame('simulated', collect((new OutreachJobsTopic)->since($cabezas['outreach.jobs'], 50))->last()->data['state']);
        $this->assertSame('not_attempted', (new OutreachOutcomesTopic)->since($cabezas['outreach.outcomes'], 50)[0]->data['type']);
        $freno = (new OutreachBrakesTopic)->since($cabezas['outreach.brakes'], 50)[0]->data;
        $this->assertSame(['type' => 'global', 'id' => $parada['brake']['uuid'], 'state' => 'open', 'ref' => 'manual'], array_intersect_key($freno, array_flip(['type', 'id', 'state', 'ref'])));

        // Un cambio posterior (soltar el freno) llega después del último cursor, y solo ese.
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        (new OutreachStop)->release($gente['beto'], $parada['brake']['uuid'], 'Fin de la prueba', (string) Str::uuid());
        $nuevos = (new OutreachBrakesTopic)->since($ultimos['outreach.brakes'], 50);
        $this->assertCount(1, $nuevos);
        $this->assertSame('released', $nuevos[0]->data['state']);
        $this->assertSame([], (new OutreachJobsTopic)->since($ultimos['outreach.jobs'], 50));
        $this->assertSame(1, DB::table('outreach_brakes')->count());
    }
}
