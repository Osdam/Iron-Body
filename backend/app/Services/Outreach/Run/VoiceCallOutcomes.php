<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\VoiceCallStates;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EL NORMALIZADOR DE LA VOZ (contrato H9, D9-8 etapa 8, D9-10, D9-3 clase
 * `integration`; paquete V9): convierte lo que la telefonía del H8 YA
 * registró de una llamada que pidió una estrategia en resultados del conjunto
 * cerrado, por la ÚNICA ingesta ({@see OutcomeRecorder}, fuente `voice_call`,
 * una vez por llamada y clave: repetirlo no duplica ni avanza nada).
 *
 * SIN INTERPRETAR LO QUE SE DIJO (eso es AI9): solo estados, códigos y las dos
 * herramientas que deciden por sí solas. El mapa ({@see map()}, PURO):
 *
 *  - el proveedor la aceptó (tiene conversación o llamada de Twilio) → `sent`
 *    (no terminal: se espera);
 *  - `no_volver_a_llamar` en esa llamada (la exclusión `spoken_opt_out`, Ley
 *    2300) → `opted_out`, EN EL ACTO aunque la llamada siga: cancela todo lo
 *    pendiente de la persona y la suprime (efectos de la tabla);
 *  - con la llamada ya en un estado final:
 *    · `solicitar_humano` usada → `human_requested` (se deriva);
 *    · `completed` (con la post-llamada procesada) → `answered`, con el
 *      veredicto de la guarda como detalle (`guard_clean` / `guard_violation`;
 *      la violación ya echó el freno de voz del H8);
 *    · `busy` y `no_answer` → `no_answer` (detalle `busy` / `no_answer`);
 *    · `failed` → `failed` (su código de fallo);
 *    · `failed_to_start` (el proveedor la rechazó) → `failed`;
 *    · `canceled` (el job del H8 la canceló antes de marcar: constante,
 *      publicación movida, consentimiento revocado, fuera de horario…) →
 *      `failed` con el motivo de la política del H8 como detalle;
 *    · `hung_up` (el corte de emergencia del H8) → `failed` (`hung_up`);
 *  - viva (`requested`, `ambiguous`, `dialing`, `active`) → nada todavía.
 *
 * Un trabajo `ambiguous` sin su referencia se enlaza por su uuid (la llamada
 * lleva el trabajo como `client_request_id`); si pasados
 * {@see ABSENT_AFTER_MINUTES} no existe ninguna, nunca la habrá (la puerta
 * confirma o deshace en una transacción): `failed` con `voice_call_absent`.
 *
 * EL COSTE: el de la llamada (proveedor + transportista, en micros, el que
 * escribe el H8) queda alcanzable desde el trabajo por su referencia
 * (`voice_call_id`) y se suma en el resumen de la pasada; la tabla de
 * trabajos no tiene columna de coste real (ver el informe de V9).
 *
 * Solo trabaja con la maestra encendida y un modo distinto de `off` (§9.1).
 */
final class VoiceCallOutcomes
{
    public const SOURCE = 'voice_call';

    /** Cuánto hacia atrás se miran trabajos de voz que salieron (días). Una llamada se cierra en minutos; la conciliación del H8, en ≤ 48 h. */
    public const LOOKBACK_DAYS = 8;

    /** Trabajos por pasada (el cupo diario de voz del H8 es ≤ 20). */
    public const BATCH = 200;

    /** Un trabajo `ambiguous` sin llamada pasado este tiempo: nunca la hubo. */
    public const ABSENT_AFTER_MINUTES = 15;

    /** Los estados finales de una llamada (D8-13). */
    public const FINAL_CALL_STATUSES = ['canceled', 'failed_to_start', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    public function __construct(
        private readonly OutreachStore $store = new OutreachStore,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly VoiceCallStates $calls = new VoiceCallStates,
    ) {}

    /** @return array<string, mixed> */
    public function run(DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        $r = ['skipped' => false, 'jobs' => 0, 'linked' => 0, 'recorded' => 0, 'duplicates' => 0, 'ignored' => 0, 'pending' => 0, 'costMicros' => 0, 'outcomes' => []];
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off'] + $r;
        }

        // Solo los que aún no tienen su resultado terminal de la voz (el lote es siempre de llamadas pendientes; índice `oout_job_idx`).
        $trabajos = DB::table(OutreachTables::JOBS.' as j')->where('j.channel', VoiceStrategyChannel::NAME)->where('j.mode', OutreachConfig::MODE_LIVE)
            ->whereIn('j.status', ['started', 'ambiguous'])
            ->where('j.status_changed_at', '>=', OutreachStore::sql($ahora->modify('-'.self::LOOKBACK_DAYS.' days')))
            ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from(OutreachTables::OUTCOMES.' as o')->whereColumn('o.job_id', 'j.id')
                ->where('o.source', self::SOURCE)->where(static fn ($k) => $k->where('o.source_key', 'like', 'vc:%:end')->orWhere('o.source_key', 'like', 'vc:absent:%')))
            ->orderBy('j.id')->limit(self::BATCH)->select('j.*')->get()->all();
        $r['jobs'] = count($trabajos);

        // 1. Los ambiguos sin referencia: su llamada por el uuid del trabajo (una vez, `ojob_refs_once`).
        $sinReferencia = array_map(static fn (object $t): string => (string) $t->uuid, array_filter($trabajos, static fn (object $t): bool => $t->voice_call_id === null));
        $halladas = $this->calls->forStrategyJobs(array_values($sinReferencia));
        foreach ($trabajos as $i => $t) {
            $id = $halladas[strtolower((string) $t->uuid)] ?? null;
            if ($t->voice_call_id === null && $id !== null
                && DB::table(OutreachTables::JOBS)->where('id', (int) $t->id)->whereNull('voice_call_id')->update(['voice_call_id' => $id, 'updated_at' => OutreachStore::sql($ahora)]) === 1) {
                $r['linked']++;
                $trabajos[$i] = $this->store->job((int) $t->id);
            }
        }

        // 2. Los estados de las llamadas, de una vez, y su resultado.
        $estados = $this->calls->forCallIds(array_values(array_filter(array_map(static fn (object $t): ?int => $t->voice_call_id === null ? null : (int) $t->voice_call_id, $trabajos))));
        foreach ($trabajos as $t) {
            $e = $this->store->enrollment((int) $t->enrollment_id);
            if ($e === null) {
                continue;
            }
            if ($t->voice_call_id === null) {
                $desde = OutreachStore::instant($t->status_changed_at);
                if ((string) $t->status === 'ambiguous' && $desde <= $ahora->modify('-'.self::ABSENT_AFTER_MINUTES.' minutes')) {
                    $this->record($r, $e, $t, ['outcome' => 'failed', 'detail' => 'voice_call_absent', 'key' => 'vc:absent:'.$t->uuid, 'at' => $ahora], $ahora);
                } else {
                    $r['pending']++;
                }

                continue;
            }
            $c = $estados[(int) $t->voice_call_id] ?? null;
            if ($c === null) {
                $r['pending']++;

                continue;
            }
            $mapa = self::map($c);
            if (! in_array($c['status'], self::FINAL_CALL_STATUSES, true) && ! $c['spokenOptOut']) {
                $r['pending']++;
            }
            foreach ($mapa as $m) {
                $this->record($r, $e, $t, $m, $ahora);
            }
            if (in_array($c['status'], self::FINAL_CALL_STATUSES, true) && $c['costMicros'] !== null) {
                $r['costMicros'] += $c['costMicros'];
            }
        }

        return $r;
    }

    /**
     * El mapa de D9-10 para UNA llamada: PURO y determinista. Una clave por
     * resultado: `vc:<uuid>:sent` y `vc:<uuid>:end` (como mucho uno terminal
     * por llamada; `opted_out` gana a todo y sale aunque la llamada siga).
     *
     * @param  array{uuid: string, status: string, failureCode: ?string, policyReason: ?string, providerAccepted: bool, guardVerdict: ?string,
     *               spokenOptOut: bool, humanRequested: bool, startedAt: ?DateTimeImmutable, endedAt: ?DateTimeImmutable, statusChangedAt: ?DateTimeImmutable}  $c
     * @return list<array{outcome: string, detail: ?string, key: string, at: ?DateTimeImmutable}>
     */
    public static function map(array $c): array
    {
        $clave = 'vc:'.$c['uuid'];
        $fin = $c['endedAt'] ?? $c['statusChangedAt'];
        $salida = [];
        if ($c['providerAccepted']) {
            $salida[] = ['outcome' => 'sent', 'detail' => null, 'key' => $clave.':sent', 'at' => $c['startedAt'] ?? $c['statusChangedAt']];
        }
        if ($c['spokenOptOut']) {
            $salida[] = ['outcome' => OutcomeTable::OPTED_OUT, 'detail' => 'no_volver_a_llamar', 'key' => $clave.':end', 'at' => $fin];

            return $salida;
        }
        if (! in_array($c['status'], self::FINAL_CALL_STATUSES, true)) {
            return $salida;
        }
        if ($c['humanRequested']) {
            $salida[] = ['outcome' => 'human_requested', 'detail' => 'solicitar_humano', 'key' => $clave.':end', 'at' => $fin];

            return $salida;
        }
        [$resultado, $detalle] = match ($c['status']) {
            'completed' => ['answered', match ($c['guardVerdict']) {
                'clean' => 'guard_clean',
                'violation' => 'guard_violation',
                default => null,
            }],
            'busy' => ['no_answer', 'busy'],
            'no_answer' => ['no_answer', 'no_answer'],
            'failed' => ['failed', $c['failureCode'] ?? 'call_failed'],
            'failed_to_start' => ['failed', $c['failureCode'] ?? 'provider_rejected'],
            'canceled' => ['failed', $c['policyReason'] ?? 'canceled'],
            default => ['failed', 'hung_up'],
        };
        $salida[] = ['outcome' => $resultado, 'detail' => self::code($detalle), 'key' => $clave.':end', 'at' => $fin];

        return $salida;
    }

    /** Un detalle como código de la base (`oout_codes_chk`), o nulo. */
    private static function code(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $valor) === 1 ? $valor : 'voice_unknown';
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array{outcome: string, detail: ?string, key: string, at: ?DateTimeImmutable}  $m
     */
    private function record(array &$r, object $enrollment, object $job, array $m, DateTimeImmutable $ahora): void
    {
        $hecho = $this->outcomes->record($enrollment, $m['outcome'], self::SOURCE, $m['key'], $ahora, [
            'job' => $job,
            'detailCode' => $m['detail'],
            'occurredAt' => $m['at'] ?? $ahora,
        ]);
        $clave = match ($hecho['result']) {
            OutcomeRecorder::RESULT_DUPLICATE => 'duplicates',
            OutcomeRecorder::RESULT_IGNORED => 'ignored',
            default => 'recorded',
        };
        $r[$clave]++;
        if ($clave === 'recorded') {
            $r['outcomes'][$m['outcome']] = ($r['outcomes'][$m['outcome']] ?? 0) + 1;
            Log::info('outreach.voice.outcome', ['job' => (string) $job->uuid, 'outcome' => $m['outcome'], 'detail' => $m['detail'], 'transition' => $hecho['transition']]);
        }
    }
}
