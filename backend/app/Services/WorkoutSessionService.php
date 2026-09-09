<?php

namespace App\Services;

use App\Enums\WorkoutExerciseStatus;
use App\Enums\WorkoutSkipReason;
use App\Exceptions\WorkoutSessionException;
use App\Models\Exercise;
use App\Models\Member;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionExercise;
use App\Models\WorkoutSessionSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registra una sesión de entrenamiento ejecutada y todo lo que se deriva de
 * ella: series, volumen, duración, racha y récords personales.
 *
 * IDEMPOTENCIA
 * ------------
 * La app genera un `client_session_id` al EMPEZAR el entrenamiento y lo reenvía
 * en cada intento. Si la sesión ya existe se devuelve tal cual, sin volver a
 * crear series, sin sumar otra racha y sin reevaluar récords. Así un doble
 * toque, un reintento por timeout o un reenvío tras perder red no pueden
 * duplicar el entrenamiento.
 *
 * COMPATIBILIDAD
 * --------------
 * Se sigue creando la fila de `routine_completions` que ya consumen Progreso,
 * el contexto de IRON IA, las notificaciones y los comandos proactivos. La
 * sesión apunta a ella, de modo que ninguna funcionalidad previa cambia de
 * fuente y ambas vistas quedan trazables entre sí.
 *
 * DOS CONTRATOS A LA VEZ
 * ----------------------
 * El endpoint acepta dos formas del mismo payload y las distingue por un solo
 * dato: si algún ejercicio trae `status`, es el contrato NUEVO.
 *
 *  - NUEVO: cada ejercicio declara su estado. Se exige que TODOS estén
 *    `completed` para cerrar la sesión, y se rechaza el envío si no. La regla
 *    vive aquí y no en Flutter porque el endpoint es alcanzable directamente:
 *    una app parcheada o una petición a mano no pueden saltársela.
 *
 *  - LEGACY (`LEGACY_COMPLETION_POLICY=PERMISSIVE_DERIVED`): las apps 2.0.3 y
 *    anteriores no saben mandar estado —su pantalla ni siquiera tiene el
 *    concepto— así que exigírselo las dejaría sin poder registrar un
 *    entrenamiento hasta que actualicen, y hay versiones instaladas que
 *    tardarán semanas en hacerlo. Para ellas NO se valida nada nuevo: la sesión
 *    se guarda como se ha guardado siempre y el estado de cada ejercicio se
 *    DERIVA de sus series (`completed` si ejecutó al menos una, `pending` si
 *    no). Esa derivación es una lectura de lo que mandaron, no una suposición
 *    sobre lo que quisieron decir.
 *
 * La compatibilidad legacy se retira cuando la versión nueva esté distribuida,
 * no antes, y esa retirada es una decisión de producto: aquí no hay ventana de
 * migración implícita ni fecha de caducidad escondida.
 */
class WorkoutSessionService
{
    /** Zona horaria operativa del gimnasio. */
    public const TZ = 'America/Bogota';

    /** ISO-8601 SIN zona: `2026-08-17T01:16:41.123`. Ver {@see self::parseTime()}. */
    private const NAIVE_TIMESTAMP = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/';

    public function __construct(
        private readonly PersonalRecordService $records,
        private readonly WeeklyStreakService $streak,
    ) {}

    /**
     * @param  array{client_session_id:string, routine_id?:string|int|null, routine_name?:string|null, started_at?:string|null, completed_at?:string|null, notes?:string|null, exercises?:array}  $payload
     * @return array{session: WorkoutSession, created: bool, records: \Illuminate\Support\Collection}
     */
    public function complete(Member $member, array $payload): array
    {
        $clientId = trim((string) $payload['client_session_id']);

        // Reentrada: la sesión ya se registró en un intento anterior.
        $existing = $this->findExisting($member, $clientId);

        if ($existing !== null) {
            return ['session' => $existing, 'created' => false, 'records' => collect()];
        }

        // Se normaliza y se valida ANTES de abrir la transacción: rechazar una
        // sesión incompleta no debe costar un rollback, y así la excepción sale
        // con la base de datos intacta.
        $exercises = $this->normalizeExercises($payload['exercises'] ?? []);
        $this->guardCompletable($exercises);

        $routine = $this->resolveRoutine($member, $payload['routine_id'] ?? null);

        try {
            $result = $this->persist($member, $payload, $clientId, $routine, $exercises);
        } catch (QueryException $e) {
            // Carrera: dos envíos del MISMO `client_session_id` pasaron los dos
            // el chequeo de reentrada antes de que ninguno insertara. El índice
            // único hizo su trabajo y no hay sesión duplicada; lo que falta es
            // no contestarle 500 a un cliente que hizo lo correcto.
            if (! $this->isReplayCollision($e)) {
                throw $e;
            }

            $winner = $this->findExisting($member, $clientId);
            if ($winner === null) {
                // La colisión fue de otra cosa que menciona la columna. No se
                // disfraza de éxito.
                throw $e;
            }

            return ['session' => $winner, 'created' => false, 'records' => collect()];
        }

        return $this->afterCommit($member, $result);
    }

    /**
     * Crea la sesión y todo lo que cuelga de ella, o nada.
     *
     * @param  list<array<string, mixed>>  $exercises
     * @return array{session: WorkoutSession, records: \Illuminate\Support\Collection}
     */
    private function persist(
        Member $member,
        array $payload,
        string $clientId,
        ?Routine $routine,
        array $exercises,
    ): array {
        return DB::transaction(function () use ($member, $payload, $clientId, $routine, $exercises): array {
            $completedAt = $this->parseTime($payload['completed_at'] ?? null) ?? CarbonImmutable::now();
            $startedAt = $this->parseTime($payload['started_at'] ?? null);

            // La duración sale de timestamps reales, nunca del cronómetro de la
            // pantalla. Si la app no mandó inicio, no se inventa: queda en 0.
            //
            // El redondeo a entero NO es cosmético: `diffInSeconds()` devuelve
            // FLOAT en Carbon 3 (en Carbon 2 devolvía int) y la app manda las
            // horas con milisegundos, así que salía 61.081. La columna es
            // `unsignedInteger` y PostgreSQL rechazaba el insert con 22P02
            // ("invalid input syntax for type integer"). SQLite, que es lo que
            // usan los tests, sí lo aceptaba: por eso pasaba en local y
            // reventaba en producción.
            $duration = 0;
            if ($startedAt !== null) {
                $duration = (int) round(max(0, $startedAt->diffInSeconds($completedAt)));
            }

            $completion = null;
            if ($routine !== null) {
                $completion = RoutineCompletion::create([
                    'member_id' => $member->id,
                    'routine_id' => $routine->id,
                    'completed_at' => $completedAt,
                    'source' => 'app',
                    'notes' => $payload['notes'] ?? null,
                ]);
            }

            $session = WorkoutSession::create([
                'member_id' => $member->id,
                'routine_id' => $routine?->id,
                'routine_completion_id' => $completion?->id,
                'client_session_id' => $clientId,
                'routine_name' => $payload['routine_name'] ?? $routine?->name,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'duration_seconds' => $duration,
                'source' => 'app',
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->storeExercises($session, $exercises);
            $this->storeSets($session, $exercises, $completedAt);
            $this->refreshTotals($session);

            // Los récords se derivan DENTRO de la transacción: o queda todo
            // (sesión + series + completion + récords) o no queda nada. Un fallo
            // aquí no puede dejar una sesión guardada con récords a medias.
            $session->load('sets');
            $records = $this->records->evaluateSession($member, $session);

            return ['session' => $session, 'records' => $records];
        });
    }

    /**
     * Lo que ocurre una vez la sesión ya está a salvo en disco.
     *
     * @param  array{session: WorkoutSession, records: \Illuminate\Support\Collection}  $result
     * @return array{session: WorkoutSession, created: bool, records: \Illuminate\Support\Collection}
     */
    private function afterCommit(Member $member, array $result): array
    {
        /** @var WorkoutSession $session */
        $session = $result['session'];
        /** @var \Illuminate\Support\Collection $records */
        $records = $result['records'];

        $session->load('sets.exercise');

        // Efectos externos, fuera de la transacción y best-effort: no forman
        // parte del registro durable y no pueden tumbar un entrenamiento que el
        // socio ya terminó.
        try {
            // Entrenar cuenta como día activo. `touch` es idempotente por
            // (miembro, fecha) en hora de Bogotá: dos rutinas el mismo día no
            // suman dos días de racha.
            $this->streak->touch($member, 'workout');
        } catch (\Throwable $e) {
            Log::warning('workout.streak_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        if ($session->completion !== null) {
            try {
                app(NotificationService::class)
                    ->notifyRoutineCompleted($member, $session->routine, $session->routine_completion_id);
            } catch (\Throwable $e) {
                Log::warning('workout.notify_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
            }
        }

        return ['session' => $session, 'created' => true, 'records' => $records];
    }

    /**
     * La sesión ya registrada para este `client_session_id`, si la hay.
     *
     * Protegido y no privado a propósito: es la costura por la que se puede
     * reproducir la carrera de dos envíos simultáneos, que de otro modo no
     * habría forma honesta de provocar en un test.
     */
    protected function findExisting(Member $member, string $clientSessionId): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->with('sets')
            ->where('member_id', $member->id)
            ->where('client_session_id', $clientSessionId)
            ->first();
    }

    /**
     * Deja el payload en una forma única, decidida y validada.
     *
     * Aquí se resuelve de una vez la identidad de cada ejercicio (nombre,
     * clave, id de catálogo y ORDEN) para que las series y la fila de estado
     * usen exactamente los mismos valores. Antes cada uno lo recalculaba por su
     * cuenta y bastaba con que una de las dos derivaciones cambiara para que
     * dejaran de cruzarse.
     *
     * El ORDEN es la identidad dentro de la sesión: `exercise_id` puede ser
     * null y puede repetirse dentro de la misma rutina.
     *
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeExercises(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (array_values($raw) as $index => $exercise) {
            if (! is_array($exercise)) {
                continue;
            }

            $name = trim((string) ($exercise['name'] ?? ''));
            $key = WorkoutSessionSet::normalizeKey($name);

            $sets = [];
            foreach (array_values($exercise['sets'] ?? []) as $set) {
                if (is_array($set)) {
                    $sets[] = $set;
                }
            }

            $out[] = [
                'name' => $name !== '' ? $name : 'Ejercicio',
                'key' => $key !== '' ? $key : 'ejercicio',
                'exercise_id' => $this->resolveExerciseId($exercise['exercise_id'] ?? null, $name),
                'order' => (int) ($exercise['order'] ?? $index),
                'sets' => $sets,
                'status' => $this->parseStatus($exercise['status'] ?? null),
                'skip_reason' => $this->parseSkipReason($exercise['skip_reason'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Un estado declarado por el cliente, o null si no declaró ninguno.
     *
     * Un valor desconocido NO se degrada a `pending`: se rechaza. Aceptar
     * basura convirtiéndola en un estado plausible es cómo un cliente roto
     * acabaría cerrando rutinas a medias sin que nadie se entere.
     */
    private function parseStatus(mixed $value): ?WorkoutExerciseStatus
    {
        if (blank($value)) {
            return null;
        }

        return WorkoutExerciseStatus::tryFrom((string) $value)
            ?? throw WorkoutSessionException::invalidStatus((string) $value);
    }

    private function parseSkipReason(mixed $value): ?WorkoutSkipReason
    {
        if (blank($value)) {
            return null;
        }

        return WorkoutSkipReason::tryFrom((string) $value)
            ?? throw WorkoutSessionException::invalidSkipReason((string) $value);
    }

    /**
     * ¿Puede cerrarse esta sesión?
     *
     * REGLA: todos los ejercicios COMPLETED. Ni `pending`, ni `in_progress`, ni
     * `skipped_temporarily` —que es temporal y por tanto sigue pendiente—. No
     * se mira ningún índice ni ninguna posición: por dónde iba el socio en la
     * pantalla no dice nada sobre lo que terminó.
     *
     * Todos los ejercicios del payload son requeridos. No existe hoy la noción
     * de ejercicio opcional: `routine_exercises` no tiene columna que lo
     * exprese y en producción no hay una sola fila con `sets = 0` (90 filas,
     * mínimo 3), así que no se inventa una excepción para un caso que el
     * sistema no puede producir.
     *
     * Solo aplica al contrato NUEVO. Ver `LEGACY_COMPLETION_POLICY` arriba.
     *
     * @param  list<array<string, mixed>>  $exercises
     */
    private function guardCompletable(array $exercises): void
    {
        $declared = array_filter($exercises, static fn (array $e) => $e['status'] !== null);

        // Contrato legacy: ni un solo estado declarado. Se guarda como siempre.
        if ($declared === []) {
            return;
        }

        if (count($declared) !== count($exercises)) {
            throw WorkoutSessionException::mixedContract();
        }

        // «Al menos un ejercicio» no necesita comprobación propia: el contrato
        // nuevo se reconoce PORQUE algún ejercicio declara estado, así que una
        // lista vacía nunca llega hasta aquí —se fue por la rama legacy—. Una
        // comprobación que no puede fallar solo aparenta rigor.
        $blocking = array_filter(
            $exercises,
            static fn (array $e) => $e['status']->blocksCompletion(),
        );

        if ($blocking !== []) {
            throw WorkoutSessionException::incomplete(count($blocking));
        }
    }

    /**
     * ¿Este fallo es la carrera de dos envíos con el mismo `client_session_id`?
     *
     * Se comprueba el SQLSTATE y además que la colisión mencione la columna:
     * capturar cualquier `QueryException` convertiría en «ya estaba guardado»
     * un fallo real de escritura, y el socio se iría a casa creyendo que su
     * entrenamiento quedó registrado.
     *
     * 23505 es PostgreSQL (producción); 23000 es el genérico de SQLite y MySQL
     * (tests y entornos locales). Ambos se comportan igual aquí.
     */
    private function isReplayCollision(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        if ($sqlState !== '23505' && $sqlState !== '23000') {
            return false;
        }

        return str_contains($e->getMessage(), 'client_session_id');
    }

    /**
     * Interpreta un instante enviado por la app y lo normaliza a UTC, que es
     * como se almacena.
     *
     * El valor puede llegar de dos formas y NO son equivalentes:
     *
     *  - CON zona (`...Z` o `...-05:00`): el instante es inequívoco. Se
     *    conserva tal cual y solo se cambia la representación a UTC.
     *  - SIN zona (`2026-08-17T01:16:41.123`): así mandaban las versiones
     *    anteriores de la app, porque `DateTime.now().toIso8601String()` de
     *    Dart emite la hora LOCAL sin designador. Carbon la leía como UTC y el
     *    entrenamiento se archivaba 5 h en el pasado: uno hecho el lunes a la
     *    1 a.m. quedaba fechado el domingo y desaparecía de "esta semana".
     *    Ese formato solo puede venir de un reloj de Bogotá, así que se
     *    interpreta explícitamente en {@see self::TZ} antes de convertir.
     *
     * Una fecha ilegible se descarta en vez de romper el registro de un
     * entrenamiento ya terminado.
     */
    private function parseTime(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        $value = trim($value);

        try {
            $tz = preg_match(self::NAIVE_TIMESTAMP, $value) === 1 ? self::TZ : null;

            return CarbonImmutable::parse($value, $tz)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Solo rutinas propias o asignadas al miembro. */
    private function resolveRoutine(Member $member, string|int|null $routineId): ?Routine
    {
        if (blank($routineId)) {
            return null;
        }

        $routine = Routine::find($routineId);
        if ($routine === null) {
            return null;
        }

        $owns = (int) $routine->member_id === (int) $member->id
            || DB::table('member_routine_assignments')
                ->where('routine_id', $routine->id)
                ->where('member_id', $member->id)
                ->exists();

        return $owns ? $routine : null;
    }

    /**
     * Persiste las series. El nombre del ejercicio se guarda como snapshot y se
     * intenta resolver el id del catálogo por id explícito o por nombre
     * normalizado, para que los récords agrupen bien aunque la rutina traiga el
     * ejercicio incompleto.
     */
    private function storeSets(WorkoutSession $session, array $exercises, CarbonImmutable $completedAt): void
    {
        foreach ($exercises as $exercise) {
            foreach (array_values($exercise['sets']) as $setIndex => $set) {
                WorkoutSessionSet::create([
                    'workout_session_id' => $session->id,
                    'exercise_id' => $exercise['exercise_id'],
                    'exercise_name' => $exercise['name'],
                    'exercise_key' => $exercise['key'],
                    'exercise_order' => $exercise['order'],
                    'set_number' => (int) ($set['set_number'] ?? $setIndex + 1),
                    'reps' => isset($set['reps']) ? max(0, (int) $set['reps']) : null,
                    'weight_kg' => isset($set['weight_kg']) ? max(0, (float) $set['weight_kg']) : null,
                    'rpe' => isset($set['rpe']) ? max(0, min(10, (int) $set['rpe'])) : null,
                    'completed' => (bool) ($set['completed'] ?? false),
                    'performed_at' => $completedAt,
                ]);
            }
        }
    }

    /**
     * Persiste el estado de cada ejercicio.
     *
     * Se guarda TAL CUAL llegó lo que el socio mandó, incluidas las series
     * prescritas que dejó sin marcar: son el registro de «esto estaba previsto
     * y no se ejecutó», y no cuentan en ningún total porque volumen, series y
     * récords ya filtran por `completed`. Descartarlas al guardar no haría el
     * dato más real, solo lo haría desaparecer.
     *
     * `updateOrCreate` por (sesión, orden) y no `create`: si un payload
     * malformado repitiera posición, la segunda entrada pisa a la primera en
     * vez de reventar contra el índice único con un 500. Las series ya se
     * comportaban así frente a un duplicado exacto.
     *
     * @param  list<array<string, mixed>>  $exercises
     */
    private function storeExercises(WorkoutSession $session, array $exercises): void
    {
        foreach ($exercises as $exercise) {
            WorkoutSessionExercise::updateOrCreate(
                [
                    'workout_session_id' => $session->id,
                    'exercise_order' => $exercise['order'],
                ],
                [
                    'exercise_id' => $exercise['exercise_id'],
                    'exercise_name' => $exercise['name'],
                    'exercise_key' => $exercise['key'],
                    'status' => $status = $exercise['status'] ?? $this->deriveStatus($exercise),
                    // El motivo solo tiene sentido junto a un salto. Un
                    // «completado porque la máquina estaba ocupada» es una
                    // contradicción, y guardarla dejaría el historial diciendo
                    // dos cosas a la vez.
                    'skip_reason' => $status === WorkoutExerciseStatus::SKIPPED_TEMPORARILY
                        ? $exercise['skip_reason']
                        : null,
                ],
            );
        }
    }

    /**
     * Estado de un ejercicio que llegó SIN declararlo (contrato legacy).
     *
     * Se lee de sus series, que es el único dato que la app antigua manda: si
     * ejecutó al menos una, el ejercicio se hizo; si no ejecutó ninguna, quedó
     * pendiente. No se usa `skipped_temporarily` ni `in_progress` porque el
     * payload viejo no distingue «lo dejé a medias» de «no lo empecé», y
     * elegir uno de los dos sería inventar intención.
     *
     * @param  array<string, mixed>  $exercise
     */
    private function deriveStatus(array $exercise): WorkoutExerciseStatus
    {
        foreach ($exercise['sets'] as $set) {
            if (($set['completed'] ?? false) == true) {
                return WorkoutExerciseStatus::COMPLETED;
            }
        }

        return WorkoutExerciseStatus::PENDING;
    }

    private function resolveExerciseId(string|int|null $explicitId, string $name): ?int
    {
        if (filled($explicitId) && Exercise::whereKey($explicitId)->exists()) {
            return (int) $explicitId;
        }

        if ($name === '') {
            return null;
        }

        return Exercise::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
    }

    /** Recalcula y persiste los totales desde lo que quedó guardado. */
    private function refreshTotals(WorkoutSession $session): void
    {
        $sets = $session->sets()->get();

        $volume = $sets->sum(fn (WorkoutSessionSet $s) => $s->volume());

        $session->forceFill([
            'total_volume_kg' => round((float) $volume, 2),
            // "Series" cuenta las ejecutadas, no las prescritas.
            'total_sets' => $sets->where('completed', true)->count(),
            // Y "ejercicios" tampoco cuenta ya lo prescrito. Contaba claves
            // únicas sobre TODAS las series, incluidas las que la app manda
            // rellenas desde la rutina y el socio nunca marcó: una rutina de
            // cinco abandonada en el primero se archivaba como cinco
            // ejercicios entrenados, junto al volumen y las series reales de
            // uno. El resumen del socio decía una cosa y sus números otra.
            'total_exercises' => $session->completedExerciseCount(),
        ])->save();
    }

    /**
     * Resumen de una sesión ya registrada (para reabrir la pantalla o
     * consultarla más tarde).
     */
    public function findForMember(Member $member, string $clientSessionId): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->with('sets.exercise')
            ->where('member_id', $member->id)
            ->where('client_session_id', $clientSessionId)
            ->first();
    }
}
