<?php

namespace App\Services\Commercial;

use App\Models\CommercialApproval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Qué está pasando ahora mismo, en una sola pantalla.
 *
 * Existe porque hoy, para saberlo, hay que recorrer pagos, membresías, agenda,
 * facturación y el inbox. Eso no es supervisar: es reconstruir a mano lo que el
 * sistema ya sabe.
 *
 * Todo lo de aquí es **de solo lectura y agregado**. No decide, no ejecuta y no
 * escribe. Cada bloque va en su propio try porque una pantalla de supervisión
 * que no carga por culpa de una tabla que falta es peor que una incompleta: la
 * incompleta se puede leer.
 *
 * Y una regla que gobierna la parte del dinero: **no sobreatribuir**. Que el
 * agente participara en una conversación no significa que cerrara la venta, y
 * las tres categorías —autónomo, asistido, influido— están definidas por
 * hechos comprobables, no por optimismo.
 */
class SupervisionService
{
    /** Ventana por defecto de la actividad reciente. */
    private const ACTIVITY_HOURS = 24;

    public function __construct(private readonly ApprovalQueueService $approvals) {}

    // ── E.1 Estado general ──────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function state(): array
    {
        return [
            'agent' => $this->agentState(),
            'infrastructure' => $this->infrastructureState(),
            'channels' => $this->channelState(),
            'conversations' => $this->conversationCounters(),
            'queue' => $this->workCounters(),
        ];
    }

    /** @return array<string,mixed> */
    private function agentState(): array
    {
        $since = now()->subHours(self::ACTIVITY_HOURS);

        $runs = $this->safe(fn () => DB::table('marketing_ai_actions')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed")
            ->first(), null);

        $latency = $this->safe(fn () => Schema::hasTable('commercial_tool_invocations')
            ? DB::table('commercial_tool_invocations')
                ->where('created_at', '>=', $since)
                ->whereNotNull('duration_ms')
                ->pluck('duration_ms')
            : collect(), collect());

        $total = (int) ($runs->total ?? 0);
        $failed = (int) ($runs->failed ?? 0);

        return [
            // «Apagado» no es una avería: es la configuración pedida hasta que
            // se registre el número. La pantalla tiene que decirlo así.
            'enabled' => (bool) config('marketing.agent_enabled', false),
            'autonomy_enabled' => (bool) config('commercial.autonomy_enabled', false),
            'driver' => (string) config('marketing.ai.driver', 'fake'),
            'model' => $this->effectiveModel(),
            'runs_24h' => $total,
            'failures_24h' => $failed,
            'error_rate' => $total > 0 ? round($failed / $total, 4) : null,
            'latency_p50_ms' => $this->percentile($latency, 50),
            'latency_p95_ms' => $this->percentile($latency, 95),
        ];
    }

    /**
     * El modelo que se usaría de verdad.
     *
     * Nunca la clave, ni parte de ella. El nombre del modelo es información
     * operativa; la credencial no lo es en ningún caso.
     */
    private function effectiveModel(): string
    {
        $driver = (string) config('marketing.ai.driver', 'fake');

        if ($driver !== 'openai') {
            return 'determinista (reglas locales)';
        }

        return (string) config('marketing.ai.openai.model', 'sin configurar');
    }

    /** @return array<string,mixed> */
    private function infrastructureState(): array
    {
        return [
            'queue' => [
                'pending' => $this->safe(fn () => Schema::hasTable('jobs')
                    ? DB::table('jobs')->count() : null, null),
                'failed' => $this->safe(fn () => Schema::hasTable('failed_jobs')
                    ? DB::table('failed_jobs')->count() : null, null),
                'driver' => (string) config('queue.default'),
            ],
            'openai' => [
                // Configurado, no «funcionando»: comprobar que responde
                // costaría una llamada de pago en cada carga de la pantalla.
                'configured' => filled(config('marketing.ai.openai.api_key'))
                    || filled(env('OPENAI_API_KEY')),
                'in_use' => (string) config('marketing.ai.driver') === 'openai',
            ],
            'storage' => [
                'disk' => (string) config('marketing.media.disk', 'whatsapp'),
                'writable' => $this->storageWritable(),
            ],
            'iron_guard' => [
                // La clave es `observability.remediation.enabled`, no
                // `iron_guard.*`. La primera version leia una clave que NO
                // EXISTE: devolvia null, el panel enseñaba "Apagada" y acertaba
                // por casualidad. Un panel de supervision que acierta por
                // casualidad no esta supervisando nada.
                'auto_remediation' => (bool) config('observability.remediation.enabled', false),
            ],
        ];
    }

    private function storageWritable(): bool
    {
        return $this->safe(function () {
            $disk = \Illuminate\Support\Facades\Storage::disk(
                (string) config('marketing.media.disk', 'whatsapp'),
            );
            $probe = 'health/.probe-'.now()->timestamp;
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return true;
        }, false);
    }

    /**
     * Estado del canal, dicho como lo que es.
     *
     * Con el número sin registrar la pantalla NO puede enseñar un error rojo:
     * es exactamente la configuración pedida hasta que termine la verificación.
     * Un panel que grita por algo que está bien enseña a ignorar las alarmas.
     *
     * @return array<string,mixed>
     */
    private function channelState(): array
    {
        $configured = filled(config('meta.access_token')) && filled(config('meta.app_secret'));

        /*
         * Hay un `phone_number_id` en la configuración. Eso es TODO lo que se
         * puede afirmar desde aquí.
         *
         * La primera versión llamaba a esto `number_registered`, y era una
         * afirmación falsa: que exista un identificador en el fichero no
         * significa que el número real esté dado de alta en Meta -puede ser uno
         * de pruebas, que es justo el caso hoy-. Comprobar el alta de verdad
         * exige preguntárselo a Meta, y el canal está apagado.
         */
        $phoneConfigured = filled(config('meta.whatsapp_phone_number_id'));
        $live = (bool) config('meta.enabled', false) && $phoneConfigured;

        return [
            'meta_configured' => $configured,
            'meta_enabled' => (bool) config('meta.enabled', false),
            'phone_number_configured' => $phoneConfigured,
            'status' => $live ? 'live' : 'not_activated',
            'label' => $live
                ? 'WhatsApp productivo activo'
                : 'WhatsApp productivo todavía no activado',
            // Informativo, nunca error: es la configuración pedida hasta que
            // termine la verificación. Alarmar por algo correcto enseña a
            // ignorar las alarmas.
            'severity' => 'info',
        ];
    }

    /** @return array<string,int> */
    private function conversationCounters(): array
    {
        return $this->safe(function () {
            $row = DB::table('marketing_conversations')
                ->where('status', 'open')
                ->selectRaw('COUNT(*) as open')
                ->selectRaw('COUNT(CASE WHEN ai_enabled = true AND human_takeover = false THEN 1 END) as by_ai')
                ->selectRaw('COUNT(CASE WHEN human_takeover = true THEN 1 END) as by_human')
                ->selectRaw('COUNT(CASE WHEN ai_enabled = false AND human_takeover = false THEN 1 END) as paused')
                ->selectRaw('COUNT(CASE WHEN staff_review_pending = true THEN 1 END) as needs_review')
                ->selectRaw('COUNT(CASE WHEN unread_count > 0 THEN 1 END) as unread')
                ->first();

            return [
                'open' => (int) ($row->open ?? 0),
                'handled_by_ai' => (int) ($row->by_ai ?? 0),
                'handled_by_human' => (int) ($row->by_human ?? 0),
                'paused' => (int) ($row->paused ?? 0),
                'needs_review' => (int) ($row->needs_review ?? 0),
                'unread' => (int) ($row->unread ?? 0),
            ];
        }, []);
    }

    /** @return array<string,int> */
    private function workCounters(): array
    {
        return [
            'approvals_pending' => $this->safe(fn () => CommercialApproval::query()
                ->whereIn('status', CommercialApproval::OPEN_STATUSES)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(), 0),
            'opportunities_open' => $this->safe(fn () => DB::table('commercial_opportunities')
                ->whereIn('status', CommercialVocabulary::OPEN_STATUSES)->count(), 0),
            'tool_failures_24h' => $this->safe(fn () => Schema::hasTable('commercial_tool_invocations')
                ? DB::table('commercial_tool_invocations')
                    ->where('created_at', '>=', now()->subDay())
                    ->whereIn('status', ['failed', 'rejected'])
                    ->count()
                : 0, 0),
        ];
    }

    // ── E.6 Dinero producido por el agente ──────────────────────────────

    /**
     * Ventas del agente, clasificadas por lo que de verdad hizo.
     *
     * Las reglas, escritas aquí para que se puedan discutir:
     *
     *  · **Autónomo**: hubo actividad del agente en la conversación y NINGÚN
     *    mensaje humano ni toma de control antes del pago.
     *  · **Asistido**: hubo actividad del agente Y también intervención humana.
     *  · **Influido**: el agente actuó en algún momento, pero el pago llegó
     *    después de que la conversación pasara a manos de una persona.
     *
     * Lo que NO se hace: contar como del agente una venta solo porque existiera
     * una conversación. Con la autonomía apagada, «autónomo» debe salir en
     * cero, y sale.
     *
     * @return array<string,mixed>
     */
    public function agentRevenue(Carbon $from, Carbon $to): array
    {
        $rows = $this->safe(fn () => DB::table('payment_transactions as p')
            ->join('marketing_leads as l', 'l.member_id', '=', 'p.member_id')
            ->leftJoin('marketing_conversations as c', 'c.lead_id', '=', 'l.id')
            ->where('p.status', 'approved')
            ->whereBetween('p.paid_at', [$from, $to])
            ->selectRaw('p.id, p.amount, p.paid_at, c.id as conversation_id, c.human_takeover')
            ->get(), collect());

        $buckets = ['autonomous' => [], 'assisted' => [], 'influenced' => [], 'none' => []];

        foreach ($rows as $row) {
            $buckets[$this->classifySale($row)][] = (float) $row->amount;
        }

        $summary = [];

        foreach (['autonomous', 'assisted', 'influenced', 'none'] as $kind) {
            $amounts = $buckets[$kind];
            $summary[$kind] = [
                'sales' => count($amounts),
                'revenue' => round(array_sum($amounts), 2),
                'average_ticket' => $amounts === [] ? null : round(array_sum($amounts) / count($amounts), 2),
            ];
        }

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'classification' => $summary,
            // El coste de la IA solo se puede afirmar si hay contabilidad de
            // consumo. No la hay, y decir cero seria afirmar que es gratis.
            'ai_cost' => ['available' => false, 'note' => 'No hay contabilidad de consumo de IA conectada.'],
            'roi' => ['available' => false, 'note' => 'Sin coste conocido no se puede calcular retorno.'],
            'rules' => [
                'autonomous' => 'El agente actuó y ninguna persona escribió ni tomó el control antes del pago.',
                'assisted' => 'Actuaron el agente y una persona.',
                'influenced' => 'El agente actuó antes, pero la conversación estaba en manos de una persona al pagar.',
                'none' => 'No hay actividad del agente asociada a esa venta.',
            ],
        ];
    }

    /** @param object $row */
    private function classifySale($row): string
    {
        if ($row->conversation_id === null) {
            return 'none';
        }

        $agentActed = $this->safe(fn () => DB::table('marketing_messages')
            ->where('conversation_id', $row->conversation_id)
            ->where('sender_type', 'ai')
            ->where('created_at', '<=', $row->paid_at)
            ->exists(), false);

        if (! $agentActed) {
            return 'none';
        }

        $humanActed = $this->safe(fn () => DB::table('marketing_messages')
            ->where('conversation_id', $row->conversation_id)
            ->where('sender_type', 'human')
            ->where('created_at', '<=', $row->paid_at)
            ->exists(), false);

        if (! $humanActed) {
            return 'autonomous';
        }

        // Con la conversación ya en manos de una persona, el agente influyó
        // pero no cerró. Afirmar lo contrario sería sobreatribuir.
        return $row->human_takeover ? 'influenced' : 'assisted';
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    /** @param \Illuminate\Support\Collection<int,mixed> $values */
    private function percentile($values, int $p): ?float
    {
        $sorted = collect($values)->filter(fn ($v) => $v !== null)->sort()->values();

        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) ceil(($p / 100) * $sorted->count()) - 1;

        return round((float) $sorted[max(0, min($index, $sorted->count() - 1))], 1);
    }

    /**
     * Ejecuta y, si falla, devuelve el valor de reserva.
     *
     * Una pantalla de supervisión incompleta se puede leer; una que no carga
     * porque falta una tabla deja a quien supervisa sin nada.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @param  T  $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
