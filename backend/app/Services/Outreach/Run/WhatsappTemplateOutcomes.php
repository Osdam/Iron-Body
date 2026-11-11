<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\WhatsappRecipient;
use App\Services\Outreach\Facts\WhatsappSignals;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * W9 · LOS NORMALIZADORES DE WHATSAPP (contrato H9, D9-8 etapa 8, D9-10;
 * D9-15 «la palabra de baja en las respuestas»). Un ADAPTADOR sobre el
 * camino de siempre de los webhooks de Meta, sin tocarlo: el webhook sigue
 * guardando estados y entrantes como hoy (`ProcessMetaWebhookEvent`), y esta
 * pasada LEE lo que dejó ({@see WhatsappSignals}, solo lectura) y lo convierte
 * en resultados del conjunto cerrado por la ÚNICA ingesta
 * ({@see OutcomeRecorder}: `OutcomeTable::resolve()`, una vez por
 * `(inscripción, fuente, clave)`, seguimientos y efectos):
 *
 *  - `meta_status`: `sent`, `delivered` y `read` (no terminales: se espera)
 *    y `failed` (el que movió el estado del mensaje; uno que llegó tarde a un
 *    mensaje ya entregado no es un fallo) o el outbox que se rindió sin que
 *    Meta lo aceptara (`dead`). Clave `job:<uuid>:<estado>`;
 *  - `inbound_message`: el primer entrante de la persona por WhatsApp entre
 *    este saliente y el siguiente del H9 → `replied` (por defecto deriva a una
 *    persona: «nunca otro contacto automático»); si alguno es una BAJA SEGURA
 *    ({@see WhatsappSignals::isOptOutText()}) → `opted_out` en su lugar (sale
 *    diga lo que diga la estrategia, cancela todo lo pendiente de la persona
 *    en todas las estrategias y la suprime para esa finalidad en TODOS los
 *    medios, SC-1, sin que ninguna estrategia lo anule); si no, y alguno es
 *    una POSIBLE baja (SC-2, {@see WhatsappSignals::isPossibleOptOutText()}) →
 *    `human_requested` con el detalle `opt_out_word`: la secuencia para y una
 *    persona la revisa (`handoff_human`, la única transición de ese resultado,
 *    aunque la estrategia mande `replied` → `end_exit`), y mientras tanto
 *    {@see SubjectSuppressions} la cuenta como baja de la finalidad. Nunca
 *    interpreta el texto: la baja segura es una lista cerrada y la posible, un
 *    detector conservador; clasificar una respuesta es AI9.
 *
 * Concilia también los trabajos `ambiguous` (el proceso murió después de que
 * la puerta creara el mensaje): los une a su mensaje por la CORRELACIÓN (el
 * `uuid` del trabajo en `correlation_id`), una vez, y normaliza lo que dijo
 * Meta; uno sin mensaje se queda para una persona (no salió nada que leer).
 *
 * Mira los trabajos de WhatsApp en vivo de los últimos {@see LOOKBACK_DAYS}
 * días; es idempotente (repetirla no avanza nada dos veces), no escribe nada
 * fuera de `outreach_*` y no deja fila en `outreach_runs`. Con la maestra apagada o el modo `off`, no hace nada.
 * La programación (cada minuto, antes de `outreach:dispatch`) es de OPS9; el
 * canal no espera a esta pasada para no escribir a quien ya pidió la baja.
 */
final class WhatsappTemplateOutcomes
{
    /** Cuántos días se siguen mirando los trabajos de WhatsApp que salieron (estados y respuestas). */
    public const LOOKBACK_DAYS = 30;

    /** Trabajos por pasada (los más antiguos primero). */
    public const BATCH = 500;

    /** El orden real de la entrega (el de `MarketingMessageStatus::RANKS`): se registran en este orden. */
    private const RANK = ['sent' => 1, 'failed' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly OutreachStore $store = new OutreachStore,
        private readonly WhatsappSignals $signals = new WhatsappSignals,
        private readonly WhatsappRecipient $recipients = new WhatsappRecipient,
    ) {}

    /** @return array<string, mixed> */
    public function run(DateTimeInterface $now, ?int $limit = null): array
    {
        $ahora = RelativeTime::utc($now);
        $cero = ['jobs' => 0, 'recorded' => [], 'duplicates' => 0, 'ignored' => 0, 'linked' => 0, 'unmatched' => 0];
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off'] + $cero;
        }
        // Sin fila en `outreach_runs` (como el normalizador de la voz de V9): el panel enseña la última pasada
        // por tipo y una de cada minuto taparía la ingesta de `commercial_events` del planificador. La huella
        // de esta pasada son sus resultados, y la orden imprime los recuentos.
        $r = ['skipped' => false] + $cero;
        $trabajos = DB::table(OutreachTables::JOBS)
            ->where('mode', OutreachConfig::MODE_LIVE)
            ->where('channel', OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE)
            ->whereIn('status', ['started', 'ambiguous'])
            ->where('status_changed_at', '>=', OutreachStore::sql($ahora->modify('-'.self::LOOKBACK_DAYS.' days')))
            ->orderBy('id')
            ->limit(max(1, min(self::BATCH, $limit ?? self::BATCH)))
            ->get()->all();
        foreach ($this->withNext($trabajos) as [$trabajo, $siguiente]) {
            $r['jobs']++;
            foreach ($this->forJob($trabajo, $siguiente, $ahora) as $clave => $n) {
                if (in_array($clave, ['duplicates', 'ignored', 'linked', 'unmatched'], true)) {
                    $r[$clave] += $n;
                } else {
                    $r['recorded'][$clave] = ($r['recorded'][$clave] ?? 0) + $n;
                }
            }
        }

        return $r;
    }

    /**
     * Normaliza UN trabajo de WhatsApp que salió (o quedó ambiguo). `$next` es
     * el instante del siguiente saliente del H9 a la misma persona (las
     * respuestas después de él son de ese otro contacto).
     *
     * @return array<string, int> recuentos por resultado registrado y `duplicates`, `ignored`, `linked`, `unmatched`
     */
    public function forJob(object $job, ?DateTimeImmutable $next, DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        $e = $this->store->enrollment((int) $job->enrollment_id);
        if ($e === null) {
            return ['unmatched' => 1];
        }
        $idMensaje = $job->marketing_message_id !== null ? (int) $job->marketing_message_id : null;
        $salida = $this->signals->outboundOf($idMensaje, (string) $job->uuid);
        if ($salida === null) {
            // Sin mensaje no salió nada que leer (o no es nuestro): un ambiguo así lo resuelve una persona.
            return ['unmatched' => 1];
        }
        $c = [];
        if ($idMensaje === null) {
            // Conciliación por la correlación: el trabajo ambiguo recibe su mensaje, una vez (DB9: «las referencias, una vez»).
            $unido = DB::table(OutreachTables::JOBS)->where('id', (int) $job->id)->where('status', 'ambiguous')->whereNull('marketing_message_id')
                ->update(['marketing_message_id' => $salida['id'], 'updated_at' => OutreachStore::sql($ahora)]) === 1;
            $c['linked'] = $unido ? 1 : 0;
            $job = $this->store->job((int) $job->id) ?? $job;
        }
        $registrar = function (string $outcome, string $source, string $suffix, ?DateTimeImmutable $at, ?string $detail = null) use (&$c, &$e, $job, $ahora): void {
            $o = ['job' => $job, 'occurredAt' => $at ?? $ahora];
            if ($detail !== null) {
                $o['detailCode'] = $detail;
            }
            $r = $this->outcomes->record($e, $outcome, $source, 'job:'.$job->uuid.':'.$suffix, $ahora, $o);
            $clave = match ($r['result']) {
                OutcomeRecorder::RESULT_DUPLICATE => 'duplicates',
                OutcomeRecorder::RESULT_IGNORED => 'ignored',
                default => $outcome,
            };
            $c[$clave] = ($c[$clave] ?? 0) + 1;
            // La inscripción pudo cambiar (paso, estado, cierre): el siguiente resultado la lee de nuevo.
            $e = $this->store->enrollment((int) $e->id) ?? $e;
        };

        // Los estados de Meta, una vez cada uno, en el orden real de la entrega.
        $estados = [];
        foreach ($this->signals->statusesOf($salida['id']) as $st) {
            if ($st['status'] === 'failed' && ! $st['applied']) {
                // Un «failed» que no movió el estado (el mensaje ya estaba entregado o leído) no es un fallo del envío.
                continue;
            }
            $estados[$st['status']] ??= $st;
        }
        uksort($estados, static fn (string $a, string $b): int => self::RANK[$a] <=> self::RANK[$b]);
        foreach ($estados as $estado => $st) {
            $detalle = $estado === 'failed' ? ($st['errorCode'] !== null ? 'meta_error_'.$st['errorCode'] : 'meta_failed') : null;
            $registrar($estado, 'meta_status', $estado, $st['at'], $detalle);
        }
        // El outbox se rindió sin que Meta lo aceptara (reintentos agotados o rechazo definitivo después de salir el trabajo).
        if (! $salida['metaAccepted'] && $salida['status'] === 'dead' && ! isset($estados['failed'])) {
            $registrar('failed', 'meta_status', 'failed', null, $salida['lastErrorCode'] !== null ? 'meta_error_'.$salida['lastErrorCode'] : 'outbox_dead');
        }

        // Las respuestas: entre este saliente y el siguiente del H9 a la persona.
        $entrantes = $this->signals->inboundBetween($this->recipients->leadIdsOf((string) $e->subject_key), $salida['createdAt'], $next);
        $baja = null;
        $posible = null;
        foreach ($entrantes as $m) {
            if ($m['optOut']) {
                $baja = $m;

                break;
            }
            $posible ??= $m['possibleOptOut'] ? $m : null;
        }
        if ($baja !== null) {
            $registrar('opted_out', 'inbound_message', 'opted_out', $baja['at']);
        } elseif ($posible !== null) {
            // SC-2: una POSIBLE baja no es una baja segura ni una respuesta cualquiera: una persona la revisa.
            $registrar('human_requested', 'inbound_message', 'possible_opt_out', $posible['at'], SubjectSuppressions::OPT_OUT_WORD_DETAIL);
        } elseif ($entrantes !== []) {
            $registrar('replied', 'inbound_message', 'replied', $entrantes[0]['at']);
        }

        return $c;
    }

    /**
     * Cada trabajo con el instante del siguiente saliente del H9 a la misma
     * persona (en esta misma lista; si no hay, ninguno).
     *
     * @param  list<object>  $jobs
     * @return list<array{0: object, 1: DateTimeImmutable|null}>
     */
    private function withNext(array $jobs): array
    {
        $porPersona = [];
        foreach ($jobs as $j) {
            $porPersona[(string) $j->subject_key][] = $j;
        }
        $salida = [];
        foreach ($jobs as $j) {
            $siguiente = null;
            foreach ($porPersona[(string) $j->subject_key] as $otro) {
                if ((int) $otro->id > (int) $j->id) {
                    $creado = $this->signals->outboundOf($otro->marketing_message_id !== null ? (int) $otro->marketing_message_id : null, (string) $otro->uuid);
                    if ($creado !== null) {
                        $siguiente = $creado['createdAt'];

                        break;
                    }
                }
            }
            $salida[] = [$j, $siguiente];
        }

        return $salida;
    }
}
