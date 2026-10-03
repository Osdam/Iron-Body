<?php

namespace App\Services\Marketing;

use App\Models\MarketingAgentSetting;
use App\Models\MarketingMessage;
use App\Services\Audit\AuditTrail;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Observability\ChannelLog;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El interruptor global del agente IA: ACTIVO o PAUSADO.
 *
 * PAUSADO significa una sola cosa: nada AUTOMÁTICO le contesta a nadie. El
 * webhook de Meta sigue validando, deduplicando y guardando cada mensaje igual
 * que siempre —eso ocurre antes de cualquier decisión—, y la conversación queda
 * para que la atienda una persona. Lo que se detiene:
 *
 *  - ULTRON no recibe turnos nuevos (emisor y job hacia n8n) y un turno que ya
 *    estaba en vuelo no se confirma (`UltronDecideService::ineligibleReason`,
 *    que usan el decide, el commit y el vigía);
 *  - el cerebro local no ejecuta nada por su cuenta;
 *  - ningún saliente del agente (`sender_type = ai`) llega a Meta: ni el nuevo
 *    (despachador) ni el que esperaba su reintento (outbox).
 *
 * Lo que NO se detiene: lo que escribe una persona desde el Inbox, y los dos
 * avisos de pago que redacta Laravel ({@see self::TRANSACTIONAL_KINDS}), que no
 * son respuestas del agente sino la confirmación de un cobro.
 *
 * Solo apaga, igual que el freno de ULTRON: reactivar aquí nunca enciende lo
 * que el entorno (`MARKETING_ULTRON_ENABLED`, el canario) o el freno apagan.
 *
 * Se lee SIEMPRE de la base, sin caché: un worker que lleva horas vivo tiene
 * que ver la pausa en el turno siguiente, no tras un `queue:restart`.
 */
class MarketingAgentSwitch
{
    /** La fila única, sembrada por la migración. */
    public const ROW_ID = 1;

    /**
     * Salientes con remitente `ai` que NO son respuestas del agente: la
     * confirmación de un pago aprobado y el aviso de reclamo de pago. Los
     * redacta Laravel a partir de un cobro real y siguen saliendo con el agente
     * pausado: detenerlos sería tocar el circuito de pagos, y el primero ni
     * siquiera se reintenta después.
     */
    public const TRANSACTIONAL_KINDS = ['onboarding', 'claim_notice'];

    /** Motivo que dejan las puertas cuando la pausa las cierra. */
    public const REASON = 'agent_paused';

    /**
     * La fila tal como está ahora en la base, o null si la tabla aún no existe.
     *
     * Solo una tabla INEXISTENTE —despliegue a medias, migración sin correr—
     * se trata como «activo», que es el estado que tenía todo antes de que el
     * interruptor existiera, y se deja constancia ruidosa. Cualquier otro error
     * de base se propaga y la puerta queda CERRADA: nunca se da por buena una
     * respuesta automática sin saber si se pausó. En los pasos encolados (job
     * hacia n8n, outbox) ese error hace que el paso se reintente; en el emisor
     * de entrada el router lo absorbe por diseño (emit es best-effort), así
     * que ese turno se queda sin ULTRON, para atención humana, y solo deja
     * `ultron.event.emit_failed` en el log.
     */
    public function current(): ?MarketingAgentSetting
    {
        try {
            return MarketingAgentSetting::query()->find(self::ROW_ID);
        } catch (QueryException $e) {
            if (! $this->tablaInexistente($e)) {
                throw $e;
            }

            ChannelLog::warning('marketing.agent.switch_unavailable', ['error_class' => class_basename($e)]);

            return null;
        }
    }

    public function isPaused(): bool
    {
        return (bool) $this->current()?->paused;
    }

    /**
     * ¿Estuvo pausado en ALGÚN momento desde `$desde` hasta ahora?
     *
     * Es la regla de los trabajos YA INICIADOS. Un turno que empezó antes de
     * una pausa —o durante una pausa ya terminada— no sale nunca, aunque cuando
     * por fin llegue su respuesta el agente vuelva a estar activo: durante la
     * pausa pudo contestar una persona, y la respuesta tardía de la máquina
     * sería una segunda respuesta a una conversación que ya siguió.
     *
     * Basta con la ÚLTIMA pausa: si alguna anterior tocó el intervalo
     * [`$desde`, ahora], terminó después de `$desde`, así que la última empezó
     * después también. Por eso cuentan su inicio y su final.
     */
    public function pausedSince(?CarbonInterface $desde): bool
    {
        $fila = $this->current();
        if ($fila === null) {
            return false;
        }
        if ($fila->paused) {
            return true;
        }
        if ($desde === null) {
            return false;
        }

        return ($fila->paused_at !== null && $fila->paused_at->greaterThanOrEqualTo($desde))
            || ($fila->resumed_at !== null && $fila->resumed_at->greaterThanOrEqualTo($desde));
    }

    /**
     * ¿La pausa retiene este saliente?
     *
     * Solo los del agente (`ai`) y nunca los avisos de pago. `$creado` es
     * cuándo nació el mensaje: con él, un saliente redactado antes de una
     * pausa tampoco sale al reanudar.
     *
     * @param  array<string,mixed>|null  $metadata
     */
    public function holdsOutbound(string $senderType, ?array $metadata, ?CarbonInterface $creado = null): bool
    {
        if ($senderType !== MarketingMessage::SENDER_AI) {
            return false;
        }
        if (in_array($metadata['kind'] ?? null, self::TRANSACTIONAL_KINDS, true)) {
            return false;
        }

        return $creado !== null ? $this->pausedSince($creado) : $this->isPaused();
    }

    /**
     * Fija el estado. Recibe el estado OBJETIVO, nunca un «cambiar»: pulsar dos
     * veces, reintentar la petición o que dos personas pidan lo mismo a la vez
     * deja una sola transición, una sola traza y una sola versión nueva.
     *
     * Dos peticiones OPUESTAS a la vez se ordenan con el bloqueo de la fila: la
     * segunda ve el estado que dejó la primera, y las dos quedan auditadas con
     * su antes y su después. El estado final es el de la última.
     *
     * La traza va en la MISMA transacción: si no se puede escribir, el cambio
     * no se confirma.
     *
     * @return array{changed:bool, setting:MarketingAgentSetting}
     */
    public function set(bool $paused, Request $request): array
    {
        return DB::transaction(function () use ($paused, $request): array {
            $fila = MarketingAgentSetting::query()->lockForUpdate()->find(self::ROW_ID)
                ?? MarketingAgentSetting::query()->create(['id' => self::ROW_ID, 'paused' => false, 'version' => 0]);

            $antes = (bool) $fila->paused;
            if ($antes === $paused) {
                return ['changed' => false, 'setting' => $fila];
            }

            $actor = AdminActor::from($request);
            $ahora = now();

            $fila->forceFill([
                'paused' => $paused,
                $paused ? 'paused_at' : 'resumed_at' => $ahora,
                'changed_by_admin_id' => $actor?->id,
                'changed_by_name' => $actor?->name,
                'changed_at' => $ahora,
                'version' => (int) $fila->version + 1,
            ])->save();

            app(AuditTrail::class)->record($request, [
                'action' => 'settings',
                'module' => 'Mercadeo',
                'entity' => 'agente_ia',
                'entity_id' => self::ROW_ID,
                'target_name' => 'Agente IA',
                'summary' => $paused ? 'Pausó el agente IA' : 'Reactivó el agente IA',
                'changes' => [[
                    'field' => 'estado',
                    'from' => $antes ? 'pausado' : 'activo',
                    'to' => $paused ? 'pausado' : 'activo',
                ]],
                'metadata' => [
                    'event' => 'marketing.agent.state_changed',
                    'version' => (int) $fila->version,
                ],
            ]);

            ChannelLog::warning($paused ? 'marketing.agent.paused' : 'marketing.agent.resumed', [
                'by_admin_id' => $actor?->id,
                'version' => (int) $fila->version,
            ]);

            return ['changed' => true, 'setting' => $fila];
        });
    }

    /**
     * Lo que ve el CRM. `ultron` cuenta el alcance REAL, para que «activo» no
     * prometa lo que el entorno o el freno no dejan hacer.
     *
     * @return array<string,mixed>
     */
    public function present(Request $request): array
    {
        $fila = $this->current();
        $actor = AdminActor::from($request);
        $paused = (bool) $fila?->paused;

        return [
            'paused' => $paused,
            'status' => $paused ? 'paused' : 'active',
            'version' => (int) ($fila?->version ?? 0),
            'changed_at' => $fila?->changed_at?->toIso8601String(),
            'changed_by_name' => $fila?->changed_by_name,
            'can_manage' => $actor !== null
                && in_array('marketing.agent.manage', CrmPermission::forAdmin($actor), true),
            'ultron' => [
                'enabled' => (bool) config('marketing.ultron.enabled', false),
                'scope' => config('marketing.ultron.canary_conversation_id') !== null ? 'canary' : 'all',
                'brake_engaged' => app(UltronAbortLatch::class)->engaged(),
            ],
        ];
    }

    /**
     * Lo que viaja por el canal SSE: solo qué cambió, sin nombres ni fechas.
     * Quien escucha relee el estado completo por el GET.
     *
     * @return array{version:int, paused:bool}
     */
    public function streamEvent(): array
    {
        $fila = $this->current();

        return ['version' => (int) ($fila?->version ?? 0), 'paused' => (bool) $fila?->paused];
    }

    /** PostgreSQL 42P01, MySQL 42S02 y SQLite «no such table». */
    private function tablaInexistente(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['42P01', '42S02'], true)
            || str_contains($e->getMessage(), 'no such table');
    }
}
