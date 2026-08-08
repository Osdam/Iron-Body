<?php

namespace Tests\Feature\Chaos;

use App\Models\Incident;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Observability\QueueHealthService;
use Illuminate\Support\Facades\DB;

/**
 * Salud de los carriles · el modo de fallo que trajo separar las colas.
 *
 * Antes, quedarse sin worker se notaba en todo a la vez y era difícil no verlo.
 * Con cinco carriles el fallo interesante es más sigiloso: cuatro funcionan, el
 * de los mensajes de clientes se queda sin proceso, y por fuera nada chirría
 * —el webhook sigue devolviendo 200 y los trabajos se siguen encolando sin
 * error—. Simplemente nadie contesta a nadie.
 *
 * Estas pruebas fijan que eso se ve, que se ve con la gravedad correcta según a
 * quién afecte, y que las averías tranquilas no despiertan a nadie.
 */
class ChaosQueueHealthTest extends ChaosTestCase
{
    private function encolar(string $queue, int $cuantos, int $segundosDeEspera = 0): void
    {
        $available = now()->subSeconds($segundosDeEspera)->timestamp;

        for ($i = 0; $i < $cuantos; $i++) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => json_encode(['displayName' => 'Chaos', 'job' => 'Chaos']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $available,
                'created_at' => $available,
            ]);
        }
    }

    private function lane(string $name): string
    {
        return (string) config("queue.lanes.{$name}.queue");
    }

    // ── Medición ────────────────────────────────────────────────────────

    /** La foto de un carril dice lo que hace falta para decidir. */
    public function test_la_foto_de_un_carril_trae_lo_necesario_para_decidir(): void
    {
        $this->encolar($this->lane('whatsapp'), 7, segundosDeEspera: 45);

        $snapshot = app(QueueHealthService::class)->snapshot();

        $this->assertArrayHasKey('whatsapp', $snapshot);
        $this->assertArrayHasKey('agent', $snapshot);
        $this->assertArrayHasKey('media', $snapshot);
        $this->assertArrayHasKey('commercial', $snapshot);
        $this->assertArrayHasKey('billing', $snapshot);

        $lane = $snapshot['whatsapp'];

        $this->assertSame(7, $lane['backlog']);
        $this->assertGreaterThanOrEqual(45, $lane['oldest_pending_seconds'],
            'La edad del trabajo más viejo no se está midiendo: es el dato que dice cuánto lleva esperando una persona.');
        $this->assertSame(0, $lane['priority'], 'El carril de WhatsApp dejó de ser P0.');
        $this->assertSame(500, $lane['slo_wait_ms']);
        $this->assertArrayHasKey('failed_last_hour', $lane);
        $this->assertArrayHasKey('jobs_last_minute', $lane);
        $this->assertArrayHasKey('last_processed_seconds_ago', $lane);
    }

    /**
     * El latido distingue «no hay trabajo» de «no hay quien lo haga».
     *
     * Sin él, un carril tranquilo —facturación puede pasar días sin un solo
     * trabajo— sería indistinguible de uno abandonado, y la alarma se dispararía
     * por nada hasta que alguien la silenciara para siempre.
     */
    public function test_el_latido_distingue_carril_tranquilo_de_carril_abandonado(): void
    {
        $salud = app(QueueHealthService::class);

        // Carril vacío y sin latido: eso es salud, no avería.
        $this->assertFalse($salud->snapshot()['billing']['looks_unattended']);

        // Con cola vieja y sin latido: ahora sí falta alguien.
        $this->encolar($this->lane('billing'), 3, segundosDeEspera: 600);
        $this->assertTrue($salud->snapshot()['billing']['looks_unattended']);

        // Y con latido reciente, aunque haya cola, hay alguien trabajando.
        $salud->heartbeat($this->lane('billing'));
        $this->assertFalse($salud->snapshot()['billing']['looks_unattended'],
            'Un carril con workers vivos se reportó como abandonado.');
    }

    /** Una ráfaga recién encolada no es un carril caído. */
    public function test_una_rafaga_recien_llegada_no_se_confunde_con_una_caida(): void
    {
        $this->encolar($this->lane('whatsapp'), 50, segundosDeEspera: 2);

        $this->assertFalse(
            app(QueueHealthService::class)->snapshot()['whatsapp']['looks_unattended'],
            'Una ráfaga de dos segundos se reportó como carril sin workers.',
        );
    }

    // ── Incidentes ──────────────────────────────────────────────────────

    /**
     * Sin worker en el carril de los clientes, el incidente es CRÍTICO.
     *
     * La gravedad la decide a quién afecta, no cuánta cola hay. Un solo mensaje
     * sin atender aquí es más urgente que cien analíticas pendientes.
     */
    public function test_sin_worker_en_whatsapp_el_incidente_es_critico(): void
    {
        $this->encolar($this->lane('whatsapp'), 2, segundosDeEspera: 300);

        app(ChannelHealthDetector::class)->scan();

        $incident = Incident::where('kind', 'queue_unattended')->first();

        $this->assertNotNull($incident, 'El carril de los mensajes se quedó sin worker y nadie se enteró.');
        $this->assertSame(Incident::SEVERITY_CRITICAL, $incident->severity);
        $this->assertSame('queue', $incident->source);
        $this->assertStringContainsString($this->lane('whatsapp'), (string) $incident->title);
        $this->assertNoSecretsLeaked($incident);
    }

    /** El mismo carril caído no abre un incidente por corrida. */
    public function test_un_carril_caido_no_abre_un_incidente_por_corrida(): void
    {
        $this->encolar($this->lane('media'), 4, segundosDeEspera: 300);

        for ($i = 0; $i < 12; $i++) {
            app(ChannelHealthDetector::class)->scan();
        }

        $incident = $this->assertSingleIncident('queue_unattended', 12);
        $this->assertSame(Incident::SEVERITY_HIGH, $incident->severity,
            'Multimedia caído no es crítico: nadie está esperando un vídeo con el teléfono en la mano.');
    }

    /** Dos carriles caídos son dos averías distintas. */
    public function test_dos_carriles_caidos_son_dos_incidentes(): void
    {
        $this->encolar($this->lane('media'), 3, segundosDeEspera: 300);
        app(ChannelHealthDetector::class)->scan();

        $this->encolar($this->lane('commercial'), 3, segundosDeEspera: 300);
        app(ChannelHealthDetector::class)->scan();

        $this->assertSame(2, Incident::where('kind', 'queue_unattended')->count(),
            'Dos carriles con dos culpables distintos se agruparon como una sola avería.');
    }

    /**
     * Con workers vivos pero sin dar, el incidente es de CAPACIDAD.
     *
     * Es una avería distinta y merece un nombre distinto: aquí no falta un
     * proceso, faltan manos. Nadie tiene que levantarse de madrugada, pero
     * alguien tiene que verlo antes de que se convierta en el otro.
     */
    public function test_workers_vivos_que_no_dan_abren_incidente_de_capacidad(): void
    {
        $this->encolar($this->lane('whatsapp'), 30, segundosDeEspera: 10);
        app(QueueHealthService::class)->heartbeat($this->lane('whatsapp'));

        app(ChannelHealthDetector::class)->scan();

        $this->assertNull(Incident::where('kind', 'queue_unattended')->first(),
            'Se reportó falta de workers donde lo que falta es capacidad.');

        $incident = Incident::where('kind', 'queue_backlog')->first();
        $this->assertNotNull($incident, 'Una cola que incumple su compromiso de espera no levantó nada.');
        $this->assertSame(Incident::SEVERITY_HIGH, $incident->severity);
    }

    /** Un carril al día no levanta nada. */
    public function test_un_carril_al_dia_no_levanta_nada(): void
    {
        app(QueueHealthService::class)->heartbeat($this->lane('whatsapp'));

        app(ChannelHealthDetector::class)->scan();

        $this->assertSame(0, Incident::whereIn('kind', ['queue_unattended', 'queue_backlog'])->count(),
            'Un sistema sano generó alarmas: así es como se aprende a ignorar el panel.');
    }

    /**
     * La vigilancia no puede tumbar al negocio.
     *
     * Es el mismo principio de F6.45 aplicado a lo nuevo: si medir la salud de
     * las colas fallara, el canal comercial sigue funcionando.
     */
    public function test_si_la_medicion_falla_el_canal_sigue_operando(): void
    {
        $this->app->bind(QueueHealthService::class, fn () => new class extends QueueHealthService
        {
            public function snapshot(): array
            {
                throw new \RuntimeException('la medición se rompió');
            }
        });

        // El escaneo no revienta…
        app(ChannelHealthDetector::class)->scan();

        // …y un mensaje entrante se sigue atendiendo.
        $this->metaWebhook($this->inboundMessage('573001112233', 'Hola'))->assertOk();

        $this->assertSame(1, \App\Models\MarketingMessage::where('direction', 'inbound')->count());
    }
}
