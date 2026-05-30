<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Implementación nativa (sin dependencias externas) del protocolo TCP
 * standalone de ZKTeco — usado por los torniquetes y controladores de la
 * línea "Eco" (SpeedFace, ProFace X, F18, K40, etc.) en el puerto 4370.
 *
 * Por ahora sólo se expone openDoor(): el único caso de uso es disparar
 * la apertura cuando una asistencia se valida en el CRM.
 *
 * Referencia del protocolo: SDK ZKTeco (Standalone) — handshake + CMD_UNLOCK.
 */
class ZktecoTurnstileService
{
    private const MAGIC = "\x50\x50\x82\x7d";
    private const CMD_CONNECT    = 1000;
    private const CMD_EXIT       = 1001;
    private const CMD_AUTH       = 1102;
    private const CMD_UNLOCK     = 31;
    private const CMD_ACK_OK     = 2000;
    private const CMD_ACK_UNAUTH = 2005;

    /**
     * Abre la puerta / torniquete durante $durationSeconds (1–254).
     *
     * @return array{ok:bool,host:string,port:int,duration_seconds?:int,session_id?:int,error?:string}
     */
    public function openDoor(
        string $host,
        int $port = 4370,
        int $durationSeconds = 3,
        ?string $commKey = null,
        int $timeoutSeconds = 3,
    ): array {
        $duration = max(1, min($durationSeconds, 254));
        $socket = null;

        try {
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeoutSeconds,
            );
            if (! $socket) {
                throw new RuntimeException("No se pudo conectar a {$host}:{$port} — {$errstr}");
            }
            stream_set_timeout($socket, $timeoutSeconds);

            // 1. Handshake — CMD_CONNECT (session_id se asigna en la respuesta).
            $response = $this->send($socket, self::CMD_CONNECT, 0, 0, '');
            $sessionId = $response['session_id'];
            $replyId   = $response['reply_id'];

            // 2. Autenticación si el dispositivo la exige (comm_key ≠ 0).
            if ($response['command'] === self::CMD_ACK_UNAUTH) {
                if ($commKey === null || $commKey === '') {
                    throw new RuntimeException('El dispositivo exige comm_key pero no se configuró.');
                }
                $replyId++;
                $token = $this->buildAuthToken((int) $commKey, $sessionId);
                $response = $this->send($socket, self::CMD_AUTH, $sessionId, $replyId, $token);
                if ($response['command'] !== self::CMD_ACK_OK) {
                    throw new RuntimeException('Autenticación rechazada — ¿comm_key incorrecto?');
                }
            } elseif ($response['command'] !== self::CMD_ACK_OK) {
                throw new RuntimeException("Handshake falló — comando inesperado: {$response['command']}");
            }

            // 3. CMD_UNLOCK con duración (4 bytes little-endian, en segundos).
            $replyId++;
            $payload  = pack('V', $duration);
            $response = $this->send($socket, self::CMD_UNLOCK, $sessionId, $replyId, $payload);
            $ok = $response['command'] === self::CMD_ACK_OK;

            // 4. CMD_EXIT — cerramos la sesión limpiamente.
            $replyId++;
            $this->send($socket, self::CMD_EXIT, $sessionId, $replyId, '');

            return [
                'ok' => $ok,
                'host' => $host,
                'port' => $port,
                'duration_seconds' => $duration,
                'session_id' => $sessionId,
            ];
        } catch (Throwable $e) {
            Log::warning('ZKTeco unlock failed', [
                'host'  => $host,
                'port'  => $port,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /** Envía un paquete y lee la respuesta. */
    private function send($socket, int $command, int $sessionId, int $replyId, string $payload): array
    {
        $packet = $this->buildPacket($command, $sessionId, $replyId, $payload);
        if (@fwrite($socket, $packet) === false) {
            throw new RuntimeException('Fallo al escribir en el socket TCP.');
        }
        return $this->readResponse($socket);
    }

    /**
     * Frame TCP: magic(4) + length(4 LE) + header(8: cmd, checksum, session, reply) + payload.
     */
    private function buildPacket(int $command, int $sessionId, int $replyId, string $payload): string
    {
        $body     = pack('vvvv', $command, 0, $sessionId, $replyId) . $payload;
        $checksum = $this->checksum($body);
        $body     = pack('vvvv', $command, $checksum, $sessionId, $replyId) . $payload;

        return self::MAGIC . pack('V', strlen($body)) . $body;
    }

    private function readResponse($socket): array
    {
        $head = $this->readFull($socket, 8);
        if (strlen($head) < 8 || substr($head, 0, 4) !== self::MAGIC) {
            throw new RuntimeException('Respuesta inválida — magic incorrecto.');
        }
        $length = unpack('V', substr($head, 4, 4))[1];
        $body   = $length > 0 ? $this->readFull($socket, $length) : '';

        if (strlen($body) < 8) {
            throw new RuntimeException('Header de respuesta truncado.');
        }
        $header = unpack('vcommand/vchecksum/vsession_id/vreply_id', substr($body, 0, 8));

        return [
            'command'    => $header['command'],
            'session_id' => $header['session_id'],
            'reply_id'   => $header['reply_id'],
            'payload'    => substr($body, 8),
        ];
    }

    private function readFull($socket, int $size): string
    {
        $buffer    = '';
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($socket);
                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('Timeout esperando respuesta del dispositivo.');
                }
                break;
            }
            $buffer    .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $buffer;
    }

    /** Checksum 16-bit "uno-complemento" estilo IP — el SDK ZKTeco lo usa así. */
    private function checksum(string $data): int
    {
        $length = strlen($data);
        $sum    = 0;
        $i      = 0;
        while ($length > 1) {
            $sum += unpack('v', substr($data, $i, 2))[1];
            $i      += 2;
            $length -= 2;
        }
        if ($length === 1) {
            $sum += ord($data[$i]);
        }
        while ($sum >> 16) {
            $sum = ($sum & 0xFFFF) + ($sum >> 16);
        }
        return (~$sum) & 0xFFFF;
    }

    /**
     * Token CMD_AUTH (algoritmo "make_commkey" del SDK ZKTeco).
     * Sólo se invoca si el dispositivo responde CMD_ACK_UNAUTH al handshake.
     */
    private function buildAuthToken(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = ($key & (1 << $i)) !== 0 ? (($k << 1) | 1) : ($k << 1);
            $k &= 0xFFFFFFFF;
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;

        $b0 = ($k & 0xFF)         ^ ord('Z');
        $b1 = (($k >> 8)  & 0xFF) ^ ord('K');
        $b2 = (($k >> 16) & 0xFF) ^ ord('S');
        $b3 = (($k >> 24) & 0xFF) ^ ord('O');

        // Sólo el segundo uint16 se XOR-ea con ticks (per spec).
        $w0 = $b0 | ($b1 << 8);
        $w1 = (($b2 | ($b3 << 8))) ^ $ticks;

        return pack('vv', $w0, $w1);
    }
}
