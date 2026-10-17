<?php

namespace App\Services\Caja;

use App\Models\Admin;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Services\Audit\AuditTrail;
use App\Support\Access\CrmPermission;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El rastro de cada tirilla: quién la mandó a la impresora, si salió, y quién
 * decidió no imprimirla.
 *
 * EL SERVIDOR DECIDE QUÉ COPIA ES. El navegador solo dice desde dónde imprime
 * (`context`); si pudiera decir «esto es el original», bastaría para tapar que
 * ya se imprimió otro. La regla:
 *
 *  · checkout sin original previo, a tiempo (late_after_minutes) y con la
 *    operación viva                       → original (sin rótulo)
 *  · checkout tardío o con la operación anulada → reprint («REIMPRESIÓN»)
 *  · checkout con original previo         → retry («REINTENTO N»)
 *  · shift_report o pending               → reprint («REIMPRESIÓN»)
 *
 * APPEND-ONLY. Un intento nace en `started` y se cierra UNA vez, a `sent` o
 * `error`, y solo lo cierra quien lo abrió. El cierre es un UPDATE condicional
 * (`WHERE status = 'started'`): con dos peticiones a la vez, una cambia la fila
 * y la otra recibe 0 filas afectadas, en vez de pisarse.
 *
 * OMITIR LA TIRILLA es una excepción, no una opción del mostrador. Exige
 * `receipts.print_optional`; sin él, el intento se REGISTRA como `denied` y se
 * audita ANTES de responder 403. Que alguien intente saltarse el recibo es
 * justo lo que hay que poder ver después.
 */
final class ReceiptPrints
{
    public const PERMISSION_OPTIONAL = CrmPermission::RECEIPTS_PRINT_OPTIONAL;

    private const MAX_RECENT = 20;

    /** Estados de la operación con los que ya no sale un «original». */
    private const ANNULLED_STATUSES = ['cancelled', 'canceled', 'voided', 'void', 'refunded', 'reversed', 'partially_reversed'];

    /**
     * Registra un intento de impresión. Idempotente por `client_attempt_id`.
     *
     * @return array{print: ReceiptPrint, created: bool}|array{conflict: string}
     */
    public function start(Receipt $recibo, string $attemptId, string $context, string $printMode, Admin $actor, Request $request): array
    {
        if ($previo = ReceiptPrint::where('uuid', $attemptId)->first()) {
            return $this->replayOrConflict($recibo, $previo, $actor, expectSkip: false);
        }

        $intentar = function (bool $forzarReintento) use ($recibo, $attemptId, $context, $printMode, $actor, $request): array {
            return DB::transaction(function () use ($recibo, $attemptId, $context, $printMode, $actor, $request, $forzarReintento): array {
                // Serializa las decisiones sobre ESTE recibo: dos pestañas que
                // imprimen a la vez no pueden ver las dos «sin original».
                Receipt::whereKey($recibo->id)->lockForUpdate()->first();

                if ($previo = ReceiptPrint::where('uuid', $attemptId)->first()) {
                    return $this->replayOrConflict($recibo, $previo, $actor, expectSkip: false);
                }

                $tipo = $this->kindFor($recibo, $context, $forzarReintento);

                $intento = ReceiptPrint::create($this->rowFor($recibo, $attemptId, $actor, $request) + [
                    'kind' => $tipo,
                    'status' => ReceiptPrint::STATUS_STARTED,
                    'context' => $context,
                    'print_mode' => $printMode,
                ]);

                return ['print' => $intento, 'created' => true];
            });
        };

        try {
            return $intentar(false);
        } catch (QueryException $e) {
            // Un índice único saltó entre la comprobación y el INSERT: o el mismo
            // intento llegó dos veces, o dos «originales» a la vez. Lo primero se
            // devuelve tal cual; lo segundo se registra como reintento, que es
            // lo que es.
            if ($previo = ReceiptPrint::where('uuid', $attemptId)->first()) {
                return $this->replayOrConflict($recibo, $previo, $actor, expectSkip: false);
            }

            if ($context === 'checkout') {
                return $intentar(true);
            }

            throw $e;
        }
    }

    /**
     * Cierra un intento abierto. Una vez, desde `started`, y solo quien lo abrió.
     *
     * @return array{print: ReceiptPrint}|array{error: string, status?: string}
     */
    public function finish(Receipt $recibo, string $attemptId, string $status, ?string $errorMessage, Admin $actor): array
    {
        $intento = ReceiptPrint::where('uuid', $attemptId)->where('receipt_id', $recibo->id)->first();

        if ($intento === null) {
            return ['error' => 'not_found'];
        }

        if (! $intento->isPrintAttempt()) {
            return ['error' => 'not_a_print_attempt', 'status' => $intento->status];
        }

        if ((int) $intento->actor_admin_id !== (int) $actor->id) {
            return ['error' => 'forbidden'];
        }

        if ($intento->status !== ReceiptPrint::STATUS_STARTED) {
            return ['error' => 'already_finished', 'status' => $intento->status];
        }

        // UPDATE condicional: es lo que hace que «una sola vez» sea verdad con
        // dos peticiones simultáneas, y no solo con una detrás de otra.
        $afectadas = ReceiptPrint::query()
            ->whereKey($intento->id)
            ->where('status', ReceiptPrint::STATUS_STARTED)
            ->update([
                'status' => $status,
                'error_message' => $status === ReceiptPrint::STATUS_ERROR && $errorMessage !== null
                    ? mb_substr($errorMessage, 0, 255)
                    : null,
                'finished_at' => now(),
            ]);

        if ($afectadas === 0) {
            return ['error' => 'already_finished', 'status' => (string) $intento->fresh()?->status];
        }

        return ['print' => $intento->fresh()];
    }

    /**
     * Omitir la impresión. Con permiso queda `skipped`; sin él, `denied` y
     * auditado. En los dos casos queda constancia de quién y cuándo.
     *
     * @return array{print: ReceiptPrint, created: bool}|array{conflict: string}|array{already_printed: true}
     */
    public function skip(Receipt $recibo, string $attemptId, ?string $reason, string $context, Admin $actor, Request $request): array
    {
        if ($previo = ReceiptPrint::where('uuid', $attemptId)->first()) {
            return $this->replayOrConflict($recibo, $previo, $actor, expectSkip: true);
        }

        if ($recibo->prints()->where('status', ReceiptPrint::STATUS_SENT)->exists()) {
            return ['already_printed' => true];
        }

        $permitido = CrmPermission::allows($actor, self::PERMISSION_OPTIONAL);

        try {
            return DB::transaction(function () use ($recibo, $attemptId, $reason, $context, $actor, $request, $permitido): array {
                $intento = ReceiptPrint::create($this->rowFor($recibo, $attemptId, $actor, $request) + [
                    'kind' => ReceiptPrint::KIND_SKIP,
                    'status' => $permitido ? ReceiptPrint::STATUS_SKIPPED : ReceiptPrint::STATUS_DENIED,
                    'context' => $context,
                    'print_mode' => 'unknown',
                    'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                    'finished_at' => now(),
                ]);

                // En la MISMA transacción que la fila: una omisión —o un intento
                // de omitir sin permiso— sin traza sería justo lo que no se puede
                // permitir. AuditTrail no se traga el fallo a propósito.
                app(AuditTrail::class)->record($request, [
                    'action' => $permitido ? 'update' : 'denied',
                    'module' => 'Caja',
                    'entity' => 'recibo',
                    'entity_id' => $recibo->number,
                    'target_name' => 'Recibo '.$recibo->number,
                    'summary' => $permitido
                        ? 'Omitió la impresión del recibo '.$recibo->number
                        : 'Intentó omitir la impresión del recibo '.$recibo->number.' sin permiso',
                    'metadata' => [
                        'receipt_uuid' => $recibo->uuid,
                        'client_attempt_id' => $attemptId,
                        'status' => $intento->status,
                        'context' => $context,
                        'reason' => $intento->reason,
                        'required_permission' => self::PERMISSION_OPTIONAL,
                    ],
                ]);

                return ['print' => $intento, 'created' => true];
            });
        } catch (QueryException $e) {
            if ($previo = ReceiptPrint::where('uuid', $attemptId)->first()) {
                return $this->replayOrConflict($recibo, $previo, $actor, expectSkip: true);
            }

            throw $e;
        }
    }

    /**
     * El rótulo de la copia, derivado de la fila: la misma respuesta hoy y en
     * cualquier repetición, sin guardarlo.
     */
    public function copyLabel(ReceiptPrint $intento): ?string
    {
        return match ($intento->kind) {
            ReceiptPrint::KIND_RETRY => 'REINTENTO '.ReceiptPrint::query()
                ->where('receipt_id', $intento->receipt_id)
                ->where('kind', ReceiptPrint::KIND_RETRY)
                ->where('id', '<=', $intento->id)
                ->count(),
            ReceiptPrint::KIND_REPRINT => 'REIMPRESIÓN',
            default => null,
        };
    }

    /** Los últimos intentos, del más reciente al más antiguo. */
    public function recent(Receipt $recibo): array
    {
        return $recibo->prints()
            ->orderByDesc('id')
            ->limit(self::MAX_RECENT)
            ->get()
            ->map(fn (ReceiptPrint $p) => [
                'client_attempt_id' => $p->uuid,
                'kind' => $p->kind,
                'status' => $p->status,
                'context' => $p->context,
                'actor_name' => $p->actor_name,
                'started_at' => optional($p->started_at)->toIso8601String(),
                'finished_at' => optional($p->finished_at)->toIso8601String(),
            ])
            ->all();
    }

    private function kindFor(Receipt $recibo, string $context, bool $forzarReintento): string
    {
        if ($context !== 'checkout') {
            return ReceiptPrint::KIND_REPRINT;
        }

        if ($forzarReintento) {
            return ReceiptPrint::KIND_RETRY;
        }

        $hayOriginal = ReceiptPrint::query()
            ->where('receipt_id', $recibo->id)
            ->where('kind', ReceiptPrint::KIND_ORIGINAL)
            ->exists();

        if ($hayOriginal) {
            return ReceiptPrint::KIND_RETRY;
        }

        // El ORIGINAL es la tirilla del momento del cobro. Días después, o con
        // la operación ya anulada, lo que sale es una copia y se rotula como tal:
        // si no, un «checkout» tardío daría un papel sin marca de algo anulado.
        $aTiempo = $recibo->operation_at === null
            || $recibo->operation_at->diffInMinutes(now(), false) <= (int) config('caja.receipts.late_after_minutes', 10);
        $estado = app(ReceiptPresenter::class)->live($recibo)['operation_status'] ?? null;
        $anulada = in_array($estado, self::ANNULLED_STATUSES, true);

        return $aTiempo && ! $anulada ? ReceiptPrint::KIND_ORIGINAL : ReceiptPrint::KIND_REPRINT;
    }

    /**
     * Una repetición del mismo `client_attempt_id` devuelve lo que se hizo; un
     * identificador que ya se usó para otra cosa (otro recibo, otro tipo de
     * acción) es un conflicto, no una repetición.
     *
     * @return array{print: ReceiptPrint, created: bool}|array{conflict: string}
     */
    private function replayOrConflict(Receipt $recibo, ReceiptPrint $previo, Admin $actor, bool $expectSkip): array
    {
        $otroRecibo = (int) $previo->receipt_id !== (int) $recibo->id;
        $otraAccion = $previo->isPrintAttempt() === $expectSkip;
        // El id de un intento se ve en GET /receipts/{uuid}: reenviar el de OTRA
        // persona (p. ej. la omisión de un Super Admin) no puede devolver su
        // resultado como propio sin dejar rastro.
        $otraPersona = (int) $previo->actor_admin_id !== (int) $actor->getKey();

        if ($otroRecibo || $otraAccion || $otraPersona) {
            return ['conflict' => 'attempt_conflict'];
        }

        return ['print' => $previo, 'created' => false];
    }

    /** @return array<string, mixed> */
    private function rowFor(Receipt $recibo, string $attemptId, Admin $actor, Request $request): array
    {
        return [
            'uuid' => $attemptId,
            'receipt_id' => $recibo->id,
            'actor_admin_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'started_at' => now(),
        ];
    }
}
