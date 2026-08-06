<?php

namespace App\Services\Commercial\Tools;

use App\Models\CommercialToolInvocation;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * El único camino por el que se ejecuta una herramienta.
 *
 * Todas las barreras viven aquí y no en cada herramienta, porque una barrera
 * que hay que recordar poner es una barrera que algún día falta. El orden es
 * deliberado: lo que rechaza más barato va primero, y nada toca la red hasta
 * que la fila de auditoría existe.
 *
 *   1. La herramienta existe (registro cerrado: el nombre nunca es texto libre).
 *   2. Su flag está encendido.
 *   3. Los argumentos validan contra las reglas.
 *   4. No hay campos de más —el intento de colar `amount` muere aquí—.
 *   5. Hay autorización, y aprobación humana si la herramienta la exige.
 *   6. Se reclama la clave de idempotencia creando la fila.
 *   7. Se ejecuta con tiempo máximo.
 *   8. Se cierra el acta con el resultado.
 *
 * El paso 6 antes del 7 es lo que hace que un reintento no duplique nada: la
 * clave se reclama en la base ANTES de salir a la red, así que un segundo
 * intento se encuentra el trabajo tomado incluso si el primero sigue en vuelo.
 */
class ToolExecutor
{
    public function __construct(private readonly ToolRegistry $registry) {}

    public function execute(string $toolName, array $arguments, ToolContext $context): ToolResult
    {
        $tool = $this->registry->find($toolName);

        if ($tool === null) {
            // No se audita: no hay herramienta a la que atribuirlo. Un nombre
            // desconocido es un error de programación o un intento de inyección,
            // y en los dos casos lo que importa es el log.
            ChannelLog::warning('commercial.tool.unknown', ['tool' => $toolName]);

            return ToolResult::rejected('unknown_tool', "La herramienta {$toolName} no existe.");
        }

        if (! $this->flagEnabled($tool)) {
            return ToolResult::rejected(
                'tool_disabled',
                "La herramienta {$toolName} está desactivada por configuración.",
            );
        }

        $validation = $this->validate($tool, $arguments);

        if ($validation instanceof ToolResult) {
            $this->auditRejection($tool, $arguments, $context, $validation);

            return $validation;
        }

        if ($denial = $this->authorize($tool, $context)) {
            $this->auditRejection($tool, $validation, $context, $denial);

            return $denial;
        }

        return $this->runAudited($tool, $validation, $context);
    }

    /**
     * Valida y devuelve SOLO los argumentos declarados.
     *
     * El filtrado importa tanto como la validación. Las reglas de Laravel
     * ignoran las claves que no mencionan, así que sin quedarse únicamente con
     * lo validado, un `amount` inventado llegaría intacto a la implementación.
     *
     * @return array<string,mixed>|ToolResult
     */
    private function validate(CommercialTool $tool, array $arguments): array|ToolResult
    {
        $rules = $tool->rules();

        // Campos de más. Se rechaza en lugar de ignorarlos en silencio: que el
        // modelo intente fijar un precio es información que hay que ver.
        $allowed = array_map(
            fn (string $key) => Str::before($key, '.'),
            array_keys($rules),
        );
        $extra = array_diff(array_keys($arguments), array_unique($allowed));

        if ($extra !== []) {
            ChannelLog::warning('commercial.tool.unexpected_arguments', [
                'tool' => $tool->name(),
                'unexpected' => array_values($extra),
            ]);

            return ToolResult::rejected(
                'unexpected_arguments',
                'Argumentos no permitidos: '.implode(', ', $extra),
            );
        }

        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            return ToolResult::rejected(
                'invalid_arguments',
                implode(' ', $validator->errors()->all()),
            );
        }

        return $validator->validated();
    }

    private function authorize(CommercialTool $tool, ToolContext $context): ?ToolResult
    {
        // Lo que no tiene vuelta atrás cómoda no lo decide una máquina sola.
        if ($tool->requiresHumanApproval() && ! $context->isHumanApproved()) {
            return ToolResult::rejected(
                'human_approval_required',
                "La herramienta {$tool->name()} exige que una persona la apruebe.",
            );
        }

        // Escribir exige que la autonomía esté encendida. Leer no: consultar el
        // catálogo de planes es inofensivo y hace falta para poder recomendar.
        // Ceder la conversación a una persona tampoco: ver requiresAutonomy().
        if ($tool->requiresAutonomy()
            && $context->requestedBy === 'engine'
            && ! (bool) config('commercial.autonomy_enabled', false)) {
            return ToolResult::rejected(
                'autonomy_disabled',
                'El motor no tiene permitido ejecutar acciones todavía.',
            );
        }

        return null;
    }

    /**
     * Reclama la clave, ejecuta y cierra el acta.
     */
    private function runAudited(CommercialTool $tool, array $arguments, ToolContext $context): ToolResult
    {
        $key = $context->idempotencyKey
            ?? $tool->name().':'.Str::uuid()->toString();

        try {
            $invocation = CommercialToolInvocation::create([
                'uuid' => Str::uuid()->toString(),
                'tool' => $tool->name(),
                'idempotency_key' => $key,
                'marketing_lead_id' => $context->leadId(),
                'member_id' => $context->memberId(),
                'commercial_opportunity_id' => $context->opportunity?->id,
                'marketing_conversation_id' => $context->conversation?->id,
                'requested_by' => $context->requestedBy,
                'approved_by_admin_id' => $context->approvedBy?->id,
                'goal' => $context->opportunity?->goal,
                'decision_action' => $context->opportunity?->next_action,
                'reason' => $context->opportunity?->reason,
                'arguments' => $arguments,
                'status' => CommercialToolInvocation::STATUS_RUNNING,
                'attempts' => 1,
                'correlation_id' => $context->correlationId,
                'started_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Alguien ya reclamó esta intención. Se devuelve lo que aquella
            // ejecución consiguió en lugar de repetir el efecto.
            return $this->resultOfExisting($key, $tool);
        }

        $started = microtime(true);

        try {
            $result = $this->withTimeout(
                $tool->timeoutSeconds(),
                fn () => $tool->execute($arguments, $context),
            );
        } catch (\Throwable $e) {
            $result = ToolResult::failed(
                'tool_exception',
                class_basename($e).': '.$e->getMessage(),
                // Una excepción inesperada se marca reintentable: casi siempre
                // es red o un servicio caído, y lo contrario condenaría una
                // venta por un fallo pasajero.
                retryable: true,
            );

            ChannelLog::error('commercial.tool.exception', [
                'tool' => $tool->name(),
                'invocation_id' => $invocation->id,
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);
        }

        $invocation->forceFill([
            'status' => $result->status,
            'result' => $result->data ?: null,
            'error_code' => $result->errorCode,
            'error_message' => $result->message,
            'retryable' => $result->retryable,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'finished_at' => now(),
        ])->save();

        ChannelLog::info('commercial.tool.executed', [
            'tool' => $tool->name(),
            'invocation_id' => $invocation->id,
            'status' => $result->status,
            'error_code' => $result->errorCode,
            'duration_ms' => $invocation->duration_ms,
        ]);

        return $result;
    }

    /**
     * Qué devolver cuando la intención ya estaba reclamada.
     *
     * Si la primera ejecución terminó bien, se devuelve su resultado: para quien
     * llama es como si hubiera funcionado, que es exactamente lo que se quiere
     * de un reintento. Si sigue en vuelo, se dice eso y no se toca nada.
     */
    private function resultOfExisting(string $key, CommercialTool $tool): ToolResult
    {
        $existing = CommercialToolInvocation::query()
            ->where('idempotency_key', $key)
            ->first();

        if ($existing === null) {
            return ToolResult::failed('idempotency_conflict', 'Intención duplicada.', retryable: true);
        }

        ChannelLog::info('commercial.tool.deduplicated', [
            'tool' => $tool->name(),
            'invocation_id' => $existing->id,
            'status' => $existing->status,
        ]);

        return match ($existing->status) {
            CommercialToolInvocation::STATUS_SUCCEEDED,
            CommercialToolInvocation::STATUS_SKIPPED => ToolResult::skipped(
                'Esta acción ya se había ejecutado.',
                (array) ($existing->result ?? []),
            ),

            CommercialToolInvocation::STATUS_RUNNING,
            CommercialToolInvocation::STATUS_PENDING => ToolResult::skipped(
                'Esta acción ya está en curso.',
                ['invocation_uuid' => $existing->uuid],
            ),

            default => ToolResult::failed(
                (string) ($existing->error_code ?? 'previous_attempt_failed'),
                (string) ($existing->error_message ?? 'El intento anterior falló.'),
                retryable: (bool) $existing->retryable,
            ),
        };
    }

    /**
     * Tiempo máximo de ejecución.
     *
     * PHP no puede interrumpir una llamada en curso de forma segura, así que
     * esto NO aborta a mitad: el límite real de una llamada HTTP lo pone el
     * timeout del cliente dentro de cada servicio. Lo que se hace aquí es
     * medir y dejar constancia cuando algo tarda más de lo declarado, que es lo
     * que permite detectar una herramienta que se está degradando antes de que
     * alguien se queje.
     */
    private function withTimeout(int $seconds, callable $work): ToolResult
    {
        $started = microtime(true);

        $result = $work();

        $elapsed = microtime(true) - $started;

        if ($elapsed > $seconds) {
            ChannelLog::warning('commercial.tool.slow', [
                'elapsed_seconds' => round($elapsed, 2),
                'budget_seconds' => $seconds,
            ]);
        }

        return $result;
    }

    private function auditRejection(
        CommercialTool $tool,
        array $arguments,
        ToolContext $context,
        ToolResult $result,
    ): void {
        try {
            CommercialToolInvocation::create([
                'uuid' => Str::uuid()->toString(),
                'tool' => $tool->name(),
                // Clave propia: un rechazo no consume la intención, para que se
                // pueda reintentar con los argumentos corregidos.
                'idempotency_key' => 'rejected:'.Str::uuid()->toString(),
                'marketing_lead_id' => $context->leadId(),
                'member_id' => $context->memberId(),
                'commercial_opportunity_id' => $context->opportunity?->id,
                'requested_by' => $context->requestedBy,
                'arguments' => $arguments,
                'status' => CommercialToolInvocation::STATUS_REJECTED,
                'error_code' => $result->errorCode,
                'error_message' => $result->message,
                'correlation_id' => $context->correlationId,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            ChannelLog::warning('commercial.tool.audit_failed', [
                'tool' => $tool->name(),
                'exception' => class_basename($e),
            ]);
        }
    }

    private function flagEnabled(CommercialTool $tool): bool
    {
        $flag = $tool->featureFlag();

        return $flag === null || (bool) config($flag, false);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true);
    }
}
