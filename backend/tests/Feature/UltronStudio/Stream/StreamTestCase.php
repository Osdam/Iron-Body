<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Services\UltronStudio\Stream\StreamPacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * Base de las pruebas del canal (T24): la bandera encendida, el compás con un
 * reloj falso (conexión corta por defecto: 3 s, un sondeo por segundo → 4
 * sondeos) y CERO RED (`Http::preventStrayRequests`). Se ejercitan ruta,
 * autenticación, puerta, temas y el formato SSE real por HTTP.
 */
abstract class StreamTestCase extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    protected const URL = '/api/admin/ultron-studio/stream';

    protected FakeStreamClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'ultron_studio_stream.enabled' => true,
            'ultron_studio_stream.max_seconds' => 3,
            'ultron_studio_stream.poll_ms' => 1000,
            'ultron_studio_stream.heartbeat_seconds' => 15,
        ]);
        $this->clock = new FakeStreamClock;
        $this->usePacer();
    }

    /** El compás con la configuración vigente y el reloj falso (se vuelve a llamar tras cambiar la configuración). */
    protected function usePacer(): void
    {
        $this->app->instance(StreamPacer::class, StreamPacer::fromConfig($this->clock));
    }

    /** @param  array<string, string>  $headers */
    protected function open(string $query, array $headers): TestResponse
    {
        return $this->get(self::URL.'?'.$query, $headers);
    }

    /**
     * Los bloques del cuerpo SSE: [{id, event, data}] (los comentarios no son bloques).
     *
     * @return list<array{id:?string,event:?string,data:mixed}>
     */
    protected function blocks(string $body): array
    {
        $bloques = [];
        foreach (preg_split('/\n\n/', trim($body)) as $bloque) {
            $id = null;
            $event = null;
            $data = null;
            foreach (explode("\n", $bloque) as $linea) {
                if (str_starts_with($linea, 'id: ')) {
                    $id = substr($linea, 4);
                } elseif (str_starts_with($linea, 'event: ')) {
                    $event = substr($linea, 7);
                } elseif (str_starts_with($linea, 'data: ')) {
                    $data = json_decode(substr($linea, 6), true, 512, JSON_THROW_ON_ERROR);
                }
            }
            if ($event !== null || $data !== null) {
                $bloques[] = ['id' => $id, 'event' => $event, 'data' => $data];
            }
        }

        return $bloques;
    }

    /** @return list<array{id:?string,event:?string,data:mixed}> */
    protected function events(string $body, string $name): array
    {
        return array_values(array_filter($this->blocks($body), fn (array $b): bool => $b['event'] === $name));
    }

    protected function pings(string $body): int
    {
        return substr_count($body, ": ping\n\n");
    }
}
