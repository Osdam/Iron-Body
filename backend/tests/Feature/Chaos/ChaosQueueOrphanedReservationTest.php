<?php

namespace Tests\Feature\Chaos;

use App\Models\Incident;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Observability\QueueHealthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría B-T16 (H-7 de OPS7, C-3 de la hoja de ruta): un worker que MUERE
 * con un trabajo reservado no lo veían `queue:health`, el monitor del H7 ni
 * IRON GUARD (`problemas []` en el ensayo de OPS7 con `kill -9`). La espera
 * solo cuenta los trabajos sin reservar, y en un carril de un solo worker y
 * poco tráfico no llega trabajo nuevo que la delate.
 *
 * Con filas sembradas en `jobs`, como las deja un worker muerto: reservada
 * hace más que el `retry_after` de su conexión (y el margen) y sin un latido
 * del carril desde entonces. Los carriles vigilados: la voz, el comercial y el
 * laboratorio. El incidente es uno por TRABAJO y con código estable.
 */
class ChaosQueueOrphanedReservationTest extends ChaosTestCase
{
    /** Lo que nunca puede salir de la carga de un trabajo a un incidente. */
    private const PAYLOAD_SECRET = 'carga-con-datos-del-trabajo-0x5eed';

    /** Siembra un trabajo RESERVADO hace `$hace` segundos en la cola del carril, como lo deja un worker muerto. */
    private function reservado(string $lane, int $hace, int $intentos = 1): int
    {
        $reservado = now()->subSeconds($hace)->timestamp;

        return (int) DB::table('jobs')->insertGetId([
            'queue' => (string) config("queue.lanes.{$lane}.queue"),
            'payload' => json_encode(['displayName' => 'Chaos', 'job' => 'Chaos', 'data' => self::PAYLOAD_SECRET]),
            'attempts' => $intentos,
            'reserved_at' => $reservado,
            'available_at' => $reservado - 5,
            'created_at' => $reservado - 5,
        ]);
    }

    /** El umbral del carril: su retry_after más el margen. */
    private static function umbral(string $lane): int
    {
        return (int) config('queue.connections.'.config("queue.lanes.{$lane}.connection").'.retry_after') + QueueHealthService::ORPHAN_MARGIN_SECONDS;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function monitorDeColas(): array
    {
        $codigo = Artisan::call('ultron-studio:monitor', ['--check' => ['queues'], '--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertIsArray($json);

        return [$codigo, $json];
    }

    public function test_los_carriles_vigilados_son_la_voz_el_comercial_y_el_laboratorio(): void
    {
        $vigilados = array_keys(array_filter((array) config('queue.lanes'), static fn (array $l): bool => ($l['watch_reserved'] ?? false) === true));
        sort($vigilados);
        $this->assertSame(['commercial', 'lab', 'voice'], $vigilados);
        $this->assertSame([120, 300, 300], [self::umbral('voice') - 60, self::umbral('commercial') - 60, self::umbral('lab') - 60], 'los retry_after de config/queue.php');
    }

    /** Lo que vio OPS7: con el worker muerto, las señales de siempre callan; la nueva no. */
    public function test_un_trabajo_reservado_de_un_worker_muerto_se_ve_en_la_foto(): void
    {
        $id = $this->reservado('voice', self::umbral('voice') + 5);

        $voz = app(QueueHealthService::class)->snapshot()['voice'];

        $this->assertSame([false, 0, 1, 1], [$voz['looks_unattended'], $voz['oldest_pending_seconds'], $voz['backlog'], $voz['reserved']],
            'las señales de antes no lo ven (H-7): por eso hace falta la nueva');
        $this->assertTrue($voz['looks_orphaned']);
        $this->assertSame([true, 120, 1], [$voz['watch_reserved'], $voz['retry_after_seconds'], $voz['orphaned_reserved']]);
        $this->assertSame([$id, 1], [$voz['orphaned_jobs'][0]['id'], $voz['orphaned_jobs'][0]['attempts']]);
        $this->assertGreaterThanOrEqual(self::umbral('voice') + 5, $voz['orphaned_jobs'][0]['reserved_seconds']);
        $this->assertStringNotContainsString(self::PAYLOAD_SECRET, (string) json_encode($voz), 'la foto no lleva la carga del trabajo');
    }

    public function test_no_es_huerfano_si_es_reciente_si_hay_latido_despues_o_si_el_carril_no_se_vigila(): void
    {
        $salud = app(QueueHealthService::class);

        // Reservado hace menos que su retry_after + el margen: un worker vivo puede estar con él.
        $this->reservado('voice', self::umbral('voice') - 5);
        $this->assertFalse($salud->snapshot()['voice']['looks_orphaned'], 'una reserva dentro de su plazo no es una avería');

        // Una reserva vieja, pero el carril terminó algo DESPUÉS: hay un worker vivo, y la recogerá él.
        DB::table('jobs')->delete();
        $this->reservado('commercial', self::umbral('commercial') + 30);
        $salud->heartbeat((string) config('queue.lanes.commercial.queue'));
        $this->assertFalse($salud->snapshot()['commercial']['looks_orphaned'], 'con latido posterior a la reserva hay quien atiende');

        // Un carril sin `watch_reserved` no se mira (los de mucho tráfico lo delatan con la espera).
        DB::table('jobs')->delete();
        $this->reservado('whatsapp', 86400);
        $whatsapp = $salud->snapshot()['whatsapp'];
        $this->assertSame([false, false, 0, []], [$whatsapp['watch_reserved'], $whatsapp['looks_orphaned'], $whatsapp['orphaned_reserved'], $whatsapp['orphaned_jobs']]);

        app(ChannelHealthDetector::class)->scan();
        $this->assertSame(0, Incident::where('kind', 'queue_reserved_orphaned')->count());
    }

    public function test_queue_health_sale_con_1_y_dice_que_el_worker_murio(): void
    {
        $this->reservado('lab', self::umbral('lab') + 10);

        $this->assertSame(1, Artisan::call('queue:health'));
        $salida = Artisan::output();
        $this->assertStringContainsString('WORKER MUERTO', $salida);
        $this->assertStringContainsString('· lab: 1 trabajo(s) reservado(s) hace más de 300 s (su retry_after) sin un latido del carril', $salida);

        $this->assertSame(0, Artisan::call('queue:health', ['--json' => true]), 'el JSON es para un script: sale con 0 y lo dice dentro');
        $json = json_decode(Artisan::output(), true);
        $this->assertSame([true, 1], [$json['lab']['looks_orphaned'], $json['lab']['orphaned_reserved']]);

        // Sin el trabajo huérfano, todos los carriles al día.
        DB::table('jobs')->delete();
        $this->assertSame(0, Artisan::call('queue:health'));
    }

    public function test_el_monitor_del_h7_lo_ve_y_no_duplica_el_incidente(): void
    {
        $this->reservado('commercial', self::umbral('commercial') + 10);

        [$codigo, $r] = $this->monitorDeColas();
        $this->assertSame(1, $codigo);
        $this->assertSame(['commercial:orphaned'], $r['checks']['queues']['problems']);
        $this->assertSame([], $r['incidents'], 'las colas son de iron-guard:scan');
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_iron_guard_abre_un_incidente_por_trabajo_con_codigo_estable_e_idempotente(): void
    {
        $id = $this->reservado('voice', self::umbral('voice') + 20);

        for ($i = 0; $i < 3; $i++) {
            app(ChannelHealthDetector::class)->scan();
        }

        $incidente = $this->assertSingleIncident('queue_reserved_orphaned', 3);
        $this->assertSame(['queue', Incident::SEVERITY_CRITICAL], [$incidente->source, $incidente->severity],
            'la voz es P1: quien pidió la saliente espera, como el carril sin atender');
        $this->assertSame(['voice', 'voice', $id, 1, 120], [$incidente->evidence['lane'], $incidente->evidence['queue'], $incidente->evidence['job_id'],
            $incidente->evidence['attempts'], $incidente->evidence['retry_after_seconds']]);
        $this->assertStringContainsString("El trabajo {$id} de la cola voice lleva", (string) $incidente->title);
        $this->assertStringNotContainsString(self::PAYLOAD_SECRET, (string) json_encode([$incidente->evidence, $incidente->title]), 'nunca la carga del trabajo');
        $this->assertNoSecretsLeaked($incidente);

        // Otro trabajo huérfano EN EL MISMO CARRIL es otra avería (la huella es el trabajo, no el carril), y uno del
        // laboratorio, otra más. El de antes sigue siendo uno, con una ocurrencia más.
        $otroDeLaVoz = $this->reservado('voice', self::umbral('voice') + 30);
        $delLaboratorio = $this->reservado('lab', self::umbral('lab') + 20);
        app(ChannelHealthDetector::class)->scan();
        $incidentes = Incident::where('kind', 'queue_reserved_orphaned')->orderBy('id')->get();
        $this->assertSame([[$id, 4], [$otroDeLaVoz, 1], [$delLaboratorio, 1]],
            $incidentes->map(fn (Incident $i): array => [$i->evidence['job_id'], (int) $i->occurrences])->all(), 'un incidente por trabajo');
        $this->assertSame([Incident::SEVERITY_CRITICAL, Incident::SEVERITY_CRITICAL, Incident::SEVERITY_HIGH], $incidentes->pluck('severity')->all(),
            'la voz es P1; el laboratorio, no');
    }

    public function test_iron_guard_scan_lo_abre_por_la_orden(): void
    {
        $this->reservado('commercial', self::umbral('commercial') + 15);

        $this->assertSame(0, Artisan::call('iron-guard:scan', ['--force' => true, '--json' => true]), 'detectar no es fallar');
        $json = json_decode(Artisan::output(), true);
        $this->assertContains('queue_reserved_orphaned', array_column($json['incidents'], 'kind'));
    }
}
