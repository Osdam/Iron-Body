<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingAiAction;
use App\Models\Incident;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\UltronAbort;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronSource;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * EL VIGÍA DE LOS TURNOS. Busca a quien preguntó y no recibió nada.
 *
 * Todo lo demás del sistema comprueba que lo que SALE es correcto. Esto
 * comprueba lo contrario: que haya salido algo. Es la única pieza que puede
 * encontrar un silencio, porque un silencio no deja fila que revisar —de eso
 * se trata— y sólo se ve cruzando dos tablas que nadie cruzaba:
 *
 *   `marketing_automation_events`  → Laravel consideró atendible ese entrante.
 *   `marketing_ai_actions`         → hubo un desenlace para ese entrante.
 *
 * Un evento sin acción es un turno HUÉRFANO. Con una excepción legítima que hay
 * que descontar o el vigía miente: si después de ese mensaje la conversación sí
 * recibió una respuesta —porque el turno lo ganó un mensaje posterior de la
 * misma persona—, esa persona no se quedó sin contestación. El invariante que
 * importa no es «una respuesta por mensaje», es «nadie se queda sin respuesta».
 *
 * Qué hace cuando encuentra uno:
 *   1. Abre UN incidente en IRON GUARD por clase (no uno por mensaje).
 *   2. Si el huérfano está en la conversación del canario, ACCIONA EL FRENO.
 *      Un canario que se queda mudo tiene que pararse solo: descubrirlo a la
 *      mañana siguiente es descubrirlo tarde.
 *
 * Idempotente y de solo lectura sobre el dominio: no toca mensajes, no reenvía
 * nada y no reabre turnos. Correrlo diez veces con el mismo silencio deja un
 * incidente con diez ocurrencias y un solo freno accionado.
 */
class UltronTurnWatchdog extends Command
{
    protected $signature = 'ultron:turn-watchdog
        {--minutes=10 : gracia, en minutos: un turno más nuevo que esto todavía puede estar en vuelo}
        {--window=360 : hasta dónde mira hacia atrás, en minutos: un silencio de anteayer ya no es accionable}
        {--since= : mira solo eventos posteriores a esta fecha/hora ISO}
        {--limit=200 : tope de huérfanos a examinar en una pasada}
        {--no-abort : encuentra y reporta, pero no acciona el freno}
        {--json : salida en JSON}';

    protected $description = 'Encuentra entrantes atendibles que se quedaron sin desenlace y, en el canario, para ULTRON.';

    private UltronDecideService $decide;

    public function handle(IncidentRecorder $incidents, UltronAbortLatch $latch, UltronDecideService $decide): int
    {
        $this->decide = $decide;

        $gracia = max(1, (int) $this->option('minutes'));
        $corte = now()->subMinutes($gracia);

        /*
         * El otro extremo de la ventana, y la razón por la que existe: sin él,
         * la primera corrida en producción desenterraría los silencios de la
         * ronda anterior del canario —que ya se investigaron a mano— y pararía
         * el canario nada más encenderlo. Un vigía tiene que mirar lo que está
         * pasando, no lo que ya pasó y alguien ya miró.
         */
        $ventana = now()->subMinutes(max($gracia + 1, (int) $this->option('window')));

        $huerfanos = $this->huerfanos($corte, $ventana);
        $rendiciones = $this->rendiciones($ventana);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked_before' => $corte->toIso8601String(),
                'orphans' => $huerfanos->values()->all(),
                'recoveries' => $rendiciones->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprime($huerfanos, $gracia);
        }

        /*
         * Las RENDICIONES no son silencios: la persona recibió el texto curado
         * de Laravel. Pero son la señal de que el borrador del modelo se está
         * cayendo contra un guard, y esa señal no la manda nadie más: el
         * workflow responde 200 y el turno queda bien. Sin esto, un fallo
         * sistemático del Composer se vería solo leyendo los chats a mano.
         */
        if ($rendiciones->isNotEmpty()) {
            $incidenteRendicion = $incidents->record([
                'source' => 'ultron',
                'kind' => 'ultron.turn.recovered',
                'title' => 'ULTRON tuvo que rendirse al texto curado (RECOVERED_ULTRON_TURN)',
                'severity' => Incident::SEVERITY_MEDIUM,
                'evidence' => [
                    'recoveries' => $rendiciones->count(),
                    'reasons' => $rendiciones->pluck('reason')->countBy()->all(),
                    'action_ids' => $rendiciones->pluck('action_id')->take(20)->values()->all(),
                ],
                'correlation_ids' => $rendiciones->take(10)->map(fn (array $r) => 'message:'.$r['message_id'])->all(),
                'affected_conversations' => $rendiciones->pluck('conversation_id')->unique()->count(),
                'affected_messages' => $rendiciones->count(),
            ]);

            $this->warn($rendiciones->count().' rendicion(es): el borrador del modelo no pudo salir y contestó Laravel. Incidente '.$incidenteRendicion->id.'.');
            foreach ($rendiciones->pluck('reason')->countBy() as $motivo => $veces) {
                $this->line('   '.$motivo.' x'.$veces);
            }
        }

        // Una rendición no tumba el vigía: la persona SÍ recibió respuesta.
        if ($huerfanos->isEmpty()) {
            return self::SUCCESS;
        }

        $incidente = $incidents->record([
            'source' => 'ultron',
            // La CLASE, no la ocurrencia: cien silencios son un incidente con
            // cien ocurrencias, que es lo que hace legible el panel.
            'kind' => 'ultron.turn.orphaned',
            'title' => 'ULTRON dejó turnos sin desenlace (ORPHANED_ULTRON_TURN)',
            'severity' => Incident::SEVERITY_HIGH,
            'evidence' => [
                'grace_minutes' => $gracia,
                'orphans' => $huerfanos->count(),
                // Ids, nunca el texto de la conversación.
                'message_ids' => $huerfanos->pluck('message_id')->take(20)->values()->all(),
                'conversation_ids' => $huerfanos->pluck('conversation_id')->unique()->values()->all(),
                'oldest_at' => $huerfanos->min('event_at'),
            ],
            'correlation_ids' => $huerfanos->take(10)->map(fn (array $h) => 'message:'.$h['message_id'])->all(),
            'affected_conversations' => $huerfanos->pluck('conversation_id')->unique()->count(),
            'affected_messages' => $huerfanos->count(),
        ]);

        $this->warn('Incidente '.$incidente->id.' ('.$incidente->occurrences.' ocurrencias, severidad '.$incidente->severity.').');

        ChannelLog::warning('ultron.turn.orphaned', [
            'orphans' => $huerfanos->count(),
            'incident_id' => $incidente->id,
        ]);

        // ── El freno ──────────────────────────────────────────────────────────
        $canario = config('marketing.ultron.canary_conversation_id');
        $enCanario = $canario !== null && $huerfanos->contains(
            fn (array $h) => (int) $h['conversation_id'] === (int) $canario
        );

        if ($enCanario && ! $this->option('no-abort')) {
            $abort = $latch->engage(
                UltronAbort::REASON_ORPHANED_TURN,
                'ultron:turn-watchdog',
                [
                    'incident_id' => (int) $incidente->id,
                    'conversation_id' => (int) $canario,
                    'message_ids' => $huerfanos
                        ->filter(fn (array $h) => (int) $h['conversation_id'] === (int) $canario)
                        ->pluck('message_id')->take(20)->values()->all(),
                ],
            );

            $this->error('CANARIO PARADO. Freno '.$abort->id.' accionado: un turno del canario se quedó mudo.');
            $this->line('  Se suelta a mano, cuando se entienda el fallo:  php artisan ultron:abort --release --by="<nombre>"');
        }

        return self::FAILURE;
    }

    /**
     * Turnos atendibles sin desenlace y sin respuesta posterior.
     *
     * @return Collection<int,array{message_id:int,conversation_id:int,event_id:int,event_status:string,event_at:?string,waited_minutes:int}>
     */
    private function huerfanos(\DateTimeInterface $corte, \DateTimeInterface $ventana): Collection
    {
        $eventos = MarketingAutomationEvent::query()
            ->where('event_type', MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED)
            ->whereNotNull('message_id')
            ->where('created_at', '<', $corte)
            ->where('created_at', '>=', $ventana)
            // `skipped` significa que ni se intentó (flag apagado): no prometió
            // ningún turno, así que tampoco puede haberlo perdido.
            ->where('status', '!=', MarketingAutomationEvent::STATUS_SKIPPED)
            ->when($this->option('since'), fn ($q, $desde) => $q->where('created_at', '>=', $desde))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'message_id', 'conversation_id', 'status', 'created_at']);

        return $eventos
            ->filter(fn (MarketingAutomationEvent $e) => ! $this->tuvoDesenlace($e)
                && ! $this->respondidaDespues($e)
                && ! $this->yaNoEraAtendible($e))
            ->map(fn (MarketingAutomationEvent $e) => [
                'message_id' => (int) $e->message_id,
                'conversation_id' => (int) $e->conversation_id,
                'event_id' => (int) $e->id,
                'event_status' => (string) $e->status,
                'event_at' => optional($e->created_at)->toIso8601String(),
                'waited_minutes' => (int) ($e->created_at?->diffInMinutes(now()) ?? 0),
            ])
            ->values();
    }

    /**
     * Turnos que terminaron en el texto curado porque el CRM rechazó el del
     * modelo. Se reconocen por `metadata.recovery`, que sólo escribe la
     * rendición.
     *
     * @return Collection<int,array{action_id:int,conversation_id:int,message_id:int,reason:string}>
     */
    private function rendiciones(\DateTimeInterface $ventana): Collection
    {
        return MarketingAiAction::query()
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->whereNotNull('metadata->recovery')
            ->where('created_at', '>=', $ventana)
            ->when($this->option('since'), fn ($q, $desde) => $q->where('created_at', '>=', $desde))
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'conversation_id', 'source_event_id', 'metadata'])
            ->map(fn (MarketingAiAction $a) => [
                'action_id' => (int) $a->id,
                'conversation_id' => (int) $a->conversation_id,
                'message_id' => (int) $a->source_event_id,
                'reason' => (string) data_get($a->metadata, 'recovery.reason', 'unknown'),
            ])
            ->values();
    }

    /** ¿Existe la fila que dice que ese turno terminó en algo? */
    private function tuvoDesenlace(MarketingAutomationEvent $e): bool
    {
        return MarketingAiAction::query()
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->where('source_event_id', (int) $e->message_id)
            ->exists();
    }

    /**
     * ¿Le contestamos después por otra vía?
     *
     * El caso legítimo: la persona escribió tres veces seguidas, el turno lo
     * ganó el tercero y los dos primeros se bloquearon como `superseded`. Esa
     * persona SÍ recibió respuesta. Contarla como silencio convertiría al vigía
     * en una alarma que suena siempre, y una alarma que suena siempre se apaga.
     */
    private function respondidaDespues(MarketingAutomationEvent $e): bool
    {
        return MarketingMessage::query()
            ->where('conversation_id', (int) $e->conversation_id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            // Cualquier saliente, de la máquina o de una persona. Si un asesor
            // contestó a mano desde el Inbox —que es lo normal durante un
            // canario— esa persona NO se quedó sin respuesta, y acusarlo de
            // silencio pararía el canario por haberlo atendido bien.
            ->where('id', '>', (int) $e->message_id)
            ->exists();
    }

    /**
     * ¿Dejó de ser atendible entre medias?
     *
     * Entre que nace el evento y llega el commit pasan segundos, y en esos
     * segundos alguien puede tomar la conversación, apagarle la IA o pedir que
     * no le escriban. No contestar entonces es lo correcto, no un silencio, y
     * contarlo como huérfano pararía el canario por hacer bien las cosas —que
     * es la forma más rápida de que una alarma se acabe ignorando—.
     */
    private function yaNoEraAtendible(MarketingAutomationEvent $e): bool
    {
        $conversation = MarketingConversation::with('lead')->find((int) $e->conversation_id);
        $message = MarketingMessage::find((int) $e->message_id);

        if ($conversation === null || $message === null) {
            return true; // ya no hay a quién contestar
        }

        return $this->decide->ineligibleReason($conversation, $message) !== null;
    }

    /** @param  Collection<int,array<string,mixed>>  $huerfanos */
    private function imprime(Collection $huerfanos, int $gracia): void
    {
        $this->info('VIGÍA DE TURNOS — gracia de '.$gracia.' min');

        if ($huerfanos->isEmpty()) {
            $this->line('  Sin turnos huérfanos: todo entrante atendible terminó en algo.');

            return;
        }

        $this->error('  '.$huerfanos->count().' turno(s) sin desenlace:');
        $this->table(
            ['mensaje', 'conversación', 'evento', 'estado', 'esperando (min)'],
            $huerfanos->map(fn (array $h) => [
                $h['message_id'], $h['conversation_id'], $h['event_id'], $h['event_status'], $h['waited_minutes'],
            ])->all(),
        );
    }
}
