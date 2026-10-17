<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptStation;
use App\Rules\PlainText;
use App\Services\Caja\ReceiptAgentTickets;
use App\Support\Caja\UntrustedText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * La API del SERVICIO LOCAL DE IMPRESIÓN — /api/receipt-agent/* (contrato §4).
 *
 * SIN SESIÓN: no hay administrador, ni token compartido, ni cookie. Lo único
 * que autoriza es el TICKET de un solo uso que emitió alguien con sesión
 * (ReceiptController::startPrint o ReceiptStationController::issueTicket),
 * atado a una estación registrada y, si es de impresión, a un intento abierto.
 * Estas rutas no son /api/admin: ni ProtectAdminPaths ni
 * EnforceAdminAuthorization las tocan, y el grupo `api` no lleva CSRF.
 *
 * RESPUESTAS UNIFORMES: `{ok:false, code, message}`. Un rechazo no dice nada
 * del recibo, del cliente ni de quién emitió el ticket; un texto con forma de
 * token que no existe y un ticket revocado responden igual (404
 * `ticket_invalid`). Throttle propio (`receipt-agent`, 60/min por IP).
 *
 * SOLO JSON Y UTF-8 VÁLIDO (revisión de seguridad M-1). Un cuerpo
 * form-urlencoded deja pasar bytes que no son UTF-8, y con ellos los filtros de
 * texto fallaban abiertos y PostgreSQL acababa en 500 con los bytes crudos en el
 * log. Se corta antes de validar nada y antes de tocar la base.
 *
 * Nada de aquí toca dinero: canjear entrega texto y marca el despacho; reportar
 * cierra el intento de impresión. Ni cobros, ni ventas, ni abonos, ni turnos.
 */
class ReceiptAgentController extends Controller
{
    /** código → [HTTP, mensaje para quien está en la caja] */
    private const ERRORS = [
        'unsupported_media_type' => [415, 'El servicio de impresión debe enviar JSON.'],
        'invalid_request' => [422, 'La petición del servicio de impresión no es válida.'],
        'ticket_invalid' => [404, 'El ticket de impresión no es válido.'],
        'ticket_expired' => [410, 'El ticket de impresión caducó. Vuelve a imprimir desde el CRM.'],
        'ticket_used' => [409, 'Este ticket de impresión ya se usó.'],
        'station_mismatch' => [403, 'Este ticket es de otro equipo.'],
        'station_inactive' => [403, 'Este equipo está desactivado para imprimir recibos.'],
        'protocol_unsupported' => [426, 'Actualiza el servicio de impresión: esta versión ya no es compatible.'],
        'agent_disabled' => [503, 'La impresión con el servicio local está desactivada en el servidor. El cobro quedó registrado y el recibo queda pendiente: avisa a un administrador.'],
        'attempt_closed' => [409, 'Este intento de impresión ya estaba cerrado.'],
        'job_too_long' => [422, 'Este recibo es demasiado largo para imprimirlo con el servicio local.'],
        'layout_error' => [500, 'No se pudo preparar el recibo para imprimir.'],
        'not_redeemed' => [409, 'Ese ticket no se ha canjeado.'],
        'already_reported' => [409, 'El resultado de ese ticket ya estaba registrado.'],
        'report_window_closed' => [410, 'Ya no se puede registrar el resultado de ese ticket.'],
        'invalid_outcome' => [422, 'El resultado no corresponde a ese ticket.'],
        // Un fallo inesperado (la base, un defecto): sin detalles. Lo registra
        // ReceiptAgentTickets, sin el token.
        'internal_error' => [500, 'No se pudo procesar el ticket de impresión. Inténtalo de nuevo.'],
    ];

    public function __construct(private readonly ReceiptAgentTickets $tickets) {}

    /** POST /api/receipt-agent/redeem {ticket, station_key, agent} */
    public function redeem(Request $request): JsonResponse
    {
        if ($rechazo = $this->rejectUnsafeBody($request)) {
            return $rechazo;
        }

        $validator = Validator::make($request->all(), [
            'ticket' => ['required', 'string', 'max:128'],
            'station_key' => ['required', 'uuid'],
            'agent' => ['required', 'array'],
            'agent.version' => ['required', 'string', 'max:32', new PlainText],
            'agent.protocol' => ['required', 'integer', 'min:0', 'max:32767'],
            'agent.printer_name' => ['nullable', 'string', 'max:120', new PlainText],
            // §4: el trabajo se maqueta a las columnas del servicio. Otra cosa: 422.
            'agent.columns' => ['required', 'integer', Rule::in(ReceiptStation::COLUMNS)],
            'agent.codepage' => ['nullable', 'string', Rule::in(ReceiptStation::CODEPAGES)],
        ]);

        if ($validator->fails()) {
            return $this->error('invalid_request');
        }

        $resultado = $this->tickets->redeem($validator->validated(), $request->ip());

        return isset($resultado['error'])
            ? $this->error($resultado['error'], $request)
            : $this->ok($resultado['body']);
    }

    /** POST /api/receipt-agent/report {ticket, station_key, outcome, …} */
    public function report(Request $request): JsonResponse
    {
        if ($rechazo = $this->rejectUnsafeBody($request)) {
            return $rechazo;
        }

        $validator = Validator::make($request->all(), [
            'ticket' => ['required', 'string', 'max:128'],
            'station_key' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in([
                ReceiptAgentTicket::OUTCOME_ACCEPTED,
                ReceiptAgentTicket::OUTCOME_ERROR,
                ReceiptAgentTicket::OUTCOME_APPLIED,
            ])],
            'error_code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            // Texto libre: se guarda LIMPIO (sin controles ni invisibles) y
            // recortado a 255, en vez de rechazarse: un mensaje raro o largo no
            // puede impedir que quede constancia de cómo terminó (M-2).
            'message' => ['nullable', 'string', 'max:2000'],
            'spool_job_id' => ['nullable', 'integer', 'min:0'],
            'printer_name' => ['nullable', 'string', 'max:120', new PlainText],
        ]);

        if ($validator->fails()) {
            return $this->error('invalid_request');
        }

        $resultado = $this->tickets->report($validator->validated(), $request->ip());

        return isset($resultado['error'])
            ? $this->error($resultado['error'], $request)
            : $this->ok($resultado['body']);
    }

    /** 415 si no es JSON; 422 si alguna cadena (o clave) no es UTF-8 válido. */
    private function rejectUnsafeBody(Request $request): ?JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error('unsupported_media_type');
        }

        if (! UntrustedText::allUtf8($request->all())) {
            return $this->error('invalid_request');
        }

        return null;
    }

    /** @param  array<string, mixed>  $body */
    private function ok(array $body): JsonResponse
    {
        // Lleva nombres de clientes e importes: ningún intermedio lo guarda.
        return response()->json($body)->header('Cache-Control', 'no-store');
    }

    private function error(string $code, ?Request $request = null): JsonResponse
    {
        [$estado, $mensaje] = self::ERRORS[$code] ?? [500, 'No se pudo procesar el ticket de impresión.'];

        if (! isset(self::ERRORS[$code]) || in_array($code, ['station_mismatch', 'layout_error'], true)) {
            // Un ticket presentado desde otro equipo es una señal, no ruido; un
            // fallo de maquetación, un defecto. Nunca el token.
            Log::warning('receipt_agent:rejected', [
                'code' => $code,
                'route' => $request?->path(),
                'ip' => $request?->ip(),
            ]);
        }

        return response()
            ->json(['ok' => false, 'code' => isset(self::ERRORS[$code]) ? $code : 'internal_error', 'message' => $mensaje], $estado)
            ->header('Cache-Control', 'no-store');
    }
}
