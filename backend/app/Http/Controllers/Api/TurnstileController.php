<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TurnstileSetting;
use App\Services\Audit\AuditTrail;
use App\Services\SerialTurnstileService;
use App\Services\TurnstileService;
use App\Services\ZktecoTurnstileService;
use App\Support\Access\AdminActor;
use App\Support\Network\OutboundDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnstileController extends Controller
{
    /** Lo que devuelve la lectura en lugar de una credencial guardada. */
    private const MASK = '••••';

    private const SECRET_FIELDS = ['device_comm_key', 'auth_header'];

    public function __construct(
        private readonly TurnstileService $turnstile,
        private readonly ZktecoTurnstileService $zkteco,
        private readonly SerialTurnstileService $serial,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        return response()->json([
            'ok' => true,
            'data' => $this->serialize(TurnstileSetting::current()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'in:webhook,zkteco,serial'],
            'device_host' => ['nullable', 'string', 'max:120'],
            'device_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'device_comm_key' => ['nullable', 'string', 'max:60'],
            'serial_port' => ['nullable', 'string', 'max:20', 'regex:/^COM\d{1,3}$/i'],
            'serial_baud' => ['nullable', 'integer', 'min:300', 'max:921600'],
            'serial_command' => ['nullable', 'string', 'max:120'],
            'webhook_url' => ['nullable', 'string', 'max:500', 'url'],
            'http_method' => ['nullable', 'in:GET,POST,PUT,PATCH'],
            'auth_header' => ['nullable', 'string', 'max:500'],
            'request_payload' => ['nullable', 'string', 'max:4000'],
            'open_duration_ms' => ['nullable', 'integer', 'min:200', 'max:30000'],
            'fire_on_entry' => ['nullable', 'boolean'],
            'fire_on_exit' => ['nullable', 'boolean'],
            'sound_enabled' => ['nullable', 'boolean'],
        ]);

        // Las credenciales ya no se leen (ver serialize()): si llegan vacías,
        // ausentes o con la máscara que devolvió la lectura, es que no se
        // tocaron. Para cambiarlas se escribe el valor nuevo.
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $data) && (blank($data[$field]) || $data[$field] === self::MASK)) {
                unset($data[$field]);
            }
        }

        $settings = TurnstileSetting::current();
        $previoTorniquete = $settings->getOriginal();
        $settings->fill($data)->save();

        // Configuración del control de acceso físico: quién la cambió importa
        // tanto como el cambio. La traza NO guarda credenciales del dispositivo.
        app(AuditTrail::class)->record($request, [
            'action' => 'settings', 'module' => 'Asistencias', 'entity' => 'torniquete',
            'entity_id' => $settings->id,
            'summary' => 'Cambió la configuración del torniquete',
            'changes' => app(AuditTrail::class)->changesOf(
                $settings,
                $previoTorniquete,
                // Solo las banderas de comportamiento. Nada de URLs, tokens ni
                // credenciales del dispositivo, que también viven aquí.
                ['enabled', 'fire_on_entry', 'fire_on_exit'],
            ),
            'metadata' => ['enabled' => (bool) $settings->enabled],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->serialize($settings->refresh()),
        ]);
    }

    /** Disparo manual desde el CRM. */
    public function trigger(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'action' => ['nullable', 'in:entry,exit'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $settings = TurnstileSetting::current();
        $result = $this->turnstile->trigger($settings, [
            'member_name' => $data['reason'] ?? 'Disparo manual',
            'action' => $data['action'] ?? 'entry',
        ]);

        // Abrir la puerta a mano es una acción física: queda registrada tanto
        // si el dispositivo respondió como si falló.
        app(AuditTrail::class)->record($request, [
            'action' => 'status', 'module' => 'Asistencias', 'entity' => 'torniquete',
            'entity_id' => $settings->id,
            'summary' => 'Abrió el torniquete manualmente desde el CRM',
            'metadata' => ['ok' => (bool) ($result['ok'] ?? false), 'reason' => $data['reason'] ?? null],
        ]);

        return response()->json([
            'ok' => $result['ok'] ?? false,
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * Disparo ad-hoc de un webhook arbitrario (Sonoff/ESP32/Shelly). Útil para
     * probar el relé HTTP sin tener que guardar settings primero.
     */
    public function fireWebhook(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url:http,https'],
            'method' => ['nullable', 'in:GET,POST,PUT,PATCH'],
            'payload' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = TurnstileSetting::current();

        // Destino, redirecciones y cuerpo: ver TurnstileService::sendWebhook().
        $result = $this->turnstile->sendWebhook(
            $settings,
            $data['url'],
            strtoupper($data['method'] ?? 'POST'),
            $data['payload'] ?? null,
        );

        if (($result['reason'] ?? null) === 'destination_not_allowed') {
            return $this->destinationNotAllowed();
        }

        // Hacer que el servidor llame a una URL de la red de la sede es, como
        // abrir la puerta a mano, una acción que queda registrada. Sin la URL.
        app(AuditTrail::class)->record($request, [
            'action' => 'status', 'module' => 'Asistencias', 'entity' => 'torniquete',
            'entity_id' => $settings->id,
            'summary' => 'Probó el relé HTTP del torniquete desde el CRM',
            'metadata' => ['ok' => (bool) $result['ok'], 'via' => 'webhook', 'http_code' => $result['status'] ?? null],
        ]);

        return response()->json([
            'ok' => $result['ok'],
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * Apertura por puerto COM (replica NetGymValidator).
     * Acepta override de port/baud/command desde el body, o usa el singleton.
     */
    public function openSerial(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'port' => ['nullable', 'string', 'max:20', 'regex:/^COM\d{1,3}$/i'],
            'baud' => ['nullable', 'integer', 'min:300', 'max:921600'],
            'command' => ['nullable', 'string', 'max:120'],
        ]);

        $settings = TurnstileSetting::current();
        $port = $data['port'] ?? $settings->serial_port;
        $baud = $data['baud'] ?? ($settings->serial_baud ?: 9600);
        $command = $data['command'] ?? ($settings->serial_command ?: 'PULSE 3000');

        if (! $port) {
            return response()->json([
                'ok' => false,
                'message' => 'Configura el puerto COM (ej. COM4) en ajustes o envíalo en el body.',
            ], 422);
        }

        $result = $this->serial->open($port, (int) $baud, $command);

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $result['ok'] ? 'success' : 'error',
            'last_http_code' => null,
            'last_error' => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        app(AuditTrail::class)->record($request, [
            'action' => 'status', 'module' => 'Asistencias', 'entity' => 'torniquete',
            'entity_id' => $settings->id,
            'summary' => 'Abrió el torniquete por puerto serie',
            'metadata' => ['ok' => (bool) $result['ok'], 'via' => 'serial'],
        ]);

        return response()->json([
            'ok' => (bool) $result['ok'],
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * Apertura directa de un torniquete ZKTeco Eco (SDK standalone, TCP 4370).
     * Si no se envía host/port/comm_key en el body, se toman del singleton.
     * Útil para pruebas desde el CRM sin tener que activar el torniquete global.
     */
    public function openZkteco(Request $request): JsonResponse
    {
        if ($rechazo = $this->requirePerson($request)) {
            return $rechazo;
        }

        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:120'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'string', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:254'],
        ]);

        $settings = TurnstileSetting::current();
        $host = $data['host'] ?? $settings->device_host;
        $port = $data['port'] ?? ($settings->device_port ?: 4370);
        // La clave enmascarada que devuelve la lectura significa «la guardada».
        $commKey = array_key_exists('comm_key', $data) && $data['comm_key'] !== self::MASK
            ? $data['comm_key']
            : $settings->device_comm_key;
        $duration = $data['duration_seconds']
            ?? max(1, (int) round(($settings->open_duration_ms ?: 3000) / 1000));

        if (! $host) {
            return response()->json([
                'ok' => false,
                'message' => 'Falta el host del dispositivo ZKTeco (configurar en ajustes o enviar en body).',
            ], 422);
        }

        // Socket TCP a un host del cuerpo: nunca el propio servidor ni los
        // metadatos de la nube, y a la IP ya resuelta (ver OutboundDestination).
        $address = OutboundDestination::resolve((string) $host);
        if ($address === null) {
            return $this->destinationNotAllowed();
        }

        $result = $this->zkteco->openDoor(
            host: $address,
            port: (int) $port,
            durationSeconds: (int) $duration,
            commKey: $commKey,
        );

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $result['ok'] ? 'success' : 'error',
            'last_http_code' => null,
            'last_error' => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        app(AuditTrail::class)->record($request, [
            'action' => 'status', 'module' => 'Asistencias', 'entity' => 'torniquete',
            'entity_id' => $settings->id,
            'summary' => 'Abrió el torniquete por ZKTeco',
            'metadata' => ['ok' => (bool) ($result['ok'] ?? false), 'via' => 'zkteco'],
        ]);

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], ($result['ok'] ?? false) ? 200 : 502);
    }

    /**
     * El torniquete lo maneja una PERSONA, nunca el token compartido de
     * automatizaciones.
     *
     * Leer la configuración entregaba en claro las credenciales del dispositivo
     * (hoy van enmascaradas, ver serialize()) y es lectura, así que la puerta lo
     * resolvía a `classes.view`, que ese token obtiene. Las demás acciones
     * abren la puerta física o hacen que el servidor llame a un host o URL
     * que viaja en el cuerpo: la puerta ya exige `classes.manage`, y esto es
     * la segunda cerradura.
     *
     * El CRM entra con la sesión de una persona y recibe exactamente lo de
     * siempre. El lector local de la sede no usa estas rutas.
     */
    private function requirePerson(Request $request): ?JsonResponse
    {
        if (AdminActor::from($request) === null) {
            return response()->json([
                'ok' => false,
                'code' => 'person_required',
                'message' => 'El torniquete solo lo maneja una persona. Entra con tu cuenta.',
            ], 403);
        }

        return null;
    }

    private function destinationNotAllowed(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'destination_not_allowed',
            'message' => TurnstileService::DESTINATION_NOT_ALLOWED,
        ], 422);
    }

    /**
     * Las credenciales del dispositivo se ESCRIBEN pero no se leen: Recepción
     * y el lector de la sede leen esta configuración y no necesitan la clave
     * del ZKTeco ni la cabecera del relé. Se dice si existen; el formulario
     * del CRM no las reenvía al guardar (ver update()).
     */
    private function serialize(TurnstileSetting $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'enabled' => $s->enabled,
            'mode' => $s->mode,
            'device_host' => $s->device_host,
            'device_port' => $s->device_port,
            'device_comm_key' => filled($s->device_comm_key) ? self::MASK : null,
            'has_device_comm_key' => filled($s->device_comm_key),
            'serial_port' => $s->serial_port,
            'serial_baud' => $s->serial_baud,
            'serial_command' => $s->serial_command,
            'webhook_url' => $s->webhook_url,
            'http_method' => $s->http_method,
            'auth_header' => filled($s->auth_header) ? self::MASK : null,
            'has_auth_header' => filled($s->auth_header),
            'request_payload' => $s->request_payload,
            'open_duration_ms' => $s->open_duration_ms,
            'fire_on_entry' => $s->fire_on_entry,
            'fire_on_exit' => $s->fire_on_exit,
            'sound_enabled' => $s->sound_enabled,
            'last_triggered_at' => optional($s->last_triggered_at)->toIso8601String(),
            'last_status' => $s->last_status,
            'last_error' => $s->last_error,
            'last_http_code' => $s->last_http_code,
        ];
    }
}
