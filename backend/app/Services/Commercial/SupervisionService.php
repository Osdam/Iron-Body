<?php

namespace App\Services\Commercial;

use App\Models\CommercialApproval;
use App\Models\MarketingConversation;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Meta\MetaAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        $quien = $this->safe(fn () => $this->whoResponds(), self::WHO_UNKNOWN);

        return [
            'agent' => $this->agentState($quien),
            'infrastructure' => $this->infrastructureState(),
            'channels' => $this->channelState(),
            'conversations' => $this->conversationCounters($quien),
            'queue' => $this->workCounters(),
        ];
    }

    /** Lo que se dice cuando no se pudo leer el interruptor o el freno: nunca «activo» sin saberlo. */
    private const WHO_UNKNOWN = [
        'status' => 'unknown', 'responds' => false,
        'label' => 'No se pudo leer el estado del agente', 'short' => 'Estado del agente sin leer',
    ];

    /**
     * Quién le responde hoy a un cliente de forma automática.
     *
     * La pantalla enseñaba el interruptor del agente anterior (`marketing.agent_enabled`) y decía «Apagado» mientras
     * ULTRON atendía el canario. Aquí manda la misma verdad que en el Inbox ({@see MarketingAgentSwitch::present()})
     * y en el emisor de ULTRON (`UltronEventEmitter`): ULTRON con su alcance real, su
     * freno y la pausa global; y, solo si ULTRON no está, el agente anterior, que responde por su cuenta únicamente
     * con sus tres llaves puestas (`agent_enabled`, `inbound.auto_analyze`, `inbound.auto_execute`).
     *
     * @return array{status: string, responds: bool, label: string, short: string}
     */
    private function whoResponds(): array
    {
        $ultron = (bool) config('marketing.ultron.enabled', false);
        $analiza = (bool) config('marketing.inbound.auto_analyze', true);
        $ejecuta = $analiza && (bool) config('marketing.agent_enabled', false) && (bool) config('marketing.inbound.auto_execute', false);

        if (! $ultron && ! $analiza) {
            return ['status' => 'off', 'responds' => false, 'label' => 'Ningún agente responde automáticamente', 'short' => 'Sin agente automático'];
        }
        if (app(MarketingAgentSwitch::class)->isPaused()) {
            return ['status' => 'paused', 'responds' => false, 'label' => 'Agente en pausa: nada automático responde', 'short' => 'Agente en pausa'];
        }
        if ($ultron) {
            if (app(UltronAbortLatch::class)->engaged()) {
                return ['status' => 'brake', 'responds' => false, 'label' => 'ULTRON está frenado: no responde hasta que se suelte el freno', 'short' => 'ULTRON frenado'];
            }

            // Encendido pero sin conexión con n8n, el trabajo que lleva el turno lo salta (`SendUltronEventToN8n`).
            if (trim((string) config('marketing.ultron.webhook_url')) === '' || (string) config('automation.webhook_secret') === '') {
                return ['status' => 'ultron_unconfigured', 'responds' => false, 'label' => 'ULTRON está encendido, pero le falta la conexión con n8n: no responde', 'short' => 'ULTRON sin conexión'];
            }
            $canario = config('marketing.ultron.canary_conversation_id');
            if ($canario === null) {
                return ['status' => 'ultron_all', 'responds' => true, 'label' => 'ULTRON responde en todas las conversaciones', 'short' => 'ULTRON activo'];
            }
            // Un canario que no es una conversación (0, un texto, una que no existe) no deja pasar a nadie: el emisor
            // compara el id de cada conversación con ese valor. Decir «responde» sería falso.
            if ((int) $canario <= 0 || ! DB::table('marketing_conversations')->where('id', (int) $canario)->exists()) {
                return ['status' => 'ultron_canary_missing', 'responds' => false, 'label' => 'ULTRON está en canario, pero su conversación de prueba no existe: no responde a nadie', 'short' => 'Canario mal configurado'];
            }

            return ['status' => 'ultron_canary', 'responds' => true, 'label' => 'ULTRON responde solo en la conversación de prueba (canario)', 'short' => 'ULTRON · solo canario'];
        }

        return $ejecuta
            ? ['status' => 'legacy_auto', 'responds' => true, 'label' => 'El agente anterior responde por su cuenta', 'short' => 'Agente anterior activo']
            : ['status' => 'legacy_propose', 'responds' => false, 'label' => 'El agente anterior solo propone respuestas: no envía nada por su cuenta', 'short' => 'El agente solo propone'];
    }

    /**
     * ¿Atiende ULTRON esta conversación ahora mismo? Las mismas barreras de conversación que su emisor
     * (`UltronEventEmitter::ineligibleReason()`): abierta, de WhatsApp, con un prospecto al que se le puede responder
     * (ni «no contactar» ni consentimiento denegado), con la IA permitida y sin que una persona la haya tomado A MANO
     * (una toma que hizo el propio agente no lo deja mudo).
     */
    private function ultronAttends(?MarketingConversation $c): bool
    {
        return $c !== null
            && $c->status === 'open'
            && $c->channel === 'whatsapp'
            && $c->lead !== null
            && $c->lead->canReplyReactively()
            && (bool) $c->ai_enabled
            && ! ($c->human_takeover && $c->human_takeover_source === 'manual');
    }

    private function canaryIsAttended(): bool
    {
        return $this->ultronAttends(
            MarketingConversation::query()->with('lead')->find((int) config('marketing.ultron.canary_conversation_id')),
        );
    }

    /**
     * Con ULTRON en todas: cuántas abiertas atiende, con las mismas barreras. `handled_by_ai` contaba de más (las de
     * Instagram y las de quien pidió no contactar) y de menos (las que escaló el propio agente): m-4 de la revisión
     * de la ola 6. La base descarta lo que puede; el consentimiento lo decide el modelo, que es quien lo sabe.
     */
    private function countAttendedByUltron(): int
    {
        $n = 0;
        MarketingConversation::query()->with('lead')
            ->where('status', 'open')->where('channel', 'whatsapp')->where('ai_enabled', true)
            ->where(fn ($q) => $q->where('human_takeover', false)->orWhereNull('human_takeover_source')->orWhere('human_takeover_source', '<>', 'manual'))
            ->chunkById(500, function ($trozo) use (&$n): void {
                foreach ($trozo as $c) {
                    $n += $this->ultronAttends($c) ? 1 : 0;
                }
            });

        return $n;
    }

    /**
     * ¿Atiende ULTRON cada una de estas conversaciones ahora mismo? Una sola lectura para todas, con las barreras de
     * {@see ultronAttends()}. Para la línea de tiempo: el alcance dice si ULTRON PUEDE llevar una conversación; esto,
     * si la lleva (no la de Instagram, ni la que tomó una persona a mano, ni la de quien pidió no contactar).
     *
     * @param  list<int>  $ids
     * @return array<int, bool>
     */
    public function ultronAttendsConversations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->safe(
            fn () => MarketingConversation::query()->with('lead')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (MarketingConversation $c): array => [(int) $c->id => $this->ultronAttends($c)])->all(),
            [],
        );
    }

    /**
     * Qué conversaciones lleva hoy un agente automático, para la línea de tiempo: todas (`all`), solo la del canario
     * (`one`), ninguna (`none`) o no se sabe (`unknown`: el agente anterior, o el estado sin leer). Es el alcance de
     * ULTRON, no cada barrera de cada conversación.
     *
     * @return array{mode: string, conversation: int|null}
     */
    public function automaticCoverage(): array
    {
        $quien = $this->safe(fn () => $this->whoResponds(), self::WHO_UNKNOWN);

        return match ($quien['status']) {
            'ultron_all' => ['mode' => 'all', 'conversation' => null],
            'ultron_canary' => ['mode' => 'one', 'conversation' => (int) config('marketing.ultron.canary_conversation_id')],
            'off', 'paused', 'brake', 'ultron_unconfigured', 'ultron_canary_missing' => ['mode' => 'none', 'conversation' => null],
            default => ['mode' => 'unknown', 'conversation' => null],
        };
    }

    /**
     * @param  array{status: string, responds: bool, label: string, short: string}  $quien
     * @return array<string,mixed>
     */
    private function agentState(array $quien): array
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
            // `enabled` = algo automático le responde hoy a alguien (ver whoResponds()). Antes era el interruptor
            // del agente anterior, y la pantalla decía «Apagado» con ULTRON atendiendo el canario.
            'enabled' => $quien['responds'],
            'status' => $quien['status'],
            'label' => $quien['label'],
            'short_label' => $quien['short'],
            'legacy_enabled' => (bool) config('marketing.agent_enabled', false),
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
            $disk = Storage::disk(
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
        $auth = app(MetaAuthService::class);
        // La credencial EFECTIVA, no la del fichero: si el canal se conectó
        // desde el CRM, el .env puede seguir vacío y esta pantalla diría que
        // no hay nada configurado mientras el canal funciona.
        $configured = filled($auth->accessToken()) && filled(config('meta.app_secret'));

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
        $phoneConfigured = filled($auth->phoneNumberId());
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

    /**
     * `handled_by_ai` cuenta las abiertas que nadie del equipo ha tomado y con la IA permitida: NO es «las lleva el
     * agente». Las que el agente atiende de verdad van en `attended_by_agent`: todas esas si responde en todas, solo
     * la del canario si ULTRON está en canario, y ninguna si está en pausa, frenado, apagado o solo propone.
     *
     * @param  array{status: string, responds: bool, label: string, short: string}  $quien
     * @return array<string,int>
     */
    private function conversationCounters(array $quien): array
    {
        return $this->safe(function () use ($quien) {
            $row = DB::table('marketing_conversations')
                ->where('status', 'open')
                ->selectRaw('COUNT(*) as open')
                ->selectRaw('COUNT(CASE WHEN ai_enabled = true AND human_takeover = false THEN 1 END) as by_ai')
                ->selectRaw('COUNT(CASE WHEN human_takeover = true THEN 1 END) as by_human')
                ->selectRaw('COUNT(CASE WHEN ai_enabled = false AND human_takeover = false THEN 1 END) as paused')
                ->selectRaw('COUNT(CASE WHEN staff_review_pending = true THEN 1 END) as needs_review')
                ->selectRaw('COUNT(CASE WHEN unread_count > 0 THEN 1 END) as unread')
                ->first();

            $atiende = match ($quien['status']) {
                'ultron_all' => $this->countAttendedByUltron(),
                'ultron_canary' => $this->canaryIsAttended() ? 1 : 0,
                // El agente anterior no está en uso: se deja su aproximación de siempre (las que nadie ha tomado).
                'legacy_auto' => (int) ($row->by_ai ?? 0),
                default => 0,
            };

            return [
                'open' => (int) ($row->open ?? 0),
                'attended_by_agent' => $atiende,
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

        /*
         * Que una persona TENGA el control basta, escriba o no.
         *
         * Esto estaba después de exigir un mensaje humano, y eso dejaba un hueco
         * por el que el agente se llevaba ventas que no cerró: un asesor toma la
         * conversación, llama al cliente por teléfono y cobra: como no escribió
         * nada en el chat, la venta contaba como autónoma. Sobreatribuir así es
         * peor que no medir, porque la cifra se usa para decidir si el agente
         * merece más autonomía.
         */
        if ($row->human_takeover) {
            return 'influenced';
        }

        $humanActed = $this->safe(fn () => DB::table('marketing_messages')
            ->where('conversation_id', $row->conversation_id)
            ->where('sender_type', 'human')
            ->where('created_at', '<=', $row->paid_at)
            ->exists(), false);

        return $humanActed ? 'assisted' : 'autonomous';
    }

    // ── Utilidades ──────────────────────────────────────────────────────

    /** @param Collection<int,mixed> $values */
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
