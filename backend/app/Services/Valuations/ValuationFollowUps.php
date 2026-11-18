<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Http\Controllers\Api\Admin\ValuationController;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\MemberValuationFollowUp;
use App\Models\ProfessionalAssessment;
use App\Models\ValuationAppointment;
use App\Services\Audit\AuditTrail;
use App\Services\Outreach\Facts\ValuationFacts;
use App\Support\Access\TrainerMemberScope;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEGUIMIENTO DE VALORACIONES (CONTRATO_CICLO_VALORACIONES.md §3 y §7).
 *
 * Responde, para un socio, «¿cuándo le toca la próxima valoración?» y deja
 * cambiar las reglas que lo deciden. Una sola implementación para el panel del
 * CRM (GET/PUT `follow-up`) y para el detector `evaluation.outdated`: si fueran
 * dos, dirían cosas distintas del mismo socio.
 *
 * INTERVALO EFECTIVO: el del socio (`member_valuation_follow_ups.interval_days`)
 * o, si no tiene, el del gimnasio (`valuation_settings.follow_up_interval_days`).
 * NULL en los dos = sin seguimiento. NO hay nivel de plan: ningún plan fija una
 * frecuencia de valoración (los beneficios solo dicen «Valoración inicial» y
 * nada en el código los aplica; auditoría §6), así que no hay regla que copiar.
 *
 * ÚLTIMA VALORACIÓN: la más reciente, ya ocurrida, de
 *  · una valoración profesional enviada: RAÍZ de su cadena (`parent_id` NULL)
 *    en `submitted` o `amended`, con SU `submitted_at`. Una enmienda no mueve la
 *    fecha: cuenta el envío original;
 *  · una cita `completed`: su `completed_at` o, si no lo tiene, su hora de
 *    inicio (el mismo respaldo que {@see ValuationFacts}).
 * No es exactamente la de ValuationFacts, que mira `physical_evaluations` en
 * vez de las valoraciones profesionales. El contrato nombra estas dos fuentes y
 * desde septiembre el 100 % de `physical_evaluations` es la copia de una
 * valoración profesional enviada (mismo instante): contarlas otra vez no añade
 * nada y mezclaría una tercera noción. Lo que diverge está escrito en el
 * apéndice «Detector» del contrato.
 *
 * PRÓXIMO CONTROL SUGERIDO (se calcula al leer, nunca se guarda):
 *  · pausado → ninguno;
 *  · `due_on` → esa fecha (sustituye al cálculo, aunque no haya valoración);
 *  · si no, fecha de Bogotá de la última valoración + intervalo, en días de
 *    calendario; ninguno si no hay valoración o el intervalo es NULL.
 *
 * ESTADO, por prioridad: pausado, agendado (hay una cita viva que aún no ha
 * empezado), sin_valoracion, vencido (faltan < 0 días), proximo (0..7) y
 * al_dia (> 7, o sin próximo control porque no hay intervalo: el contrato no
 * tiene un estado para «sin seguimiento», ver el informe).
 *
 * ALCANCE (lectura y escritura): `view_all` → cualquier socio; `view` con
 * entrenador vinculado → solo los socios con asignación ACTIVA a ese
 * entrenador (la regla de TrainerMemberScope); fuera → 404, como la agenda.
 */
final class ValuationFollowUps
{
    public const STATUS_PAUSED = 'pausado';

    public const STATUS_SCHEDULED = 'agendado';

    public const STATUS_NO_VALUATION = 'sin_valoracion';

    public const STATUS_OVERDUE = 'vencido';

    public const STATUS_UPCOMING = 'proximo';

    public const STATUS_UP_TO_DATE = 'al_dia';

    /** «Próximo»: faltan de 0 a 7 días. */
    public const UPCOMING_DAYS = 7;

    public const MIN_INTERVAL_DAYS = 1;

    public const MAX_INTERVAL_DAYS = 365;

    public const HISTORY_LIMIT = 50;

    public const SOURCE_MEMBER = 'member';

    public const SOURCE_GYM = 'gym';

    public const ORIGIN_AGENDA = 'agenda';

    public const ORIGIN_TRAINER_APP = 'app_entrenador';

    public const ORIGIN_RECONCILIATION = 'reconciliacion';

    /**
     * `metadata.source` del evento `completed` de una cita (contrato §1 y §2,
     * los escribe backend A) → origen en el historial. Lo demás es la agenda.
     */
    private const COMPLETED_SOURCE_ORIGINS = [
        'trainer_assessment' => self::ORIGIN_TRAINER_APP,
        'historical_reconciliation' => self::ORIGIN_RECONCILIATION,
    ];

    /** Las que cuentan como valoración hecha (borrador y anulada, no). */
    private const ASSESSMENT_DONE_STATUSES = [ProfessionalAssessment::STATUS_SUBMITTED, ProfessionalAssessment::STATUS_AMENDED];

    public function __construct(
        private readonly ValuationSettings $settings,
        private readonly AuditTrail $audit,
    ) {}

    // ── Reglas del PUT ──────────────────────────────────────────────────────

    /**
     * Cuerpo ENTERO, como los ajustes: las cuatro claves presentes (un null es
     * una decisión, una clave que falta no). `due_on` además no puede ser
     * anterior a hoy, salvo que sea la misma fecha ya guardada
     * ({@see self::update()}).
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'interval_days' => ['present', 'nullable', 'integer', 'min:'.self::MIN_INTERVAL_DAYS, 'max:'.self::MAX_INTERVAL_DAYS],
            'enabled' => ['required', 'boolean'],
            'due_on' => ['present', 'nullable', 'string', 'date_format:Y-m-d'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ];
    }

    // ── Cálculo puro (sin base de datos) ────────────────────────────────────

    /** `Y-m-d` + N días de calendario de Bogotá (sin horario de verano, pero siempre con la zona). */
    public static function addCalendarDays(string $fecha, int $dias): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $fecha, ValuationTime::TZ)->addDays($dias)->toDateString();
    }

    /** Días de calendario de `$desde` a `$hasta` (negativo si `$hasta` ya pasó). */
    public static function daysBetween(string $desde, string $hasta): int
    {
        $a = CarbonImmutable::createFromFormat('!Y-m-d', $desde, 'UTC');
        $b = CarbonImmutable::createFromFormat('!Y-m-d', $hasta, 'UTC');

        return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
    }

    public static function nextSuggestedOn(bool $enabled, ?string $dueOn, ?string $lastDate, ?int $intervalDays): ?string
    {
        if (! $enabled) {
            return null;
        }
        if ($dueOn !== null) {
            return $dueOn;
        }
        if ($lastDate === null || $intervalDays === null) {
            return null;
        }

        return self::addCalendarDays($lastDate, $intervalDays);
    }

    public static function statusFor(bool $enabled, bool $hasFutureAppointment, bool $hasValuation, ?int $daysUntilDue): string
    {
        return match (true) {
            ! $enabled => self::STATUS_PAUSED,
            $hasFutureAppointment => self::STATUS_SCHEDULED,
            ! $hasValuation => self::STATUS_NO_VALUATION,
            // Sin próximo control (intervalo NULL y sin fecha puntual): no hay
            // nada pendiente. El contrato no define un estado propio.
            $daysUntilDue === null => self::STATUS_UP_TO_DATE,
            $daysUntilDue < 0 => self::STATUS_OVERDUE,
            $daysUntilDue <= self::UPCOMING_DAYS => self::STATUS_UPCOMING,
            default => self::STATUS_UP_TO_DATE,
        };
    }

    // ── Estado de varios socios a la vez ────────────────────────────────────

    /**
     * El seguimiento calculado de cada socio, con una consulta por fuente (no
     * una por socio). Lo usan el GET y el detector.
     *
     * @param  list<int>  $memberIds
     * @return array<int, array{
     *     member_id: int, row: ?MemberValuationFollowUp, version: int,
     *     enabled: bool, member_days: ?int, gym_days: ?int, interval_days: ?int, interval_source: ?string,
     *     due_on: ?string, last: ?array<string,mixed>, next_appointment: ?object,
     *     next_suggested_on: ?string, days_until_due: ?int, status: string
     * }>
     */
    public function snapshots(array $memberIds, ?CarbonInterface $ahora = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if ($ids === []) {
            return [];
        }
        $ahora = Carbon::instance($ahora ?? Carbon::now())->utc();
        $hoy = ValuationTime::local($ahora)->toDateString();

        $gimnasio = $this->settings->current()->follow_up_interval_days;
        $gimnasio = $gimnasio === null ? null : (int) $gimnasio;
        $filas = MemberValuationFollowUp::query()->whereIn('member_id', $ids)->get()->keyBy('member_id');
        $ultimas = $this->lastValuations($ids, $ahora);
        $proximas = $this->nextAppointments($ids, $ahora);

        $salida = [];
        foreach ($ids as $id) {
            /** @var MemberValuationFollowUp|null $fila */
            $fila = $filas->get($id);
            $propio = $fila?->interval_days;
            $efectivo = $propio ?? $gimnasio;
            $activo = $fila === null ? true : (bool) $fila->enabled;
            $fecha = $fila?->dueOn();
            $ultima = $ultimas[$id] ?? null;
            // Una fecha puntual vale para el ciclo en que se fijó: si DESPUÉS de
            // la última edición del seguimiento hubo una valoración, queda
            // consumida y vuelve el cálculo normal (fecha de esa valoración +
            // intervalo). La fila conserva el dato; solo deja de mandar.
            if ($fecha !== null && $ultima !== null && $fila?->updated_at !== null && $ultima['at']->gt($fila->updated_at)) {
                $fecha = null;
            }
            $cita = $proximas[$id] ?? null;
            $siguiente = self::nextSuggestedOn($activo, $fecha, $ultima['date'] ?? null, $efectivo);
            $faltan = $siguiente !== null ? self::daysBetween($hoy, $siguiente) : null;

            $salida[$id] = [
                'member_id' => $id,
                'row' => $fila,
                'version' => (int) ($fila?->version ?? 0),
                'enabled' => $activo,
                'member_days' => $propio,
                'gym_days' => $gimnasio,
                'interval_days' => $efectivo,
                'interval_source' => $propio !== null ? self::SOURCE_MEMBER : ($gimnasio !== null ? self::SOURCE_GYM : null),
                'due_on' => $fecha,
                'last' => $ultima,
                'next_appointment' => $cita,
                'next_suggested_on' => $siguiente,
                'days_until_due' => $faltan,
                'status' => self::statusFor($activo, $cita !== null, $ultima !== null, $faltan),
            ];
        }

        return $salida;
    }

    /**
     * La última valoración ya ocurrida de cada socio (ver la cabecera).
     *
     * @param  list<int>  $ids
     * @return array<int, array{at: Carbon, date: string, source: string, trainer_id: ?int, assessment_id: ?int, assessment_uuid: ?string, appointment_uuid: ?string}>
     */
    private function lastValuations(array $ids, Carbon $ahora): array
    {
        $tope = ValuationAvailability::db($ahora);
        $mejor = [];
        // A igual instante gana la cita: es el registro de la agenda y, si
        // está enlazada, lleva también la valoración.
        $considerar = function (int $socio, array $candidata) use (&$mejor): void {
            $actual = $mejor[$socio] ?? null;
            if ($actual === null || $candidata['at']->gt($actual['at'])
                || ($candidata['at']->eq($actual['at']) && $candidata['source'] === 'appointment' && $actual['source'] !== 'appointment')) {
                $mejor[$socio] = $candidata;
            }
        };

        $valoraciones = DB::table('professional_assessments')
            ->whereIn('member_id', $ids)
            ->whereNull('parent_id')
            ->whereIn('status', self::ASSESSMENT_DONE_STATUSES)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', $tope)
            ->get(['id', 'uuid', 'member_id', 'trainer_id', 'submitted_at']);
        foreach ($valoraciones as $v) {
            $considerar((int) $v->member_id, [
                'at' => Carbon::parse($v->submitted_at, 'UTC'),
                'source' => 'assessment',
                'trainer_id' => $v->trainer_id !== null ? (int) $v->trainer_id : null,
                'assessment_id' => (int) $v->id,
                'assessment_uuid' => (string) $v->uuid,
                'appointment_uuid' => null,
            ]);
        }

        $completadas = DB::table('valuation_appointments')
            ->whereIn('member_id', $ids)
            ->where('status', ValuationAppointment::STATUS_COMPLETED)
            ->get(['id', 'uuid', 'member_id', 'trainer_id', 'starts_at', 'completed_at', 'professional_assessment_id']);
        $citaDeValoracion = [];
        foreach ($completadas as $c) {
            if ($c->professional_assessment_id !== null) {
                $citaDeValoracion[(int) $c->professional_assessment_id] = (string) $c->uuid;
            }
            $cuando = Carbon::parse($c->completed_at ?? $c->starts_at, 'UTC');
            if ($cuando->gt($ahora)) {
                continue;
            }
            $considerar((int) $c->member_id, [
                'at' => $cuando,
                'source' => 'appointment',
                'trainer_id' => (int) $c->trainer_id,
                'assessment_id' => $c->professional_assessment_id !== null ? (int) $c->professional_assessment_id : null,
                'assessment_uuid' => null,
                'appointment_uuid' => (string) $c->uuid,
            ]);
        }

        // Completar el otro lado del enlace (cita ↔ valoración) del ganador.
        $faltan = [];
        foreach ($mejor as $socio => $m) {
            if ($m['source'] === 'assessment') {
                $mejor[$socio]['appointment_uuid'] = $citaDeValoracion[$m['assessment_id']] ?? null;
            } elseif ($m['assessment_id'] !== null) {
                $faltan[$m['assessment_id']] = true;
            }
        }
        $uuids = $faltan === [] ? [] : DB::table('professional_assessments')->whereIn('id', array_keys($faltan))->pluck('uuid', 'id')->all();
        foreach ($mejor as $socio => $m) {
            if ($m['source'] === 'appointment' && $m['assessment_id'] !== null) {
                $mejor[$socio]['assessment_uuid'] = isset($uuids[$m['assessment_id']]) ? (string) $uuids[$m['assessment_id']] : null;
            }
            $mejor[$socio]['date'] = ValuationTime::local($m['at'])->toDateString();
        }

        return $mejor;
    }

    /**
     * La cita viva más cercana que AÚN NO HA EMPEZADO de cada socio
     * (`scheduled`, `confirmed` o `in_progress` con inicio futuro; la misma
     * definición que CONTRATO_SOLICITUDES_VALORACION.md §2). Una cita pasada que
     * nadie cerró no cuenta: si contara, el socio quedaría «agendado» para
     * siempre (en producción hay 40 así).
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function nextAppointments(array $ids, Carbon $ahora): array
    {
        $salida = [];
        DB::table('valuation_appointments as va')
            ->leftJoin('trainers as t', 't.id', '=', 'va.trainer_id')
            ->whereIn('va.member_id', $ids)
            ->whereIn('va.status', ValuationAppointment::ACTIVE_STATUSES)
            ->where('va.starts_at', '>', ValuationAvailability::db($ahora))
            ->orderBy('va.starts_at')
            ->orderBy('va.id')
            ->get(['va.member_id', 'va.uuid', 'va.starts_at', 'va.status', 'va.trainer_id', 't.full_name as trainer_name'])
            ->each(function ($c) use (&$salida): void {
                $salida[(int) $c->member_id] ??= $c;
            });

        return $salida;
    }

    // ── Panel del CRM ───────────────────────────────────────────────────────

    /**
     * El socio, si existe y está en el alcance de quien mira; si no, 404 (no se
     * confirma la existencia de un socio ajeno). Sin alcance ninguno, 403.
     *
     * Se carga SIN TrainerMemberScope y se decide aquí, como el resto de la
     * agenda ({@see ValuationPresenter}): la regla es la misma (asignación
     * activa al entrenador vinculado) pero también vale para una cuenta con
     * `view` y vínculo que no tenga el rol Entrenador.
     *
     * @throws ValuationException 403|404
     */
    public function memberInScope(ValuationAccess $access, int $memberId): Member
    {
        $alcance = $access->scopedTrainerId();

        $socio = Member::query()
            ->withoutGlobalScope(TrainerMemberScope::NAME)
            ->whereNull('anonymized_at')
            ->with(['user' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)->select(['id', 'membership_end_date'])])
            ->find($memberId, ['id', 'user_id', 'full_name', 'document_number']);

        if ($socio === null) {
            throw ValuationException::notFound('El socio');
        }
        if ($alcance !== null && ! MemberTrainerAssignment::query()
            ->where('member_id', $socio->id)
            ->where('trainer_id', $alcance)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->exists()) {
            throw ValuationException::notFound('El socio');
        }

        return $socio;
    }

    /**
     * La respuesta de `follow-up` (contrato §3). Las horas, en ISO 8601 con el
     * desfase de Bogotá; las fechas de calendario, `Y-m-d` de Bogotá.
     *
     * @return array<string,mixed>
     */
    public function present(Member $socio, ValuationAccess $access, ?CarbonInterface $ahora = null): array
    {
        $ahora = Carbon::instance($ahora ?? Carbon::now())->utc();
        $id = (int) $socio->id;
        $e = $this->snapshots([$id], $ahora)[$id];
        $socio->loadMissing(['user' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)->select(['id', 'membership_end_date'])]);
        [$estado, $etiqueta] = ValuationPresenter::membership($socio);

        $asignacion = MemberTrainerAssignment::query()
            ->where('member_id', $id)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->with('trainer:id,full_name')
            ->orderByDesc('id')
            ->first(['id', 'member_id', 'trainer_id']);

        $ultima = $e['last'];
        $nombreEntrenador = $ultima !== null && $ultima['trainer_id'] !== null
            ? DB::table('trainers')->where('id', $ultima['trainer_id'])->value('full_name')
            : null;
        $cita = $e['next_appointment'];

        return [
            'member' => [
                'id' => $id,
                'name' => (string) $socio->full_name,
                'document_tail' => ValuationPresenter::documentTail($socio->document_number),
                'membership' => [
                    'status' => $estado,
                    'label' => $etiqueta,
                    'ends_on' => $socio->user?->membership_end_date,
                ],
                'assigned_trainer' => $asignacion?->trainer
                    ? ['id' => (int) $asignacion->trainer->id, 'name' => (string) $asignacion->trainer->full_name]
                    : null,
            ],
            'last_valuation' => $ultima === null ? null : [
                'at' => self::iso($ultima['at']),
                'date' => $ultima['date'],
                'trainer' => $ultima['trainer_id'] !== null && $nombreEntrenador !== null
                    ? ['id' => $ultima['trainer_id'], 'name' => (string) $nombreEntrenador]
                    : null,
                'source' => $ultima['source'],
                'assessment_uuid' => $ultima['assessment_uuid'],
                'appointment_uuid' => $ultima['appointment_uuid'],
            ],
            'interval' => [
                'days' => $e['interval_days'],
                'source' => $e['interval_source'],
                'enabled' => $e['enabled'],
                'gym_default_days' => $e['gym_days'],
            ],
            'due_on_override' => $e['due_on'],
            'next_suggested_on' => $e['next_suggested_on'],
            'days_until_due' => $e['days_until_due'],
            'next_appointment' => $cita === null || ! $access->coversTrainer((int) $cita->trainer_id) ? null : [
                'uuid' => (string) $cita->uuid,
                'starts_at' => self::iso(Carbon::parse($cita->starts_at, 'UTC')),
                'trainer' => ['id' => (int) $cita->trainer_id, 'name' => (string) ($cita->trainer_name ?? '')],
                'status' => (string) $cita->status,
            ],
            'status' => $e['status'],
            'version' => $e['version'],
            'can_edit' => $access->can(ValuationAccess::UPDATE),
            'history' => $this->history($id, $access),
        ];
    }

    /**
     * Citas y valoraciones del socio, de la más reciente a la más antigua, con
     * un máximo de 50. Una cita y la valoración que la cerró son UNA fila
     * (`linked=true`); una valoración sin cita es su propia fila. Una cadena de
     * enmiendas es una fila: la raíz, con la fecha del envío original.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $memberId, ?ValuationAccess $access = null): array
    {
        $citas = DB::table('valuation_appointments as va')
            ->leftJoin('trainers as t', 't.id', '=', 'va.trainer_id')
            ->where('va.member_id', $memberId)
            ->orderByDesc('va.starts_at')
            ->orderByDesc('va.id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['va.id', 'va.uuid', 'va.status', 'va.starts_at', 'va.completed_at', 'va.professional_assessment_id', 'va.trainer_id', 't.full_name as trainer_name']);

        // Todos los enlaces del socio, no solo los de estas 50 citas: una
        // valoración enlazada a una cita antigua no debe salir suelta.
        $enlazadas = DB::table('valuation_appointments')
            ->where('member_id', $memberId)
            ->whereNotNull('professional_assessment_id')
            ->pluck('professional_assessment_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $uuidDe = $enlazadas === [] ? [] : DB::table('professional_assessments')->whereIn('id', $enlazadas)->pluck('uuid', 'id')->all();

        $origenDe = [];
        if ($citas->isNotEmpty()) {
            DB::table('valuation_appointment_events')
                ->whereIn('appointment_id', $citas->pluck('id')->all())
                ->where('action', 'completed')
                ->orderBy('id')
                ->get(['appointment_id', 'metadata'])
                ->each(function ($ev) use (&$origenDe): void {
                    $meta = is_string($ev->metadata) ? json_decode($ev->metadata, true) : null;
                    $fuente = is_array($meta) ? ($meta['source'] ?? null) : null;
                    $origenDe[(int) $ev->appointment_id] = self::COMPLETED_SOURCE_ORIGINS[$fuente] ?? self::ORIGIN_AGENDA;
                });
        }

        $filas = [];
        foreach ($citas as $c) {
            $inicio = Carbon::parse($c->starts_at, 'UTC');
            $enlace = $c->professional_assessment_id !== null ? (int) $c->professional_assessment_id : null;
            // Una cuenta con alcance (entrenador vinculado) ve la fecha y el
            // estado de las citas del socio con OTRO entrenador, pero no quién
            // ni el enlace: el mismo criterio que las solicitudes.
            $propia = $access === null || $access->coversTrainer((int) $c->trainer_id);
            $filas[] = ['orden' => [$inicio->getTimestamp(), 1, (int) $c->id], 'fila' => [
                'kind' => 'appointment',
                'at' => self::iso($inicio),
                'date' => ValuationTime::local($inicio)->toDateString(),
                'status' => (string) $c->status,
                'trainer' => $propia && $c->trainer_name !== null ? ['name' => (string) $c->trainer_name] : null,
                'appointment_uuid' => $propia ? (string) $c->uuid : null,
                'assessment_uuid' => $enlace !== null && isset($uuidDe[$enlace]) ? (string) $uuidDe[$enlace] : null,
                'linked' => $enlace !== null,
                'origin' => $origenDe[(int) $c->id] ?? self::ORIGIN_AGENDA,
                'completed_at' => $c->completed_at !== null ? self::iso(Carbon::parse($c->completed_at, 'UTC')) : null,
            ]];
        }

        $valoraciones = DB::table('professional_assessments as pa')
            ->leftJoin('trainers as t', 't.id', '=', 'pa.trainer_id')
            ->where('pa.member_id', $memberId)
            ->whereNull('pa.parent_id')
            ->whereIn('pa.status', self::ASSESSMENT_DONE_STATUSES)
            ->whereNotNull('pa.submitted_at')
            ->when($enlazadas !== [], fn ($q) => $q->whereNotIn('pa.id', $enlazadas))
            ->orderByDesc('pa.submitted_at')
            ->orderByDesc('pa.id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['pa.id', 'pa.uuid', 'pa.status', 'pa.submitted_at', 't.full_name as trainer_name']);
        foreach ($valoraciones as $v) {
            $envio = Carbon::parse($v->submitted_at, 'UTC');
            $filas[] = ['orden' => [$envio->getTimestamp(), 0, (int) $v->id], 'fila' => [
                'kind' => 'assessment',
                'at' => self::iso($envio),
                'date' => ValuationTime::local($envio)->toDateString(),
                'status' => (string) $v->status,
                'trainer' => $v->trainer_name !== null ? ['name' => (string) $v->trainer_name] : null,
                'appointment_uuid' => null,
                'assessment_uuid' => (string) $v->uuid,
                'linked' => false,
                'origin' => self::ORIGIN_TRAINER_APP,
                'completed_at' => self::iso($envio),
            ]];
        }

        usort($filas, fn ($a, $b) => $b['orden'] <=> $a['orden']);

        return array_map(fn ($f) => $f['fila'], array_slice($filas, 0, self::HISTORY_LIMIT));
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * Cambia intervalo, pausa y fecha puntual del socio, con versión optimista
     * y auditoría (antes y después). Devuelve lo mismo que el GET.
     *
     * CONCURRENCIA. La fila se asegura con `INSERT … ON CONFLICT DO NOTHING`
     * (versión 0, la que el GET da a un socio sin fila) y se bloquea con
     * `FOR UPDATE` antes de comparar la versión. Dos PUT a la vez con la misma
     * versión: el segundo espera al primero, ve la versión nueva y recibe 409
     * `follow_up_changed`. No se toca la fila de `members` (es el cerrojo de la
     * agenda), así que no hay orden de cerrojos que respetar con ella.
     *
     * `due_on` en el pasado (Bogotá) es 422, salvo que sea la fecha ya guardada:
     * el PUT es entero, y sin esa excepción una fecha puntual que ya venció
     * impediría cambiar el intervalo o pausar sin borrarla antes.
     *
     * @param  array{interval_days: int|string|null, enabled: bool|int|string, due_on: ?string, expected_version: int|string}  $datos  ya validados con {@see self::rules()}
     * @return array<string,mixed>
     *
     * @throws ValuationException 409 `follow_up_changed`
     * @throws ValidationException
     */
    public function update(ValuationAccess $access, Member $socio, array $datos, Request $request): array
    {
        $access->require(ValuationAccess::UPDATE);
        $memberId = (int) $socio->id;
        $intervalo = $datos['interval_days'] === null ? null : (int) $datos['interval_days'];
        $activo = filter_var($datos['enabled'], FILTER_VALIDATE_BOOLEAN);
        $esperada = (int) $datos['expected_version'];
        $fecha = null;
        if ($datos['due_on'] !== null) {
            $fecha = ValuationTime::plainDate((string) $datos['due_on']);
            if ($fecha === null) {
                throw ValidationException::withMessages(['due_on' => ['Fecha no válida: usa AAAA-MM-DD entre los años 2000 y 2100.']]);
            }
        }
        $hoy = ValuationTime::today()->toDateString();

        DB::transaction(function () use ($access, $socio, $memberId, $intervalo, $activo, $fecha, $esperada, $hoy, $request): void {
            $marca = Carbon::now();
            DB::table('member_valuation_follow_ups')->insertOrIgnore([
                'member_id' => $memberId,
                'enabled' => true,
                'version' => 0,
                'created_at' => $marca,
                'updated_at' => $marca,
            ]);
            $fila = MemberValuationFollowUp::query()->where('member_id', $memberId)->lockForUpdate()->firstOrFail();

            if ((int) $fila->version !== $esperada) {
                throw new ValuationException(
                    'El seguimiento de este socio cambió mientras lo mirabas. Revisa cómo quedó antes de seguir.',
                    'follow_up_changed',
                    409,
                    ['data' => $this->present($socio, $access)],
                );
            }
            if ($fecha !== null && $fecha < $hoy && $fecha !== $fila->dueOn()) {
                throw ValidationException::withMessages(['due_on' => ['La fecha del próximo control no puede ser anterior a hoy.']]);
            }

            $previo = $fila->getOriginal();
            $fila->fill(['interval_days' => $intervalo, 'enabled' => $activo, 'due_on' => $fecha]);
            // Sin cambios no sube la versión ni deja traza: repetir el mismo
            // PUT no es otra decisión.
            if (! $fila->isDirty(MemberValuationFollowUp::FIELDS)) {
                return;
            }
            $fila->version = (int) $fila->version + 1;
            $fila->updated_by_admin_id = $access->actor->id;
            $fila->save();

            $this->audit->record($request, [
                'action' => 'update', 'module' => 'Valoraciones', 'entity' => 'seguimiento_valoraciones',
                'entity_id' => $memberId,
                'summary' => 'Cambió el seguimiento de valoraciones del socio',
                // Solo los tres campos de negocio (no versión ni autor), con
                // su antes y después: son números, una casilla y una fecha.
                'changes' => array_values(array_filter(
                    $this->audit->changesOf($fila, $previo, MemberValuationFollowUp::FIELDS),
                    fn (array $c) => in_array($c['field'], MemberValuationFollowUp::FIELDS, true),
                )),
                'metadata' => ['member_id' => $memberId, 'version' => (int) $fila->version],
            ]);
        });

        return $this->present($socio, $access);
    }

    // ── Detector (`evaluation.outdated`, contrato §7) ───────────────────────

    /**
     * Socios a los que HOY les toca valoración: membresía vigente, seguimiento
     * activo, próximo control sugerido ≤ hoy (Bogotá) y sin cita viva futura.
     *
     * Primero un filtro en SQL que solo deja pasar a quien PUEDE tocarle (tiene
     * alguna valoración o una fecha puntual, no está pausado, no tiene cita
     * futura y su membresía está vigente); después el mismo cálculo del panel,
     * por lotes, decide. Así el panel y el detector no pueden discrepar.
     *
     * MEMBRESÍA VIGENTE = socio activo y no anonimizado, cuenta activa
     * ({@see MembershipFilter}, que deja fuera las congeladas) y fecha de fin
     * conocida y ≥ hoy (la regla con la que la agenda pinta «Activa»).
     *
     * @return list<array{member_id: int, name: string, due_on: string, has_any: bool}>
     */
    public function dueForOutreach(?CarbonInterface $ahora = null): array
    {
        $ahora = Carbon::instance($ahora ?? Carbon::now())->utc();
        $hoy = ValuationTime::local($ahora)->toDateString();
        $tope = ValuationAvailability::db($ahora);

        $consulta = DB::table('members')
            ->join('users', 'users.id', '=', 'members.user_id')
            ->where('members.status', Member::STATUS_ACTIVE)
            ->whereNull('members.anonymized_at')
            ->whereNotNull('users.membership_end_date')
            ->where('users.membership_end_date', '>=', $hoy);
        MembershipFilter::apply($consulta, 'active');

        $socios = $consulta
            ->where(fn ($w) => $w
                ->whereExists(fn ($q) => $q->from('professional_assessments as pa')
                    ->whereColumn('pa.member_id', 'members.id')
                    ->whereNull('pa.parent_id')
                    ->whereIn('pa.status', self::ASSESSMENT_DONE_STATUSES)
                    ->whereNotNull('pa.submitted_at'))
                ->orWhereExists(fn ($q) => $q->from('valuation_appointments as vc')
                    ->whereColumn('vc.member_id', 'members.id')
                    ->where('vc.status', ValuationAppointment::STATUS_COMPLETED))
                ->orWhereExists(fn ($q) => $q->from('member_valuation_follow_ups as fd')
                    ->whereColumn('fd.member_id', 'members.id')
                    ->whereNotNull('fd.due_on')))
            ->whereNotExists(fn ($q) => $q->from('member_valuation_follow_ups as fp')
                ->whereColumn('fp.member_id', 'members.id')
                ->where('fp.enabled', false))
            ->whereNotExists(fn ($q) => $q->from('valuation_appointments as vf')
                ->whereColumn('vf.member_id', 'members.id')
                ->whereIn('vf.status', ValuationAppointment::ACTIVE_STATUSES)
                ->where('vf.starts_at', '>', $tope))
            ->orderBy('members.id')
            ->get(['members.id', 'members.full_name']);

        $salida = [];
        foreach ($socios->chunk(500) as $lote) {
            $estados = $this->snapshots($lote->pluck('id')->map(fn ($v) => (int) $v)->values()->all(), $ahora);
            foreach ($lote as $s) {
                $e = $estados[(int) $s->id] ?? null;
                if ($e === null || ! $e['enabled'] || $e['next_appointment'] !== null
                    || $e['next_suggested_on'] === null || $e['next_suggested_on'] > $hoy) {
                    continue;
                }
                $salida[] = [
                    'member_id' => (int) $s->id,
                    'name' => (string) $s->full_name,
                    'due_on' => $e['next_suggested_on'],
                    'has_any' => $e['last'] !== null,
                ];
            }
        }

        return $salida;
    }

    /** ISO 8601 con el desfase de Bogotá (`…-05:00`). */
    private static function iso(CarbonInterface $instante): string
    {
        return ValuationTime::local($instante)->toIso8601String();
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Bandeja de valoraciones recibidas (CONTRATO_BANDEJA_SEGUIMIENTO.md)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Las valoraciones ENVIADAS por los entrenadores (raíz, submitted|amended),
     * de la más reciente a la más antigua (`submitted_at`, `id`), paginadas en
     * la base. Salen aunque no hubiera cita. Sin medidas: el detalle va por el
     * GET del seguimiento del socio. Un número fijo de consultas por página.
     *
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function recent(ValuationAccess $access, ?string $q, int $page, int $perPage): array
    {
        $base = $this->feedQuery($access);
        $nuevas = (clone $base)->whereNull('pa.admin_reviewed_at')->count();

        if ($q !== null) {
            $plegado = ValuationController::foldSearch($q);
            $escapar = fn (string $t) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t);
            $like = '%'.$escapar($plegado).'%';
            $docLike = '%'.$escapar((string) preg_replace('/[\s.\-]+/', '', $plegado)).'%';
            $columna = ValuationController::foldedColumn('m.full_name');
            $base->where(fn ($w) => $w->whereRaw("{$columna} LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(m.document_number) LIKE ? ESCAPE '!'", [$docLike]));
        }

        $pagina = $base->orderByDesc('pa.submitted_at')->orderByDesc('pa.id')
            ->select(['pa.id', 'pa.uuid', 'pa.member_id', 'pa.trainer_id', 'pa.submitted_at', 'pa.admin_reviewed_at',
                'pa.admin_reviewed_by_admin_id', 'm.full_name as member_name', 't.full_name as trainer_name'])
            ->paginate(max(1, min(50, $perPage)), ['*'], 'page', max(1, $page));

        return [
            'data' => $this->feedRows($access, collect($pagina->items())),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'unreviewed' => $nuevas,
            ],
        ];
    }

    /**
     * Marca una valoración como revisada por administración. Idempotente: si ya
     * lo estaba, conserva la primera marca. Fuera de alcance o inexistente: 404.
     * No cambia la valoración ni la cita: es solo el acuse del CRM.
     *
     * @return array<string,mixed>
     */
    public function markReviewed(ValuationAccess $access, string $assessmentUuid): array
    {
        // El acuse es de administración: una cuenta con alcance de entrenador
        // no puede quitarle el «Nueva» (lo exige también el mapa: view_all).
        if (! $access->viewsAll() || $access->scopedTrainerId() !== null) {
            throw ValuationException::forbidden('Marcar como revisada es de administración (ver toda la agenda).');
        }
        $fila = $this->feedQuery($access)->where('pa.uuid', $assessmentUuid)
            ->select(['pa.id', 'pa.uuid', 'pa.member_id', 'pa.trainer_id', 'pa.submitted_at', 'pa.admin_reviewed_at',
                'pa.admin_reviewed_by_admin_id', 'm.full_name as member_name', 't.full_name as trainer_name'])
            ->first();
        if ($fila === null) {
            throw ValuationException::notFound('La valoración');
        }

        DB::table('professional_assessments')->where('id', $fila->id)->whereNull('admin_reviewed_at')
            ->update(['admin_reviewed_at' => now(), 'admin_reviewed_by_admin_id' => $access->actor->id]);

        $fresca = $this->feedQuery($access)->where('pa.id', $fila->id)
            ->select(['pa.id', 'pa.uuid', 'pa.member_id', 'pa.trainer_id', 'pa.submitted_at', 'pa.admin_reviewed_at',
                'pa.admin_reviewed_by_admin_id', 'm.full_name as member_name', 't.full_name as trainer_name'])
            ->first();

        return $this->feedRows($access, collect([$fresca]))[0];
    }

    /** Valoraciones enviadas dentro del alcance de la agenda (misma regla que memberInScope). */
    private function feedQuery(ValuationAccess $access): \Illuminate\Database\Query\Builder
    {
        if (! $access->hasScope()) {
            throw ValuationException::forbidden();
        }
        $alcance = $access->scopedTrainerId();

        return DB::table('professional_assessments as pa')
            ->join('members as m', 'm.id', '=', 'pa.member_id')
            ->leftJoin('trainers as t', 't.id', '=', 'pa.trainer_id')
            ->whereNull('pa.parent_id')
            ->whereIn('pa.status', self::ASSESSMENT_DONE_STATUSES)
            ->whereNotNull('pa.submitted_at')
            ->whereNull('m.anonymized_at')
            ->when($alcance !== null, fn ($w) => $w->whereIn('pa.member_id', MemberTrainerAssignment::query()
                ->select('member_id')
                ->where('trainer_id', $alcance)
                ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $filas
     * @return list<array<string,mixed>>
     */
    private function feedRows(ValuationAccess $access, \Illuminate\Support\Collection $filas): array
    {
        if ($filas->isEmpty()) {
            return [];
        }
        $citas = DB::table('valuation_appointments')
            ->whereIn('professional_assessment_id', $filas->pluck('id')->all())
            ->get(['professional_assessment_id', 'uuid', 'status'])
            ->keyBy('professional_assessment_id');
        $revisores = DB::table('admins')
            ->whereIn('id', $filas->pluck('admin_reviewed_by_admin_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $estados = $this->snapshots($filas->pluck('member_id')->map(fn ($v) => (int) $v)->all());

        return $filas->map(function ($f) use ($citas, $revisores, $estados, $access): array {
            $cita = $citas->get($f->id);
            $e = $estados[(int) $f->member_id] ?? null;
            $proxima = $e['next_appointment'] ?? null;
            $proximaVisible = $proxima !== null && $access->coversTrainer((int) $proxima->trainer_id);

            return [
                'assessment_uuid' => (string) $f->uuid,
                'member' => ['id' => (int) $f->member_id, 'name' => (string) $f->member_name],
                'trainer' => $f->trainer_id !== null && $f->trainer_name !== null
                    ? ['id' => (int) $f->trainer_id, 'name' => (string) $f->trainer_name] : null,
                'completed_at' => self::iso(Carbon::parse($f->submitted_at, 'UTC')),
                'appointment' => $cita !== null ? ['uuid' => (string) $cita->uuid, 'status' => (string) $cita->status] : null,
                'is_new' => $f->admin_reviewed_at === null,
                'reviewed_at' => $f->admin_reviewed_at !== null ? self::iso(Carbon::parse($f->admin_reviewed_at, 'UTC')) : null,
                'reviewed_by_name' => $f->admin_reviewed_by_admin_id !== null ? ($revisores[$f->admin_reviewed_by_admin_id] ?? null) : null,
                'follow_up' => [
                    'status' => $e['status'] ?? null,
                    'next_suggested_on' => $e['next_suggested_on'] ?? null,
                    'next_appointment_starts_at' => $proximaVisible ? self::iso(Carbon::parse($proxima->starts_at, 'UTC')) : null,
                ],
            ];
        })->values()->all();
    }
}
