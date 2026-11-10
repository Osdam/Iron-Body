<?php

namespace App\Services\Outreach\Run;

use App\Models\Admin;
use App\Models\Incident;
use App\Rules\PlainText;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Outreach\Core\OutreachCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Los FRENOS y la PARADA GLOBAL del H9 (contrato H9, D9-14, §6.2
 * `outreach_brakes`, §9.2 nivel 0b, L9-7, P9-20; T15).
 *
 * UN FRENO SE ABRE Y SE SUELTA, UNA VEZ CADA COSA, y como mucho hay uno abierto
 * por alcance (`obrk_one_open`): `global` (la PARADA GLOBAL), `strategy`
 * (el uuid de la estrategia) o `channel` (`assisted`, `whatsapp_template`,
 * `voice_call`). Lo abre una PERSONA con `commercial_automation.stop`
 * (motivo `manual` o `legal_hold`, con una nota) o el MONITOR solo
 * ({@see openBySystem()}: `opt_out_spike`, `failure_spike`, `budget_breach`,
 * `unknown_send`, `chain_unreadable`, `drift`), que además abre un incidente
 * de IRON GUARD (fuente `outreach`).
 *
 * EFECTO (D9-14): con el global abierto no nacen inscripciones (solo
 * recuentos) y el despachador no reclama; los trabajos `planned` ESPERAN. Con
 * uno de estrategia o de canal, lo mismo en su ámbito (N2 de la escalera).
 * En ≤ 1 pasada del despachador nada nuevo sale. La respuesta de la PARADA
 * GLOBAL dice cuántos trabajos quedaron retenidos.
 *
 * SOLO LO SUELTA UNA PERSONA ({@see release()}) con
 * `commercial_automation.release`, una nota (`PlainText` estricto, sin
 * teléfonos ni correos) y A CUATRO OJOS DE VERDAD (L9-7): otra CUENTA y otra
 * PERSONA (vínculo verificado) que quien lo abrió. El CHECK de la base
 * (`obrk_four_eyes_chk`) y el disparador «se suelta una vez» lo respaldan.
 * NUNCA se suelta solo.
 *
 * LA CONSOLA (`outreach:stop --all --reason=`, L9-44): el mando de OPS9 echa
 * la PARADA GLOBAL sin una cuenta del CRM. La base exige que un freno
 * `manual` lo abra una persona (`opened_by_admin_id` no nulo), así que la
 * consola queda registrada con la cuenta centinela {@see CONSOLE_ADMIN_ID}
 * (0, que no es ninguna cuenta) y el nombre `consola:outreach:stop`, o con la
 * cuenta que se pase en `--by=`. Soltarlo exige igualmente una persona con la
 * llave y una nota.
 */
final class OutreachStop
{
    public const STOP = 'commercial_automation.stop';

    public const RELEASE = 'commercial_automation.release';

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_STRATEGY = 'strategy';

    public const SCOPE_CHANNEL = 'channel';

    public const SCOPES = [self::SCOPE_GLOBAL, self::SCOPE_STRATEGY, self::SCOPE_CHANNEL];

    public const REASON_MANUAL = 'manual';

    public const REASON_LEGAL_HOLD = 'legal_hold';

    public const PERSON_REASONS = [self::REASON_MANUAL, self::REASON_LEGAL_HOLD];

    public const SYSTEM_REASONS = ['opt_out_spike', 'failure_spike', 'budget_breach', 'unknown_send', 'chain_unreadable', 'drift'];

    /** El alcance que admite cada causa del monitor (`obrk_cause_scope_chk`). */
    public const SYSTEM_REASON_SCOPES = [
        'opt_out_spike' => [self::SCOPE_STRATEGY], 'failure_spike' => [self::SCOPE_CHANNEL], 'budget_breach' => [self::SCOPE_CHANNEL],
        'unknown_send' => [self::SCOPE_GLOBAL], 'chain_unreadable' => [self::SCOPE_GLOBAL, self::SCOPE_STRATEGY], 'drift' => [self::SCOPE_STRATEGY],
    ];

    /** Los canales que se pueden frenar (el simulado no sale hacia nadie). */
    public const CHANNELS = ['assisted', OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE, OutreachCatalog::CHANNEL_VOICE_CALL];

    public const NOTE_MIN = 3;

    public const NOTE_MAX = 500;

    /** La cuenta centinela de la consola (no existe ninguna cuenta 0). */
    public const CONSOLE_ADMIN_ID = 0;

    public const CONSOLE_NAME = 'consola:outreach:stop';

    public const INCIDENT_SOURCE = 'outreach';

    public const INCIDENT_KIND = 'outreach_brake';

    private const DIGIT_RUN = '/\d(?:[\s().-]*\d){6,}/';

    private const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public function __construct(private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies) {}

    // ── Lectura ─────────────────────────────────────────────────────────────

    /** ¿Está echada la PARADA GLOBAL? Una sentencia. */
    public function isGlobalOpen(): bool
    {
        return DB::table(OutreachTables::BRAKES)->where('scope', self::SCOPE_GLOBAL)->whereNull('released_at')->exists();
    }

    /**
     * Lo abierto ahora, por alcance: lo que N2 mira en cada pasada (una consulta).
     *
     * @return array{global: bool, channels: list<string>, strategies: list<string>}
     */
    public function openScopes(): array
    {
        $salida = ['global' => false, 'channels' => [], 'strategies' => []];
        foreach (DB::table(OutreachTables::BRAKES)->whereNull('released_at')->get(['scope', 'scope_ref']) as $f) {
            match ((string) $f->scope) {
                self::SCOPE_GLOBAL => $salida['global'] = true,
                self::SCOPE_CHANNEL => $salida['channels'][] = (string) $f->scope_ref,
                self::SCOPE_STRATEGY => $salida['strategies'][] = strtolower((string) $f->scope_ref),
                default => null,
            };
        }
        sort($salida['channels'], SORT_STRING);
        sort($salida['strategies'], SORT_STRING);

        return $salida;
    }

    /** Los frenos de la estrategia y del canal de un trabajo, como los espera `brakes` de la escalera. @return array{global: bool, channel: bool, strategy: bool} */
    public static function ladderBrakes(array $open, string $strategyUuid, string $jobChannel): array
    {
        return [
            'global' => $open['global'],
            'channel' => in_array($jobChannel, $open['channels'], true),
            'strategy' => in_array(strtolower($strategyUuid), $open['strategies'], true),
        ];
    }

    /**
     * Las aperturas (las vivas primero), sin datos personales: solo el nombre
     * del personal que abrió o soltó (los únicos nombres de las tablas).
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        return DB::table(OutreachTables::BRAKES)->orderByRaw('CASE WHEN released_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))->get()->map(self::dto(...))->all();
    }

    /** @return array<string, mixed> */
    public static function dto(object $b): array
    {
        return [
            'uuid' => (string) $b->uuid,
            'scope' => (string) $b->scope,
            'scopeRef' => $b->scope_ref !== null ? (string) $b->scope_ref : null,
            'reason' => (string) $b->reason,
            'note' => $b->note !== null ? (string) $b->note : null,
            'openedBy' => ['type' => (string) $b->opened_by_type, 'name' => (string) $b->opened_by_name],
            'openedAt' => self::iso($b->opened_at),
            'released' => $b->released_at !== null,
            'releasedAt' => self::iso($b->released_at),
            'releasedBy' => $b->released_by_name !== null ? (string) $b->released_by_name : null,
            'releaseNote' => $b->release_note !== null ? (string) $b->release_note : null,
        ];
    }

    // ── Abrir ───────────────────────────────────────────────────────────────

    /**
     * Abre un freno por una PERSONA (o la consola, con `$by` null). Idempotente
     * por `clientRequestId` y por alcance: si ese alcance ya está frenado se
     * devuelve el abierto (`replayed`), porque pedir la parada dos veces no es
     * un error. Devuelve también los trabajos retenidos (los vivos del
     * alcance) para la respuesta de D9-14.
     *
     * @return array{brake: array<string, mixed>, replayed: bool, retainedJobs: int}
     *
     * @throws OutreachException 422 con el alcance, el motivo o la nota mal
     */
    public function open(string $scope, ?string $scopeRef, string $reason, string $note, ?Admin $by, string $clientRequestId): array
    {
        $errores = $this->scopeErrors($scope, $scopeRef) + $this->noteErrors($note, 'note');
        if (! in_array($reason, self::PERSON_REASONS, true)) {
            $errores['reason'] = ['invalid'];
        }
        if (! Str::isUuid($clientRequestId)) {
            $errores['clientRequestId'] = ['uuid'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $scopeRef = $scope === self::SCOPE_GLOBAL ? null : ($scope === self::SCOPE_STRATEGY ? strtolower((string) $scopeRef) : $scopeRef);
        $persona = $by === null ? null : $this->bridge->personOf((int) $by->id);

        return DB::transaction(function () use ($scope, $scopeRef, $reason, $note, $by, $clientRequestId, $persona): array {
            $previo = DB::table(OutreachTables::BRAKES)->where('client_request_id', strtolower($clientRequestId))->first();
            if ($previo !== null) {
                return ['brake' => self::dto($previo), 'replayed' => true, 'retainedJobs' => $this->retainedJobs($scope, $scopeRef)];
            }
            $abierto = $this->openRow($scope, $scopeRef);
            if ($abierto !== null) {
                return ['brake' => self::dto($abierto), 'replayed' => true, 'retainedJobs' => $this->retainedJobs($scope, $scopeRef)];
            }
            $fila = [
                'uuid' => (string) Str::uuid(),
                'client_request_id' => strtolower($clientRequestId),
                'scope' => $scope,
                'scope_ref' => $scopeRef,
                'reason' => $reason,
                'note' => $note,
                'opened_by_type' => 'person',
                'opened_by_admin_id' => $by === null ? self::CONSOLE_ADMIN_ID : (int) $by->id,
                'opened_by_name' => $by === null ? self::CONSOLE_NAME : mb_substr((string) $by->name, 0, 120),
                'opened_by_person_id' => $persona['identityId'] ?? null,
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            try {
                $id = (int) DB::table(OutreachTables::BRAKES)->insertGetId($fila);
            } catch (UniqueConstraintViolationException) {
                // Otra petición lo abrió a la vez (`obrk_one_open`): abrir es idempotente.
                $abierto = $this->openRow($scope, $scopeRef);
                if ($abierto !== null) {
                    return ['brake' => self::dto($abierto), 'replayed' => true, 'retainedJobs' => $this->retainedJobs($scope, $scopeRef)];
                }
                throw OutreachException::conflict('client_request_reused', 'Esa petición ya se usó para otra cosa.');
            }
            Log::info('outreach.brake.opened', ['scope' => $scope, 'scope_ref' => $scopeRef, 'reason' => $reason, 'by' => $fila['opened_by_admin_id']]);

            return ['brake' => self::dto(DB::table(OutreachTables::BRAKES)->where('id', $id)->first()), 'replayed' => false, 'retainedJobs' => $this->retainedJobs($scope, $scopeRef)];
        });
    }

    /** La PARADA GLOBAL (`outreach:stop --all`, el botón del panel). @return array{brake: array<string, mixed>, replayed: bool, retainedJobs: int} */
    public function stopAll(string $reason, ?Admin $by, ?string $clientRequestId = null): array
    {
        return $this->open(self::SCOPE_GLOBAL, null, self::REASON_MANUAL, $reason, $by, $clientRequestId ?? (string) Str::uuid());
    }

    /**
     * Lo abre el MONITOR solo (D9-14): idempotente por alcance (uno abierto),
     * con su causa de la lista cerrada y, al confirmar, un incidente de IRON
     * GUARD. Devuelve el freno nuevo o null si ya estaba abierto. Nunca lanza
     * por un fallo del incidente: el freno es lo que protege.
     *
     * @param  array<string, int|string|bool|null|list<mixed>>  $evidence  códigos y cifras, nunca texto ni teléfonos
     * @return array<string, mixed>|null
     */
    public function openBySystem(string $scope, ?string $scopeRef, string $reason, array $evidence = []): ?array
    {
        if (! in_array($reason, self::SYSTEM_REASONS, true) || ! in_array($scope, self::SYSTEM_REASON_SCOPES[$reason], true) || $this->scopeErrors($scope, $scopeRef) !== []) {
            throw new \InvalidArgumentException('Causa o alcance del freno fuera de su forma.');
        }
        $scopeRef = $scope === self::SCOPE_GLOBAL ? null : ($scope === self::SCOPE_STRATEGY ? strtolower((string) $scopeRef) : $scopeRef);
        if ($this->openRow($scope, $scopeRef) !== null) {
            return null;
        }
        $uuid = (string) Str::uuid();
        try {
            DB::table(OutreachTables::BRAKES)->insert([
                'uuid' => $uuid,
                'client_request_id' => null,
                'scope' => $scope,
                'scope_ref' => $scopeRef,
                'reason' => $reason,
                'note' => null,
                'opened_by_type' => 'system',
                'opened_by_admin_id' => null,
                'opened_by_name' => 'Sistema (monitor outreach)',
                'opened_by_person_id' => null,
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra pasada lo abrió a la vez: idempotente.
            return null;
        }
        Log::warning('outreach.brake.opened_by_system', ['scope' => $scope, 'scope_ref' => $scopeRef, 'reason' => $reason, 'brake_uuid' => $uuid]);
        $this->incident($scope, $scopeRef, $reason, $uuid, $evidence);

        return self::dto(DB::table(OutreachTables::BRAKES)->where('uuid', $uuid)->first());
    }

    // ── Soltar ──────────────────────────────────────────────────────────────

    /**
     * Suelta UNA apertura, a cuatro ojos (L9-7): otra cuenta Y otra persona
     * verificada que quien lo abrió. 404 si no existe; 409 si ya estaba suelto
     * o la petición se repite para otra cosa; 422 con la nota mal; 403 si lo
     * abrió la misma cuenta o la misma persona. Idempotente por
     * `clientRequestId` (la misma petición de la misma cuenta devuelve lo
     * mismo). Quien suelta sin vínculo verificado SÍ puede soltar lo que abrió
     * OTRA cuenta sin vínculo: la cuenta distinta ya niega lo propio; la
     * persona se compara cuando se conoce.
     *
     * @return array<string, mixed>
     */
    public function release(Admin $by, string $brakeUuid, string $note, string $clientRequestId): array
    {
        $errores = $this->noteErrors($note, 'note');
        if (! Str::isUuid($clientRequestId)) {
            $errores['clientRequestId'] = ['uuid'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $persona = $this->bridge->personOf((int) $by->id);

        return DB::transaction(function () use ($by, $brakeUuid, $note, $persona): array {
            $b = DB::table(OutreachTables::BRAKES)->where('uuid', strtolower($brakeUuid))->lockForUpdate()->first();
            if ($b === null) {
                throw OutreachException::notFound('brake');
            }
            if ($b->released_at !== null) {
                $misma = (int) $b->released_by_admin_id === (int) $by->id && $b->release_note === $note;
                if ($misma) {
                    return self::dto($b);
                }
                throw OutreachException::conflict('brake_already_released', 'Ese freno ya está suelto: se suelta una sola vez.');
            }
            if ($b->opened_by_admin_id !== null && self::samePerson((int) $by->id, $persona['identityId'] ?? null, (int) $b->opened_by_admin_id, $b->opened_by_person_id !== null ? (int) $b->opened_by_person_id : null)) {
                throw OutreachException::fourEyes();
            }
            $cambiadas = DB::table(OutreachTables::BRAKES)->where('id', $b->id)->whereNull('released_at')->update([
                'released_at' => now(),
                'released_by_admin_id' => (int) $by->id,
                'released_by_name' => mb_substr((string) $by->name, 0, 120),
                'released_by_person_id' => $persona['identityId'] ?? null,
                'release_note' => $note,
                'updated_at' => now(),
            ]);
            if ($cambiadas !== 1) {
                throw OutreachException::conflict('brake_already_released', 'Ese freno ya está suelto: se suelta una sola vez.');
            }
            Log::info('outreach.brake.released', ['brake_uuid' => $b->uuid, 'scope' => $b->scope, 'reason' => $b->reason, 'by' => (int) $by->id]);

            return self::dto(DB::table(OutreachTables::BRAKES)->where('id', $b->id)->first());
        });
    }

    /** La regla de L9-7 (misma cuenta O misma identidad), expuesta para las pruebas. */
    public static function samePerson(int $adminA, ?int $identityA, int $adminB, ?int $identityB): bool
    {
        return OutreachPublishedStrategies::samePerson($adminA, $identityA, $adminB, $identityB);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array<string, list<string>> */
    public function noteErrors(string $note, string $field = 'note'): array
    {
        $v = Validator::make([$field => $note], [$field => ['required', 'string', 'min:'.self::NOTE_MIN, 'max:'.self::NOTE_MAX, new PlainText(strict: true)]]);
        if ($v->fails()) {
            return [$field => ['invalid']];
        }
        if (preg_match(self::DIGIT_RUN, $note) === 1 || preg_match(self::EMAIL, $note) === 1) {
            return [$field => ['contact_data']];
        }

        return [];
    }

    /** @return array<string, list<string>> */
    private function scopeErrors(string $scope, ?string $scopeRef): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            return ['scope' => ['invalid']];
        }
        if ($scope === self::SCOPE_GLOBAL) {
            return $scopeRef === null ? [] : ['scopeRef' => ['must_be_null']];
        }
        if ($scope === self::SCOPE_CHANNEL) {
            return in_array($scopeRef, self::CHANNELS, true) ? [] : ['scopeRef' => ['invalid']];
        }

        return is_string($scopeRef) && preg_match(OutreachCatalog::UUID_PATTERN, strtolower($scopeRef)) === 1 ? [] : ['scopeRef' => ['uuid']];
    }

    private function openRow(string $scope, ?string $scopeRef): ?object
    {
        $q = DB::table(OutreachTables::BRAKES)->where('scope', $scope)->whereNull('released_at');
        $scopeRef === null ? $q->whereNull('scope_ref') : $q->where('scope_ref', $scopeRef);

        return $q->first();
    }

    /** Los trabajos vivos que el freno retiene (la respuesta de D9-14). */
    private function retainedJobs(string $scope, ?string $scopeRef): int
    {
        $q = DB::table(OutreachTables::JOBS.' as j')->whereIn('j.status', OutreachTables::JOB_LIVE_STATUSES);
        if ($scope === self::SCOPE_CHANNEL) {
            $q->where('j.channel', $scopeRef);
        } elseif ($scope === self::SCOPE_STRATEGY) {
            $q->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')->where('s.uuid', $scopeRef);
        }

        return (int) $q->count();
    }

    /** @param  array<string, mixed>  $evidence */
    private function incident(string $scope, ?string $scopeRef, string $reason, string $uuid, array $evidence): void
    {
        try {
            app(IncidentRecorder::class)->record([
                'source' => self::INCIDENT_SOURCE,
                'kind' => self::INCIDENT_KIND,
                'fingerprint_keys' => [$reason, $scope, (string) $scopeRef],
                'title' => 'Freno de la automatización comercial echado ('.$scope.'): '.$reason.'. Nada nuevo sale en ese ámbito hasta que una persona lo suelte.',
                'severity' => Incident::SEVERITY_HIGH,
                'evidence' => ['reason' => $reason, 'scope' => $scope, 'scope_ref' => $scopeRef, 'brake_uuid' => $uuid, 'runbook' => 'backend/docs/ultron-studio/ULTRON_STUDIO_ROLLBACK.md'] + $evidence,
            ]);
        } catch (Throwable $e) {
            // El freno ya está echado (lo que protege); el incidente es el aviso.
            Log::error('outreach.brake.incident_failed', ['reason' => $reason, 'brake_uuid' => $uuid, 'error_class' => $e::class]);
        }
    }

    private static function iso(mixed $at): ?string
    {
        if ($at === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
