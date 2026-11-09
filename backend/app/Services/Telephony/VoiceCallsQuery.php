<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use Illuminate\Support\Carbon;

/**
 * Las lecturas del registro de llamadas para el CRM (contrato H8, D8-22:
 * `telephony.view`, «sin datos personales»). De cada llamada: su estado, su
 * sello, su coste y sus tiempos; del otro extremo solo los 4 últimos dígitos
 * (nunca la huella, el número ni el alias de un canario); de los eventos, el
 * tipo, el resultado y los códigos (sin texto), estos como una lista plana de
 * códigos cortos ({@see eventCodes()}).
 */
final class VoiceCallsQuery
{
    public const STATUS_FILTERS = ['requested', 'canceled', 'failed_to_start', 'ambiguous', 'dialing', 'active', 'denied', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    /**
     * Cada código de un evento en el detalle, como lo lee el CRM
     * (`studio-voice-parse.ts`, `codigos()` con `RE_CODIGO`; un evento con otra
     * forma se descarta allí).
     */
    public const EVENT_CODE_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/D';

    /** Códigos de un evento, como mucho (el CRM admite 64): si sobran, el último es `y_<n>_mas`. */
    public const MAX_EVENT_CODES = 50;

    /**
     * Las claves de `codes` que se enseñan, con su valor (`clave_valor`). Las
     * demás no salen: los ids del proveedor (`conversationId`, `callSid`; el
     * registro no los da) y la clase de un error (`error_class`).
     */
    private const SHOWN_KEYS = ['reason', 'http', 'scope', 'seq', 'part', 'where', 'caller', 'publication', 'carrierStatus', 'before', 'after',
        'errorCode', 'reservedMicros', 'durationSeconds', 'costRaw', 'agentTurns', 'toolCalls', 'micros', 'unit'];

    /** Las banderas: `clave` si es cierta, `clave_no` si no. */
    private const FLAG_KEYS = ['hasAudio', 'afterCancel'];

    /**
     * @param  array{status?: ?string, direction?: ?string}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public static function list(array $filters, int $page, int $perPage): array
    {
        $q = VoiceCall::query()->whereNull('transfer_of_call_id')->orderByDesc('id');
        if (in_array($filters['status'] ?? null, self::STATUS_FILTERS, true)) {
            $q->where('status', $filters['status']);
        }
        if (in_array($filters['direction'] ?? null, [VoiceCall::DIRECTION_INBOUND, VoiceCall::DIRECTION_OUTBOUND], true)) {
            $q->where('direction', $filters['direction']);
        }
        $p = $q->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));

        return [
            'data' => array_map(self::row(...), $p->items()),
            'meta' => ['page' => $p->currentPage(), 'perPage' => $p->perPage(), 'total' => $p->total(), 'lastPage' => $p->lastPage()],
        ];
    }

    /** @return array<string, mixed>|null la llamada con sus eventos, o null */
    public static function show(string $uuid): ?array
    {
        $llamada = VoiceCall::query()->where('uuid', strtolower($uuid))->first();
        if ($llamada === null) {
            return null;
        }
        $eventos = VoiceCallEvent::query()->where('call_id', $llamada->id)->orderBy('id')->limit(200)->get()
            ->map(static fn (VoiceCallEvent $e): array => [
                'kind' => $e->kind,
                'provider' => $e->provider,
                'tool' => $e->tool,
                'outcome' => $e->outcome,
                'codes' => self::eventCodes($e->codes),
                'actor' => $e->actor_type === VoiceCallEvent::ACTOR_ADMIN ? $e->actor_name : $e->actor_type,
                'occurredAt' => $e->occurred_at?->toIso8601String(),
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all();

        return self::row($llamada) + ['events' => $eventos];
    }

    /**
     * Los `codes` de un evento PARA EL CRM (SEC8-b (3), O-1), sin cambiar lo
     * guardado: una lista plana de códigos cortos y pintables, cada uno con
     * {@see EVENT_CODE_PATTERN} y como mucho {@see MAX_EVENT_CODES}.
     *  - una clave de {@see SHOWN_KEYS} con su valor: `reason_no_answer`,
     *    `carrier_status_completed`, `seq_3`, `http_200`;
     *  - una bandera: `after_cancel` o `has_audio_no`;
     *  - los motivos de la iniciación: `reason_<motivo>` cada uno;
     *  - las violaciones de la guarda: `<código>_turno_<n>`, en orden de turno;
     *  - las que no se guardaron (`truncated`, E10-4) y las que no caben: el
     *    último, `y_<n>_mas`.
     * Sin códigos (null o vacío), `[]`. Ni teléfonos ni texto libre: solo salen
     * códigos y números escritos por la telefonía; lo que no tiene esa forma
     * se omite.
     *
     * @return list<string>
     */
    public static function eventCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }
        $out = [];
        $mas = 0;
        foreach ($codes as $clave => $valor) {
            if ($clave === 'violations' && is_array($valor)) {
                foreach ($valor as $v) {
                    if (is_array($v) && is_string($v['code'] ?? null) && is_int($v['turn'] ?? null)) {
                        $out[] = self::violationCode($v['code'], $v['turn']);
                    }
                }
            } elseif ($clave === 'reasons' && is_array($valor)) {
                foreach ($valor as $motivo) {
                    $out[] = is_string($motivo) ? self::code('reason_'.$motivo) : null;
                }
            } elseif ($clave === 'truncated' && is_int($valor)) {
                $mas += max(0, $valor);
            } elseif (in_array($clave, self::FLAG_KEYS, true) && is_bool($valor)) {
                $out[] = self::code(self::snake($clave).($valor ? '' : '_no'));
            } elseif (in_array($clave, self::SHOWN_KEYS, true) && (is_string($valor) || is_int($valor) || is_float($valor))) {
                $out[] = self::code(self::snake($clave).'_'.$valor);
            }
        }
        $out = array_values(array_filter($out, static fn (?string $c): bool => $c !== null));
        if ($mas === 0 && count($out) <= self::MAX_EVENT_CODES) {
            return $out;
        }
        $vistos = array_slice($out, 0, self::MAX_EVENT_CODES - 1);

        return [...$vistos, 'y_'.(count($out) - count($vistos) + $mas).'_mas'];
    }

    /** `<código>_turno_<n>`, recortando el código si no cabe en los 48 caracteres. */
    private static function violationCode(string $code, int $turn): string
    {
        $sufijo = '_turno_'.max(0, $turn);
        $base = rtrim(substr(self::code($code) ?? 'violacion', 0, 48 - strlen($sufijo)), '_');

        return self::code(($base === '' ? 'violacion' : $base).$sufijo) ?? 'violacion';
    }

    /** Un texto como código del CRM (`[a-z0-9_]`, empieza por letra, ≤ 48), o null si no queda ninguno. */
    private static function code(string $raw): ?string
    {
        $c = rtrim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($raw)), '_'), 0, 48), '_');

        return preg_match(self::EVENT_CODE_PATTERN, $c) === 1 ? $c : null;
    }

    /** `carrierStatus` → `carrier_status`. */
    private static function snake(string $clave): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $clave));
    }

    /** @return array<string, mixed> una llamada, sin datos personales */
    public static function row(VoiceCall $c): array
    {
        return [
            'uuid' => $c->uuid,
            'direction' => $c->direction,
            'status' => $c->status,
            'targetSource' => $c->target_source,
            'remoteLast4' => $c->remote_last4,
            'callerAuthorized' => $c->caller_authorized,
            'policyReason' => $c->policy_reason,
            'attempt' => $c->attempt,
            'requestedBy' => $c->requested_by_name,
            'seal' => [
                'state' => $c->stamp_state,
                'seq' => $c->voice_publication_seq,
                'part' => $c->part,
                'providerAgentId' => $c->provider_agent_id,
                'compilerVersion' => $c->compiler_version,
                'verified' => $c->seal_verified,
            ],
            'toolCalls' => $c->tool_calls,
            'durationSeconds' => $c->duration_seconds,
            'failureCode' => $c->failure_code,
            'terminationReason' => $c->termination_reason,
            'cost' => [
                'reservedMicros' => $c->reserved_micros,
                'providerMicros' => $c->provider_cost_micros,
                'carrierMicros' => $c->carrier_cost_micros,
                'budgetDay' => (string) $c->budget_day,
            ],
            'createdAt' => $c->created_at?->toIso8601String(),
            'startedAt' => $c->started_at?->toIso8601String(),
            'endedAt' => $c->ended_at?->toIso8601String(),
        ];
    }

    /**
     * Las métricas de un día de Bogotá: recuentos por estado y sentido, coste y
     * salud. Quien ya leyó la cadena (`$parts`, la salida de
     * {@see VoicePublishedConfig::read()}) y el freno los pasa: `status` los lee
     * UNA vez para todo (PERF8, P8-1); si no, se leen aquí.
     *
     * @param  array<string, VoicePublishedPart>|null  $parts
     * @return array<string, mixed>
     */
    public static function metrics(?string $budgetDay = null, ?array $parts = null, ?bool $brakeOpen = null): array
    {
        $dia = $budgetDay ?? VoiceCostGuard::budgetDay(now());
        $porEstado = VoiceCall::query()->where('budget_day', $dia)->whereNull('transfer_of_call_id')
            ->selectRaw('direction, status, count(*) as n')->groupBy('direction', 'status')->get()
            ->map(static fn ($f): array => ['direction' => $f->direction, 'status' => $f->status, 'count' => (int) $f->n])->all();
        $ultimaEntrega = VoiceCallEvent::query()->whereIn('kind', ['post_call_transcription', 'call_initiation_failure'])->max('created_at');
        $freno = $brakeOpen ?? VoiceBrake::isOpen();
        // La evidencia de la entrada vigente de cada parte (E4-2: la de promover la mide el Studio con `forPublication`).
        $publicaciones = [];
        foreach ($parts ?? VoicePublishedConfig::read() as $nombre => $parte) {
            $publicaciones[$nombre] = $parte->ok() ? VoiceEvidence::summary($nombre, (int) $parte->seq, $freno) : ['part' => $nombre, 'status' => $parte->status];
        }

        return [
            'budgetDay' => $dia,
            'byStatus' => $porEstado,
            'cost' => ['committedMicros' => VoiceCostGuard::committedMicros($dia), 'budgetMicros' => VoiceCostGuard::budgetFromConfig(), 'pricesAsOf' => VoiceCostGuard::prices()['as_of']],
            'live' => VoiceCallFacts::liveCount(),
            'ambiguous' => VoiceCall::query()->where('status', VoiceCall::STATUS_AMBIGUOUS)->count(),
            'stuckDialing' => VoiceCall::query()->where('status', VoiceCall::STATUS_DIALING)->where('status_changed_at', '<', now()->subMinutes(VoiceReconciler::STUCK_DIALING_MINUTES))->count(),
            'lastWebhookAt' => $ultimaEntrega === null ? null : Carbon::parse($ultimaEntrega)->toIso8601String(),
            'brakeOpen' => $freno,
            'publications' => $publicaciones,
        ];
    }
}
