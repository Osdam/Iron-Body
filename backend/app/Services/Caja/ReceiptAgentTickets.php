<?php

namespace App\Services\Caja;

use App\Exceptions\ReceiptException;
use App\Models\Admin;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptPrint;
use App\Models\ReceiptStation;
use App\Support\Caja\UntrustedText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * LOS TICKETS DEL SERVICIO LOCAL DE IMPRESIÓN: emitir, canjear, reportar.
 * Contrato: CONTRATO_AGENTE_IMPRESION.md §4, §5.1, §5.3.
 *
 * EL TICKET ES LA AUTORIZACIÓN. El servicio de Windows no tiene sesión ni
 * credencial propia: solo puede pedir lo que un administrador con sesión ya
 * autorizó, para SU estación, una vez y durante unos segundos. Por eso:
 *
 *  · 32 bytes de random_bytes → base64url sin relleno (43 caracteres). Se
 *    devuelve UNA vez a quien lo emite y aquí se guarda solo su SHA-256: una
 *    copia de la base o la pantalla de eventos no sirven para canjear nada.
 *  · El canje va en UNA transacción con bloqueo de fila: dos canjes del mismo
 *    ticket a la vez no imprimen dos veces; el segundo recibe `ticket_used`.
 *  · ORDEN DE BLOQUEO: primero el intento (`receipt_prints`), después el
 *    ticket y, en el canje, la estación. Es el mismo orden que usa la
 *    repetición de un intento al revocar sus tickets previos; con órdenes
 *    distintos, un canje y una repetición simultáneos se esperarían el uno al
 *    otro (interbloqueo). La estación va con FOR UPDATE y no FOR SHARE: dos
 *    canjes con FOR SHARE que luego actualizan la misma estación sí se
 *    interbloquean (medido en la revisión de BD: 7 deadlocks en 10 s).
 *  · Un error INESPERADO (la base, un defecto) no sale tal cual: el servicio
 *    recibe `internal_error` y el log lo registra sin el token ni el SQL.
 *  · Ningún rechazo cambia nada: un ticket de otra estación, de una estación
 *    desactivada o de un servicio viejo sigue sin canjear para quien sí puede.
 *
 * NADA DE AQUÍ TOCA DINERO: ni cobros, ni ventas, ni abonos, ni turnos, ni
 * facturas. Lo único que escribe fuera de sus tablas es `receipt_prints`
 * (despacho y cierre), por los métodos de {@see ReceiptPrints}.
 */
final class ReceiptAgentTickets
{
    public const PROTOCOL = 1;

    public function __construct(
        private readonly ReceiptPrints $prints,
        private readonly ReceiptPresenter $presenter,
        private readonly ReceiptTextLayout $layout,
    ) {}

    /** El interruptor general (`caja.receipts.agent.enabled`). */
    public static function enabled(): bool
    {
        return (bool) config('caja.receipts.agent.enabled', true);
    }

    public static function minProtocol(): int
    {
        return (int) config('caja.receipts.agent.min_protocol', 1);
    }

    // ── Emitir ──────────────────────────────────────────────────────────────

    /**
     * Emite un ticket. El token en claro solo existe en el valor devuelto.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{ticket: ReceiptAgentTicket, token: string}
     */
    public function issue(ReceiptStation $station, string $purpose, ?ReceiptPrint $print, ?Admin $actor, ?string $variant = null, ?array $payload = null): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ahora = now();

        $ticket = ReceiptAgentTicket::create([
            'uuid' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'receipt_station_id' => $station->id,
            'receipt_print_id' => $print?->id,
            'variant' => $variant,
            'payload' => $payload,
            'issued_by_admin_id' => $actor?->id,
            'issued_by_name' => $actor?->name,
            'issued_at' => $ahora,
            'expires_at' => $ahora->copy()->addSeconds($this->ttl($purpose)),
        ]);

        return ['ticket' => $ticket, 'token' => $token];
    }

    /**
     * El ticket de un intento de impresión `agent` (POST /receipts/{uuid}/prints).
     *
     * Vale igual para el intento recién creado que para una REPETICIÓN del mismo
     * `client_attempt_id`: si el intento sigue abierto y ningún servicio lo ha
     * tomado, se revocan sus tickets anteriores y se emite uno nuevo (el
     * navegador pudo perder la respuesta). Si ya se despachó o se cerró, no hay
     * nada que volver a imprimir con este intento: null.
     *
     * @return array{token: string, expires_at: string}|null
     */
    public function issueForAttempt(ReceiptPrint $intento, ReceiptStation $station, Admin $actor): ?array
    {
        if ($intento->print_mode !== ReceiptPrint::PRINT_MODE_AGENT) {
            return null;
        }

        return DB::transaction(function () use ($intento, $station, $actor): ?array {
            // Mismo orden que el canje: primero el intento. Mientras esta
            // transacción lo tenga, ningún canje puede despacharlo a medias.
            $fila = ReceiptPrint::whereKey($intento->id)->lockForUpdate()->first();

            if ($fila === null || $fila->status !== ReceiptPrint::STATUS_STARTED || $fila->dispatched_at !== null) {
                return null;
            }

            // Un ticket anterior que alguien guardó ya no sirve: solo vale el
            // último que recibió el navegador.
            ReceiptAgentTicket::query()
                ->where('receipt_print_id', $fila->id)
                ->whereNull('revoked_at')
                ->whereNull('redeemed_at')
                ->update(['revoked_at' => now()]);

            ['ticket' => $ticket, 'token' => $token] = $this->issue($station, ReceiptAgentTicket::PURPOSE_PRINT, $fila, $actor);

            return ['token' => $token, 'expires_at' => $ticket->expires_at->toIso8601String()];
        });
    }

    // ── Canjear (§4 redeem) ─────────────────────────────────────────────────

    /**
     * @param  array{ticket: string, station_key: string, agent: array{version: string, protocol: int, printer_name?: string|null, columns: int, codepage?: string|null}}  $data
     * @return array{error: string}|array{body: array<string, mixed>}
     */
    public function redeem(array $data, ?string $ip): array
    {
        try {
            $previo = $this->find($data['ticket']);
        } catch (Throwable $e) {
            return $this->unexpected('redeem', $e, null);
        }
        if ($previo === null) {
            return ['error' => 'ticket_invalid'];
        }

        try {
            return ['body' => DB::transaction(fn (): array => $this->redeemLocked($previo, $data, $ip))];
        } catch (ReceiptException $e) {
            // Cualquier rechazo deshace la transacción entera: un ticket no se
            // consume a medias ni un intento queda despachado sin ticket canjeado.
            return ['error' => $this->rejectionCode($e)];
        } catch (Throwable $e) {
            return $this->unexpected('redeem', $e, $previo);
        }
    }

    /** @return array<string, mixed> */
    private function redeemLocked(ReceiptAgentTicket $previo, array $data, ?string $ip): array
    {
        $intento = $previo->receipt_print_id
            ? ReceiptPrint::whereKey($previo->receipt_print_id)->lockForUpdate()->first()
            : null;
        $ticket = ReceiptAgentTicket::whereKey($previo->id)->lockForUpdate()->first();
        // La estación también bajo bloqueo, y DESPUÉS del ticket: desactivarla
        // (PATCH, que solo bloquea la estación) espera a que el canje termine o
        // gana y el canje ve `active = false`. Sin esto, un trabajo podía salir
        // en la ventana entre leerla y despachar.
        $station = $ticket ? ReceiptStation::whereKey($ticket->receipt_station_id)->lockForUpdate()->first() : null;

        if ($ticket === null || $station === null || $ticket->revoked_at !== null) {
            throw $this->reject('ticket_invalid');
        }
        if ($ticket->expires_at->lte(now())) {
            throw $this->reject('ticket_expired');
        }
        if ($ticket->redeemed_at !== null) {
            throw $this->reject('ticket_used');
        }
        if (! hash_equals(strtolower($station->station_key), strtolower($data['station_key']))) {
            throw $this->reject('station_mismatch');
        }
        if (! $station->active) {
            throw $this->reject('station_inactive');
        }
        if ((int) $data['agent']['protocol'] < self::minProtocol()) {
            throw $this->reject('protocol_unsupported');
        }
        if (! self::enabled()) {
            throw $this->reject('agent_disabled');
        }

        $agente = $data['agent'];
        // Lo que se guarda de lo que cuenta el servicio, limpio (M-2): ya pasó
        // la validación, esto es la segunda red.
        $version = UntrustedText::sanitize((string) $agente['version'], 32);
        $impresora = UntrustedText::sanitize($agente['printer_name'] ?? null, 120);
        $columnas = (int) $agente['columns'];
        $codepage = $agente['codepage'] ?? $station->codepage;

        [$respuesta, $contenido] = match ($ticket->purpose) {
            ReceiptAgentTicket::PURPOSE_PRINT => $this->printPayload($intento, $station, $columnas),
            ReceiptAgentTicket::PURPOSE_TEST => $this->testPayload($ticket, $station, $columnas, $codepage),
            default => $this->configurePayload($station),
        };

        // Despacho ANTES que el canje: si el intento ya no admite despacho, no
        // se ha escrito nada todavía.
        if ($intento !== null && ! $this->prints->dispatch($intento)) {
            throw $this->reject('attempt_closed');
        }

        // Bajo el bloqueo esto no puede perder una carrera; la condición es la
        // misma red que en el resto del módulo, y si fallara, la excepción
        // deshace también el despacho.
        $canjeado = ReceiptAgentTicket::query()
            ->whereKey($ticket->id)
            ->whereNull('redeemed_at')
            ->whereNull('revoked_at')
            ->update([
                'redeemed_at' => now(),
                'redeemed_ip' => $ip,
                'agent_version' => $version,
                'columns' => $columnas,
                'printer_name' => $impresora,
                'content_sha256' => hash('sha256', $this->canonicalJson($contenido)),
            ]);

        if ($canjeado !== 1) {
            throw $this->reject('ticket_used');
        }

        $this->touchStation($station, $ip, [
            'agent_version' => $version,
            'agent_protocol' => (int) $agente['protocol'],
            'last_printer_name' => $impresora,
        ]);

        return ['ok' => true, 'purpose' => $ticket->purpose, 'ticket_id' => $ticket->uuid] + $respuesta;
    }

    /**
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function printPayload(?ReceiptPrint $intento, ReceiptStation $station, int $columnas): array
    {
        if ($intento === null || $intento->status !== ReceiptPrint::STATUS_STARTED || $intento->dispatched_at !== null) {
            throw $this->reject('attempt_closed');
        }

        $recibo = $intento->receipt()->firstOrFail();
        $job = $this->layout->printJob(
            $recibo,
            $intento,
            $this->prints->copyLabel($intento),
            // Lo VIVO: si se anuló después, el papel lo dice; si ya tiene
            // factura con número, la nombra. El snapshot no cambia.
            $this->presenter->live($recibo),
            $columnas,
            (int) $station->feed_lines,
        );

        return [['job' => $job], $job['lines']];
    }

    /**
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function testPayload(ReceiptAgentTicket $ticket, ReceiptStation $station, int $columnas, string $codepage): array
    {
        $job = $this->layout->testJob(
            $ticket->variant ?? ReceiptAgentTicket::DEFAULT_VARIANT,
            $columnas,
            $codepage,
            $station->name,
            (int) $station->feed_lines,
            // En los recibos de muestra, quien pidió la prueba sale como cajero.
            cashier: $ticket->issued_by_name,
        );

        return [['job' => $job], $job['lines']];
    }

    /**
     * Lo que el administrador configuró para el equipo, tal y como está AHORA:
     * la estación es la única fuente de verdad.
     *
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function configurePayload(ReceiptStation $station): array
    {
        $ajustes = self::settingsOf($station);

        return [['settings' => $ajustes], $ajustes];
    }

    /** @return array{station_name: string, printer_name: string|null, columns: int, codepage: string, feed_lines: int} */
    public static function settingsOf(ReceiptStation $station): array
    {
        return [
            'station_name' => $station->name,
            'printer_name' => $station->printer_name,
            'columns' => (int) $station->columns,
            'codepage' => $station->codepage,
            'feed_lines' => (int) $station->feed_lines,
        ];
    }

    // ── Reportar (§4 report) ────────────────────────────────────────────────

    /**
     * @param  array{ticket: string, station_key: string, outcome: string, error_code?: string|null, message?: string|null, spool_job_id?: int|null, printer_name?: string|null}  $data
     * @return array{error: string}|array{body: array<string, mixed>}
     */
    public function report(array $data, ?string $ip): array
    {
        try {
            $previo = $this->find($data['ticket']);
        } catch (Throwable $e) {
            return $this->unexpected('report', $e, null);
        }
        if ($previo === null) {
            return ['error' => 'ticket_invalid'];
        }

        try {
            return ['body' => DB::transaction(fn (): array => $this->reportLocked($previo, $data, $ip))];
        } catch (ReceiptException $e) {
            return ['error' => $this->rejectionCode($e)];
        } catch (Throwable $e) {
            return $this->unexpected('report', $e, $previo);
        }
    }

    /** @return array<string, mixed> */
    private function reportLocked(ReceiptAgentTicket $previo, array $data, ?string $ip): array
    {
        $intento = $previo->receipt_print_id
            ? ReceiptPrint::whereKey($previo->receipt_print_id)->lockForUpdate()->first()
            : null;
        $ticket = ReceiptAgentTicket::whereKey($previo->id)->lockForUpdate()->first();
        $station = ReceiptStation::find($ticket?->receipt_station_id);

        if ($ticket === null || $station === null || $ticket->revoked_at !== null) {
            throw $this->reject('ticket_invalid');
        }
        if ($ticket->redeemed_at === null) {
            throw $this->reject('not_redeemed');
        }
        if ($ticket->reported_at !== null) {
            throw $this->reject('already_reported');
        }
        $ventana = (int) config('caja.receipts.agent.report_window_hours', 24);
        if ($ticket->redeemed_at->copy()->addHours($ventana)->lt(now())) {
            throw $this->reject('report_window_closed');
        }
        if (! hash_equals(strtolower($station->station_key), strtolower($data['station_key']))) {
            throw $this->reject('station_mismatch');
        }

        // `applied` solo en configure; `error` en los tres (un configure que el
        // servicio no pudo aplicar: impresora inexistente, ajustes inválidos o
        // no pudo guardarlos); `accepted` solo en print y test.
        $esperados = $ticket->purpose === ReceiptAgentTicket::PURPOSE_CONFIGURE
            ? [ReceiptAgentTicket::OUTCOME_APPLIED, ReceiptAgentTicket::OUTCOME_ERROR]
            : [ReceiptAgentTicket::OUTCOME_ACCEPTED, ReceiptAgentTicket::OUTCOME_ERROR];
        if (! in_array($data['outcome'], $esperados, true)) {
            throw $this->reject('invalid_outcome');
        }

        // Texto libre del servicio: se guarda sin controles ni invisibles (bidi,
        // anchura cero) y recortado (M-2). Lo enseñan eventos, pendientes y el
        // informe del turno.
        $mensaje = UntrustedText::sanitize($data['message'] ?? null, 255);
        $codigo = UntrustedText::sanitize($data['error_code'] ?? null, 40);
        $impresora = UntrustedText::sanitize($data['printer_name'] ?? null, 120);
        $yaCerrado = false;

        if ($ticket->purpose === ReceiptAgentTicket::PURPOSE_PRINT && $intento !== null) {
            $cierre = $this->prints->finishFromAgent(
                $intento,
                $data['outcome'] === ReceiptAgentTicket::OUTCOME_ACCEPTED ? ReceiptPrint::STATUS_SENT : ReceiptPrint::STATUS_ERROR,
                // Lo que cuenta el servicio; si solo trae el código, el código.
                $mensaje ?? $codigo,
            );
            $yaCerrado = $cierre['already_finished'];
        }

        $anotado = ReceiptAgentTicket::query()
            ->whereKey($ticket->id)
            ->whereNull('reported_at')
            ->update([
                'reported_at' => now(),
                'outcome' => $data['outcome'],
                'error_code' => $codigo,
                'error_message' => $mensaje,
                'spool_job_id' => $data['spool_job_id'] ?? null,
                'printer_name' => $impresora ?? $ticket->printer_name,
            ]);

        if ($anotado !== 1) {
            throw $this->reject('already_reported');
        }

        $this->touchStation($station, $ip, array_filter([
            'last_printer_name' => $impresora,
        ], fn ($v) => $v !== null));

        $body = ['ok' => true, 'recorded' => true];
        if ($yaCerrado) {
            // El CRM lo cerró antes con el PATCH: no se pisa, solo se anota.
            $body['already_finished'] = true;
        }

        return $body;
    }

    // ── Comunes ─────────────────────────────────────────────────────────────

    /**
     * El ticket por la huella de su token. Un texto que no tiene la forma de un
     * token no llega a la base: no existe, y se dice igual que si no existiera.
     */
    private function find(string $token): ?ReceiptAgentTicket
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        return ReceiptAgentTicket::where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Un fallo que no es un rechazo: la base (pérdida de conexión, un
     * interbloqueo, bytes que PostgreSQL no acepta) o un defecto. La
     * transacción ya se deshizo; el servicio recibe `internal_error` y el log
     * guarda lo justo para investigar: NI el token NI el SQL con sus valores
     * (el mensaje de una QueryException lleva la consulta y sus bindings).
     *
     * @return array{error: string}
     */
    private function unexpected(string $operacion, Throwable $e, ?ReceiptAgentTicket $ticket): array
    {
        Log::error('receipt_agent:internal_error', array_filter([
            'operation' => $operacion,
            // El identificador PÚBLICO del ticket, nunca el token.
            'ticket_id' => $ticket?->uuid,
            'exception' => $e::class,
            'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? (string) $e->getCode()) : null,
            'message' => $e instanceof QueryException ? null : mb_substr($e->getMessage(), 0, 200),
            'at' => basename($e->getFile()).':'.$e->getLine(),
        ], fn ($v) => $v !== null && $v !== ''));

        return ['error' => 'internal_error'];
    }

    private function reject(string $code): ReceiptException
    {
        return new ReceiptException('Rechazo del servicio de impresión: '.$code.'.', $code);
    }

    /**
     * El código que ve el servicio. Un fallo de maquetación es un defecto
     * nuestro; un recibo que no cabe en 400 líneas, una limitación: los dos
     * dejan el ticket sin consumir.
     */
    private function rejectionCode(ReceiptException $e): string
    {
        return match ($e->code_) {
            'job_too_long' => 'job_too_long',
            'layout_invalid', 'receipt_error' => 'layout_error',
            default => $e->code_,
        };
    }

    /** @param  array<string, mixed>  $extra */
    private function touchStation(ReceiptStation $station, ?string $ip, array $extra): void
    {
        ReceiptStation::query()->whereKey($station->id)->update(array_merge([
            'last_seen_at' => now(),
            'last_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ], $extra));
    }

    private function ttl(string $purpose): int
    {
        return (int) config('caja.receipts.agent.ticket_ttl.'.$purpose, 120);
    }

    /** JSON estable de lo entregado: misma entrada, misma huella. */
    private function canonicalJson(mixed $contenido): string
    {
        return (string) json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
