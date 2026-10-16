<?php

namespace Tests\Unit;

use App\Services\ZktecoTurnstileService;
use Tests\TestCase;

/**
 * Un fallo de apertura por ZKTeco no devuelve el detalle de la conexión: con él
 * se distinguía «puerto cerrado», «timeout» y «otro servicio escuchando», es
 * decir, un escáner de puertos (revisión de seguridad de e8aa158, N3).
 */
class ZktecoErrorDetailTest extends TestCase
{
    public function test_un_puerto_cerrado_responde_el_mensaje_generico_sin_detalle(): void
    {
        // Un puerto local sin nada escuchando: conexión rechazada al instante.
        $servidor = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr(strrchr(stream_socket_get_name($servidor, false), ':'), 1);
        fclose($servidor);

        $resultado = app(ZktecoTurnstileService::class)->openDoor('127.0.0.1', $puerto, 1, null, 1);

        $this->assertFalse($resultado['ok']);
        $this->assertSame(ZktecoTurnstileService::GENERIC_ERROR, $resultado['error']);
        $this->assertStringNotContainsString((string) $puerto, $resultado['error']);
    }

    public function test_un_servicio_que_no_es_zkteco_responde_lo_mismo(): void
    {
        // Algo escucha pero no habla ZKTeco: antes respondía «magic incorrecto»
        // o «timeout», distinto de un puerto cerrado.
        $servidor = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr(strrchr(stream_socket_get_name($servidor, false), ':'), 1);

        $resultado = app(ZktecoTurnstileService::class)->openDoor('127.0.0.1', $puerto, 1, null, 1);
        fclose($servidor);

        $this->assertFalse($resultado['ok']);
        $this->assertSame(ZktecoTurnstileService::GENERIC_ERROR, $resultado['error']);
    }
}
