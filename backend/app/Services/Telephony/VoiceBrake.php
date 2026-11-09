<?php

namespace App\Services\Telephony;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Rules\PlainText;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Support\Access\CrmPermission;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * El FRENO de voz (contrato H8, D8-19, [P-17]; fixture `policy-tables.json`
 * §brake; `voice_brakes`, solo inserción).
 *
 * LO ABREN SOLOS ({@see open()}): una violación de la guarda, un sello que no
 * cuadra, la deriva del agente, el techo duro de gasto o un aviso de la
 * subcuenta, y una llamada que Laravel no inició. Abrir es IDEMPOTENTE por su
 * clave (`open_key`, una por causa concreta) y abre además un incidente de
 * IRON GUARD (el mecanismo del monitor del H7), después de confirmar.
 *
 * SE REARMA ({@see openOrRearm()}; E10-6, SEC8-b B-2): las causas que repiten
 * clave (la deriva del agente y la del número, el techo de gasto de un día)
 * vuelven a echarlo si la evidencia cambió después de que una persona lo
 * soltara: una apertura nueva (`<clave>:r<n>`), con su incidente.
 *
 * Con el freno echado (alguna apertura sin soltar): las salientes dan 409
 * `voice_braked`, las entrantes `caller_authorized = no` y las herramientas no
 * responden.
 *
 * SOLO LO SUELTA UNA PERSONA ({@see release()}): con `ultron.voice_publish`,
 * con una nota (PlainText estricto, 1–300 caracteres, sin teléfonos ni
 * correos) y una vez cada apertura (DF-P14). Nunca se suelta solo. El Studio
 * (S8) lo suelta a través de este servicio, nunca escribiendo en la tabla
 * (Enmienda 2, E2-6).
 */
final class VoiceBrake
{
    /**
     * Las causas del fixture (§brake) → el motivo cerrado de la base (DB8).
     * E4-8: la deriva del NÚMERO (apunta a un agente que no es el publicado)
     * es una deriva del agente que atiende (D8-19); la base no tiene otro
     * motivo (`vbr_reason_chk`), así que se guarda como `agent_drift` y la
     * evidencia dice la causa (`trigger`).
     */
    public const TRIGGERS = [
        'guard_violation' => 'guard_violation',
        'seal_mismatch' => 'seal_mismatch',
        'agent_drift' => 'agent_drift',
        'daily_hard_cap_reached' => 'cost_cap',
        'usage_alert' => 'subaccount_alert',
        'unknown_provider_call' => 'unknown_provider_call',
        'number_drift' => 'agent_drift',
    ];

    public const NOTE_MAX = 300;

    public const INCIDENT_SOURCE = 'voice';

    public const INCIDENT_KIND = 'voice_brake';

    /**
     * Las reaperturas de una causa (E10-6) llevan su generación en la clave:
     * `<clave>:r2`, `:r3`… mientras quepa en `open_key` (128,
     * `VoiceEvents::KEY_PATTERN`); con una clave más larga (ids del proveedor de
     * hasta 128), `rearm:<sha256 de la clave>:r<n>`. Los ids no llevan `:`
     * (`VoiceIds`): ninguna clave de primera apertura cae en una familia.
     */
    public const REARM_READABLE_MAX = 120;

    /** ¿Abre el freno esta causa? (pura, paridad con el fixture). */
    public static function opensFor(string $trigger): bool
    {
        return array_key_exists($trigger, self::TRIGGERS);
    }

    /** ¿Está echado? (alguna apertura sin su suelta). Una sentencia. */
    public static function isOpen(): bool
    {
        return VoiceBrakeEntry::query()->from('voice_brakes as o')
            ->where('o.kind', VoiceBrakeEntry::KIND_OPEN)
            ->whereNotExists(fn ($q) => $q->from('voice_brakes as r')->whereColumn('r.brake_id', 'o.id'))
            ->exists();
    }

    /**
     * Abre el freno. Devuelve la apertura nueva, o null si esa misma causa ya
     * lo había abierto (idempotente por `open_key`). `$reason` es un motivo de
     * la base o una causa de {@see TRIGGERS} (se guarda su motivo y, si es
     * otro, la causa en la evidencia).
     *
     * @param  array<string, int|string|bool|null|list<mixed>>  $evidence  códigos y cifras, nunca texto ni teléfonos
     */
    public static function open(string $reason, string $openKey, array $evidence = [], ?int $callId = null, ?int $eventId = null): ?VoiceBrakeEntry
    {
        $causa = $reason;
        $reason = self::TRIGGERS[$causa] ?? $causa;
        if (! in_array($reason, VoiceBrakeEntry::REASONS, true) || preg_match(VoiceEvents::KEY_PATTERN, $openKey) !== 1) {
            throw new \InvalidArgumentException('Motivo o clave del freno fuera de su forma.');
        }
        if ($causa !== $reason) {
            $evidence = ['trigger' => $causa] + $evidence;
        }
        $fila = (new VoiceBrakeEntry)->forceFill([
            'kind' => VoiceBrakeEntry::KIND_OPEN,
            'reason' => $reason,
            'open_key' => $openKey,
            'call_id' => $callId,
            'event_id' => $eventId,
            'evidence' => $evidence === [] ? null : $evidence,
            'actor_type' => 'system',
            'actor_admin_id' => null,
            'actor_name' => 'Sistema (freno automático)',
        ]);
        try {
            DB::transaction(static fn () => $fila->save());
        } catch (UniqueConstraintViolationException) {
            // La misma causa ya lo abrió: abrir es idempotente.
            return null;
        }

        Log::channel('voice')->warning('voice.brake.opened', ['reason' => $reason, 'brake_uuid' => $fila->uuid, 'call_id' => $callId]);
        DB::afterCommit(static function () use ($reason, $fila): void {
            self::incident($reason, (string) $fila->uuid);
        });

        return $fila;
    }

    /**
     * Abre el freno por una causa que se REPITE con la misma clave (E10-6,
     * SEC8-b B-2): la deriva del agente (`drift:<agente>`), la del número
     * (`number:<id>:<agente>`) y el techo de gasto (`cost_cap:<día>`).
     *  - Nunca abierta: como {@see open()}.
     *  - Su última apertura sigue echada: idempotente (null).
     *  - Una persona ya la soltó: se abre OTRA (la generación siguiente de la
     *    clave, con `rearmOf` en la evidencia y su incidente) SOLO si
     *    `$changedAfterRelease`, con la apertura soltada y su suelta, dice que
     *    la evidencia cambió después de soltarla (una observación posterior,
     *    {@see observedAfter()}; un gasto mayor que el registrado). La misma
     *    evidencia no la reabre: soltarla con la causa corregida se respeta.
     *
     * @param  array<string, int|string|bool|null|list<mixed>>  $evidence  códigos y cifras, nunca texto ni teléfonos
     * @param  Closure(VoiceBrakeEntry, VoiceBrakeEntry): bool  $changedAfterRelease
     */
    public static function openOrRearm(string $reason, string $openKey, array $evidence, Closure $changedAfterRelease, ?int $callId = null, ?int $eventId = null): ?VoiceBrakeEntry
    {
        $plan = self::nextOpening($openKey, $changedAfterRelease);
        if ($plan === null) {
            return null;
        }
        [$clave, $soltada] = $plan;

        return self::open($reason, $clave, $soltada === null ? $evidence : $evidence + ['rearmOf' => (string) $soltada->uuid], $callId, $eventId);
    }

    /**
     * SIN ESCRIBIR (lo usa también `voice:reconcile --dry-run`, E10-9): la
     * clave con la que se abriría ahora y la apertura soltada a la que sucede
     * (null la primera vez), o null si no se abriría. Sin
     * `$changedAfterRelease`, la regla de {@see open()}: cada clave, una vez.
     *
     * @param  (Closure(VoiceBrakeEntry, VoiceBrakeEntry): bool)|null  $changedAfterRelease
     * @return array{0: string, 1: ?VoiceBrakeEntry}|null
     */
    public static function nextOpening(string $openKey, ?Closure $changedAfterRelease = null): ?array
    {
        if ($changedAfterRelease === null) {
            return VoiceBrakeEntry::query()->where('open_key', $openKey)->exists() ? null : [$openKey, null];
        }
        $familia = strlen($openKey) <= self::REARM_READABLE_MAX ? $openKey.':r' : 'rearm:'.hash('sha256', $openKey).':r';
        $generaciones = [];
        $aperturas = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)
            ->where(static fn ($q) => $q->where('open_key', $openKey)->orWhereRaw('substr(open_key, 1, '.strlen($familia).') = ?', [$familia]))
            ->get();
        foreach ($aperturas as $a) {
            $resto = substr((string) $a->open_key, strlen($familia));
            if ($a->open_key === $openKey) {
                $generaciones[1] = $a;
            } elseif (str_starts_with((string) $a->open_key, $familia) && preg_match('/^[1-9][0-9]{0,5}$/D', $resto) === 1) {
                $generaciones[(int) $resto] = $a;
            }
        }
        if ($generaciones === []) {
            return [$openKey, null];
        }
        $n = max(array_keys($generaciones));
        $ultima = $generaciones[$n];
        $suelta = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_RELEASE)->where('brake_id', $ultima->id)->first();
        if ($suelta === null || ! $changedAfterRelease($ultima, $suelta)) {
            return null;
        }

        return [$familia.($n + 1), $ultima];
    }

    /**
     * La regla de las derivas (E10-6): una observación hecha DESPUÉS de soltar
     * es evidencia nueva. `$observedAt` es el instante en que empezó a
     * observarse (antes de la petición). La suelta se guarda a segundos: una
     * observación del mismo segundo cuenta como posterior (falla cerrado).
     *
     * @return Closure(VoiceBrakeEntry, VoiceBrakeEntry): bool
     */
    public static function observedAfter(DateTimeInterface $observedAt): Closure
    {
        $observada = DateTimeImmutable::createFromInterface($observedAt);

        return static fn (VoiceBrakeEntry $abierta, VoiceBrakeEntry $suelta): bool => $suelta->created_at === null || $observada > $suelta->created_at;
    }

    /**
     * Suelta UNA apertura. 403 sin persona o sin `ultron.voice_publish`; 404
     * si no existe; 409 si ya estaba suelta; 422 si la nota no vale.
     *
     * @throws TelephonyException
     */
    public static function release(?Admin $actor, string $brakeUuid, string $note, string $clientRequestId): VoiceBrakeEntry
    {
        if (! $actor instanceof Admin) {
            throw TelephonyException::personRequired();
        }
        if (! CrmPermission::allows($actor, CrmPermission::ULTRON_VOICE_PUBLISH)) {
            throw TelephonyException::forbidden(CrmPermission::ULTRON_VOICE_PUBLISH);
        }
        $errores = self::noteErrors($note);
        if ($errores !== [] || ! Str::isUuid($clientRequestId)) {
            throw TelephonyException::invalid($errores + ($errores === [] ? ['clientRequestId' => ['uuid']] : []));
        }

        return DB::transaction(function () use ($actor, $brakeUuid, $note, $clientRequestId): VoiceBrakeEntry {
            VoiceLocks::take(VoiceLocks::BRAKE);
            $apertura = VoiceBrakeEntry::query()->where('uuid', strtolower($brakeUuid))->where('kind', VoiceBrakeEntry::KIND_OPEN)->first();
            if ($apertura === null) {
                throw TelephonyException::notFound('brake');
            }
            $previa = VoiceBrakeEntry::query()->where('client_request_id', strtolower($clientRequestId))->first();
            if ($previa !== null) {
                if ($previa->brake_id === $apertura->id && $previa->actor_admin_id === $actor->id) {
                    return $previa;
                }
                throw TelephonyException::conflict('client_request_reused', 'Esa petición ya se usó para otra cosa.');
            }
            if (VoiceBrakeEntry::query()->where('brake_id', $apertura->id)->exists()) {
                throw TelephonyException::conflict('brake_already_released', 'Ese freno ya está suelto: se suelta una sola vez.');
            }
            $suelta = (new VoiceBrakeEntry)->forceFill([
                'kind' => VoiceBrakeEntry::KIND_RELEASE,
                'brake_id' => $apertura->id,
                'note' => $note,
                'client_request_id' => strtolower($clientRequestId),
                'actor_type' => 'admin',
                'actor_admin_id' => $actor->id,
                'actor_name' => (string) $actor->name,
                'actor_role' => $actor->role,
            ]);
            try {
                $suelta->save();
            } catch (UniqueConstraintViolationException) {
                // Otra petición la soltó a la vez (`vbr_release_once`).
                throw TelephonyException::conflict('brake_already_released', 'Ese freno ya está suelto: se suelta una sola vez.');
            }
            Log::channel('voice')->info('voice.brake.released', ['brake_uuid' => $apertura->uuid, 'reason' => $apertura->reason, 'actor_admin_id' => $actor->id]);

            return $suelta;
        });
    }

    /**
     * Las aperturas (las vivas primero), sin datos personales.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(int $limit = 50): array
    {
        $sueltas = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_RELEASE)->get()->keyBy('brake_id');

        return VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderByDesc('id')->limit(max(1, min(200, $limit)))->get()
            ->map(static function (VoiceBrakeEntry $a) use ($sueltas): array {
                $s = $sueltas->get($a->id);

                return [
                    'uuid' => $a->uuid,
                    'reason' => $a->reason,
                    'openedAt' => $a->created_at?->toIso8601String(),
                    'evidence' => $a->evidence,
                    'released' => $s !== null,
                    'releasedAt' => $s?->created_at?->toIso8601String(),
                    'releasedBy' => $s?->actor_name,
                    'note' => $s?->note,
                ];
            })
            ->sortBy(static fn (array $f): int => $f['released'] ? 1 : 0)
            ->values()
            ->all();
    }

    /** @return array<string, list<string>> los fallos de la nota, por código */
    public static function noteErrors(string $note): array
    {
        $v = Validator::make(['note' => $note], ['note' => ['required', 'string', 'min:1', 'max:'.self::NOTE_MAX, new PlainText(strict: true)]]);
        if ($v->fails()) {
            return ['note' => ['invalid']];
        }
        if (preg_match(StudioCanaryPhone::CONTACT_DIGIT_RUN, $note) === 1 || preg_match(StudioCanaryPhone::CONTACT_EMAIL, $note) === 1) {
            return ['note' => [StudioCanaryPhone::CONTACT_DATA]];
        }

        return [];
    }

    /** El incidente de IRON GUARD de una apertura. Si no se puede abrir, el freno sigue echado y se registra. */
    private static function incident(string $reason, string $brakeUuid): void
    {
        try {
            app(IncidentRecorder::class)->record([
                'source' => self::INCIDENT_SOURCE,
                'kind' => self::INCIDENT_KIND,
                'fingerprint_keys' => [$reason],
                'title' => 'Freno de voz echado: '.$reason.'. Ninguna llamada nueva hasta que una persona lo suelte.',
                'severity' => Incident::SEVERITY_HIGH,
                'evidence' => ['reason' => $reason, 'brake_uuid' => $brakeUuid, 'runbook' => 'backend/docs/ultron-studio/ULTRON_STUDIO_ROLLBACK.md'],
            ]);
        } catch (Throwable $e) {
            // El freno ya está echado (lo que protege); el incidente es el aviso.
            Log::channel('voice')->error('voice.brake.incident_failed', ['reason' => $reason, 'brake_uuid' => $brakeUuid, 'error_class' => $e::class]);
        }
    }
}
