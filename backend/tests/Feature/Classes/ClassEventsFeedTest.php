<?php

namespace Tests\Feature\Classes;

use App\Http\Controllers\Api\MemberRealtimeController;
use App\Models\CatalogEvent;
use App\Models\ClassEvent;
use App\Services\Classes\ClassEventsFeed;
use App\Services\RealtimeEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Las piezas del canal en tiempo real, sin el bucle del stream.
 *
 * `ClassEventsFeed` decide desde dónde lee el CRM y si tiene que releerlo todo
 * (`open`), qué hechos le quedan por recibir (`pending`) y qué viaja de cada
 * uno (`payload`). `MemberRealtimeController::catalogPayload` es lo que recibe
 * la app de los socios por el canal global. El stream SSE en sí (un bucle de
 * ~25 s) no se ejercita aquí.
 *
 * Los ids se toman de lo que devuelve la base, nunca se suponen: así las
 * pruebas no dependen de por dónde vaya el autoincremento.
 */
class ClassEventsFeedTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes 05/10/2026 10:00 en Bogotá = 15:00 UTC. */
    private const AHORA_BOGOTA = '2026-10-05 10:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AHORA_BOGOTA, 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Crea $n hechos seguidos y devuelve sus ids en orden.
     *
     * @return list<int>
     */
    private function hechos(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = (int) ClassEvent::create([
                'type' => 'booking.created', 'class_id' => 21, 'session_date' => '2026-10-07',
                'source' => 'app', 'created_at' => now(),
            ])->id;
        }

        return $ids;
    }

    /** @return list<int> */
    private function idsDe(iterable $eventos): array
    {
        return collect($eventos)->map(fn (ClassEvent $e) => (int) $e->id)->values()->all();
    }

    /** @return array<int, true> */
    private function enviados(array $ids): array
    {
        return array_fill_keys($ids, true);
    }

    // ── Apertura ────────────────────────────────────────────────────────────

    public function test_sin_cursor_se_pide_releer_y_se_sigue_desde_el_ultimo_hecho(): void
    {
        $ids = $this->hechos(3);

        $this->assertSame(['cursor' => $ids[2], 'resync' => true], ClassEventsFeed::open(null));
    }

    public function test_un_cursor_negativo_nunca_se_toma_por_bueno(): void
    {
        $this->assertSame(['cursor' => 0, 'resync' => true], ClassEventsFeed::open(-5), 'Con el registro vacío.');

        $ids = $this->hechos(3);
        $this->assertSame(['cursor' => $ids[2], 'resync' => true], ClassEventsFeed::open(-1), 'Con hechos.');
    }

    public function test_un_cursor_valido_continua_sin_releer(): void
    {
        $ids = $this->hechos(5);

        $this->assertSame(['cursor' => $ids[2], 'resync' => false], ClassEventsFeed::open($ids[2]));
        $this->assertSame(
            ['cursor' => $ids[4], 'resync' => false],
            ClassEventsFeed::open($ids[4]),
            'Al día con el último hecho: no hay nada que releer.',
        );
    }

    public function test_un_cursor_mayor_que_el_ultimo_hecho_es_de_otra_base_y_pide_releer(): void
    {
        $ids = $this->hechos(3);

        $this->assertSame(['cursor' => $ids[2], 'resync' => true], ClassEventsFeed::open($ids[2] + 1));
    }

    public function test_si_lo_siguiente_al_cursor_ya_se_podo_se_pide_releer(): void
    {
        $ids = $this->hechos(6);
        ClassEvent::whereIn('id', array_slice($ids, 0, 3))->delete();
        $masAntiguo = $ids[3];

        $this->assertSame(
            ['cursor' => $ids[5], 'resync' => true],
            ClassEventsFeed::open($masAntiguo - 2),
            'Falta el hecho que venía justo después del cursor: hay un hueco.',
        );
        $this->assertSame(
            ['cursor' => $masAntiguo - 1, 'resync' => false],
            ClassEventsFeed::open($masAntiguo - 1),
            'Justo antes del más antiguo que queda: no falta nada.',
        );
    }

    public function test_con_el_registro_vacio_un_cursor_viejo_reinicia_a_cero_y_la_siguiente_apertura_ya_no_relee(): void
    {
        $this->assertSame(['cursor' => 0, 'resync' => true], ClassEventsFeed::open(7));
        $this->assertSame(['cursor' => 0, 'resync' => false], ClassEventsFeed::open(0));
    }

    // ── Lo pendiente ────────────────────────────────────────────────────────

    public function test_se_entrega_lo_posterior_al_cursor_en_orden_y_sin_lo_ya_enviado(): void
    {
        $ids = $this->hechos(80);
        $base = $ids[0] - 1; // el hecho k-ésimo tiene id $base + k

        // Cursor 30: la ventana (30 - 50) cae por debajo del suelo 0, así que
        // se relee todo desde el suelo y solo lo ya enviado se descarta.
        $pendientes = ClassEventsFeed::pending($base, $base + 30, $this->enviados(range($base + 1, $base + 30)));

        $this->assertSame(range($base + 31, $base + 80), $this->idsDe($pendientes));
    }

    public function test_un_hecho_que_se_hizo_visible_tarde_dentro_de_la_ventana_se_entrega(): void
    {
        // En PostgreSQL el id se asigna al insertar, no al confirmar: el 45
        // puede aparecer cuando el cursor ya va por el 70.
        $ids = $this->hechos(80);
        $base = $ids[0] - 1;
        $enviados = $this->enviados(range($base + 21, $base + 70));
        unset($enviados[$base + 45]);

        $pendientes = ClassEventsFeed::pending($base + 10, $base + 70, $enviados);

        $this->assertSame(array_merge([$base + 45], range($base + 71, $base + 80)), $this->idsDe($pendientes));
    }

    public function test_lo_que_queda_por_debajo_de_la_ventana_no_se_relee(): void
    {
        $ids = $this->hechos(80);
        $base = $ids[0] - 1;
        $cursor = $base + 70;
        // Nada enviado entre el suelo (10) y el borde de la ventana (70 - 50 = 20).

        $pendientes = ClassEventsFeed::pending($base + 10, $cursor, $this->enviados(range($base + 21, $base + 70)));

        $this->assertSame($base + 71, $this->idsDe($pendientes)[0], 'La ventana es de '.ClassEventsFeed::OVERLAP.' ids hacia atrás, no más.');
        $this->assertNotContains($base + 15, $this->idsDe($pendientes));
    }

    public function test_nunca_se_relee_por_debajo_del_suelo_de_la_conexion(): void
    {
        $ids = $this->hechos(80);
        $base = $ids[0] - 1;

        // Suelo 60 por encima de cursor - 50 = 20: manda el suelo.
        $pendientes = ClassEventsFeed::pending($base + 60, $base + 70, $this->enviados(range($base + 61, $base + 70)));

        $this->assertSame(range($base + 71, $base + 80), $this->idsDe($pendientes));
    }

    public function test_la_primera_lectura_de_una_conexion_entrega_como_mucho_el_limite(): void
    {
        // Al conectar, suelo = cursor y no hay nada enviado: todo lo pendiente
        // es nuevo. `LIMIT` es el «máximo de hechos por lectura».
        $ids = $this->hechos(ClassEventsFeed::LIMIT + ClassEventsFeed::OVERLAP + 50);
        $base = $ids[0] - 1;

        $lectura = ClassEventsFeed::pending($base, $base, []);

        $this->assertCount(ClassEventsFeed::LIMIT, $lectura, 'Máximo de hechos por lectura.');
        $this->assertSame($base + 1, $this->idsDe($lectura)[0], 'Empieza por el más antiguo pendiente.');
    }

    public function test_con_la_ventana_ya_enviada_se_siguen_entregando_hasta_el_limite_de_nuevos(): void
    {
        // Que la ventana de relectura no se coma el cupo de hechos nuevos.
        $ids = $this->hechos(ClassEventsFeed::LIMIT + ClassEventsFeed::OVERLAP + 100);
        $base = $ids[0] - 1;
        $cursor = $base + 100;

        $lectura = ClassEventsFeed::pending($base, $cursor, $this->enviados(range($base + 1, $cursor)));

        $this->assertSame(range($cursor + 1, $cursor + ClassEventsFeed::LIMIT), $this->idsDe($lectura));
    }

    // ── Lo que viaja ────────────────────────────────────────────────────────

    public function test_el_hecho_viaja_con_exactamente_sus_campos_y_sin_la_reserva(): void
    {
        $hecho = ClassEvent::create([
            'type' => 'booking.cancelled', 'class_id' => 21, 'session_date' => '2026-10-07',
            'reservation_id' => 99, 'source' => 'crm', 'reason' => null, 'created_at' => now(),
        ]);

        $this->assertSame([
            'id' => (int) $hecho->id,
            'type' => 'booking.cancelled',
            'class_id' => 21,
            'session_date' => '2026-10-07',
            'source' => 'crm',
            'reason' => null,
            'at' => '2026-10-05T15:00:00+00:00',
        ], ClassEventsFeed::payload($hecho->fresh()));
    }

    public function test_un_hecho_de_clase_sin_fecha_viaja_con_la_fecha_nula(): void
    {
        $hecho = ClassEvent::create([
            'type' => 'class.updated', 'class_id' => 21, 'session_date' => null,
            'source' => 'crm', 'reason' => 'class.deleted', 'created_at' => now(),
        ]);

        $payload = ClassEventsFeed::payload($hecho->fresh());

        $this->assertNull($payload['session_date']);
        $this->assertSame('class.deleted', $payload['reason']);
    }

    public function test_el_aviso_global_a_los_socios_dice_de_que_clase_y_ocurrencia_es(): void
    {
        RealtimeEvents::classesChanged(21, '2026-10-07', 'reservation.created');
        $evento = CatalogEvent::sole()->fresh();

        $this->assertSame([
            'type' => 'reservation.created',
            'product_id' => null,
            'class_id' => 21,
            'session_date' => '2026-10-07',
            'changed' => ['classes'],
            'version' => (string) $evento->version,
            'event_id' => (int) $evento->id,
            'timestamp' => '2026-10-05T15:00:00+00:00',
        ], MemberRealtimeController::catalogPayload($evento));
    }
}
