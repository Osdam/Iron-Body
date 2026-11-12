<?php

namespace App\Services\Outreach\Run;

use App\Models\Admin;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\SubjectFacts;
use App\Services\Outreach\Facts\SubjectKey;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * LA BANDEJA ASISTIDA (contrato H9, D9-9.5, §7 E11–E13; §13.1 #22; T6): los
 * trabajos `assisted` que SALIERON (`started`) en vivo y aún no tienen el
 * resultado del personal. Una persona con `commercial_automation.assisted_work`
 * los ve, RECLAMA uno (una sola vez: la base lo fija) y registra el resultado
 * del conjunto cerrado (fuente `staff`), idempotente por `clientRequestId`.
 * Solo quien lo reclamó registra su resultado (BOLA).
 *
 * LA API NO DEVUELVE TELÉFONOS NI NOMBRES: la estrategia, el objetivo, el
 * motivo (las frases de la traza), el guion de la plantilla, el plazo y la
 * referencia a la ficha de la persona en el CRM (`person: {kind, id}`), que
 * protegen los permisos que ya existen. La persona sigue siendo responsable
 * del contacto real: la política aplicó horario, frecuencia y consentimiento
 * DEL MEDIO.
 *
 * LA BANDEJA VUELVE A MIRAR, AL OFRECER, AL RECLAMAR Y AL ENSEÑAR LO
 * RECLAMADO (M-5, I-1, I-2, I-3, N-1 y N-2 de security): una inscripción que
 * espera el resultado asistido no se reevalúa, así que lo que la escalera
 * negaría HOY por la persona o por la estrategia lo ve la bandeja, con las
 * MISMAS entradas de la escalera. Cada trabajo tiene, como mucho, UN motivo de
 * retención, por este orden ({@see WITHHELD_REASONS}):
 *  - `brake`: la PARADA GLOBAL, el freno del canal del trabajo o el de su
 *    estrategia, con la misma lectura que N2 ({@see OutreachStop::openScopes()});
 *  - `strategy_stopped`: la (versión, parte) de su inscripción ya no está en
 *    marcha en la cadena: pausada, apagada, revertida, retirada, archivada o
 *    ilegible (las entradas de N5, {@see LiveStrategy}; N-2);
 *  - `opted_out`: una señal de «no contactar» de N1/N2 que se SABE: el
 *    `do_not_contact` (suyo o de un prospecto con su número), el *opt-out*
 *    comercial, el consentimiento del medio denegado, la exclusión de voz y la
 *    supresión del libro ({@see CoreProjection::policyInput()},
 *    {@see SubjectSuppressions}; N-1);
 *  - `complaint_recent`: N6, una queja dentro de la ventana;
 *  - `policy_unknown`: lo de `opted_out` no se puede comprobar (persona sin
 *    resolver o clave que ya no es de una persona): falla cerrado;
 *  - `complaint_unknown`: N6 sin datos de eventos (falla cerrado);
 *  - `expired`: el plazo del trabajo (`expires_at <= ahora`, el borde del
 *    despachador) ya pasó: la política se evaluó para ese plazo.
 * Si algo de eso no se puede LEER (frenos, cadena, foto, libro), la excepción
 * sube: ningún `catch`, falla cerrado, como el despachador.
 * Un trabajo SIN reclamar con motivo no se ofrece ni se reclama (409
 * `not_in_queue`; el motivo, al registro). Uno YA reclamado se queda para que
 * su reclamante registre el resultado, pero sin `person` ni `script` y con
 * `contactWithheld` = el motivo (solo lo ve quien lo reclamó). No se escribe
 * nada: el trabajo y la inscripción siguen como estaban hasta el resultado o
 * el fin de la ventana. `human_requested` no se mira aquí (L9-66: una derivada
 * a persona sigue viva en la bandeja). Los frenos ajenos al H9 (cerrojo de
 * ULTRON, pausa del agente, freno de voz) tampoco: D-3, decisión del propietario.
 *
 * LA PÁGINA SE LLENA (I-4): lo sin reclamar vencido, frenado o de una parte
 * que no está en marcha se descarta en SQL (como la reclamación del
 * despachador) y se cuenta; lo de la persona, en PHP, por tandas de
 * {@see SCAN_BATCH} filas (una foto y una consulta de supresión por tanda)
 * hasta llenar `limit` o hasta {@see MAX_SCAN} filas leídas (`scanCapped`,
 * m-3). Cuántos se retuvieron va en `withheld`, sin decir de quién.
 */
final class AssistedQueue
{
    public const WORK = 'commercial_automation.assisted_work';

    public const MAX_PAGE = 50;

    /** Lo que el personal puede registrar (D9-10, columna «asistido»). */
    public const STAFF_OUTCOMES = ['no_answer', 'answered', 'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked'];

    /** Los motivos de retención de un trabajo (`contactWithheld`), en orden de prioridad: una fila lleva uno solo. */
    public const WITHHELD_BRAKE = 'brake';

    public const WITHHELD_STRATEGY_STOPPED = 'strategy_stopped';

    public const WITHHELD_OPTED_OUT = 'opted_out';

    public const WITHHELD_COMPLAINT_RECENT = 'complaint_recent';

    public const WITHHELD_POLICY_UNKNOWN = 'policy_unknown';

    public const WITHHELD_COMPLAINT_UNKNOWN = 'complaint_unknown';

    public const WITHHELD_EXPIRED = 'expired';

    public const WITHHELD_REASONS = [
        self::WITHHELD_BRAKE, self::WITHHELD_STRATEGY_STOPPED, self::WITHHELD_OPTED_OUT, self::WITHHELD_COMPLAINT_RECENT,
        self::WITHHELD_POLICY_UNKNOWN, self::WITHHELD_COMPLAINT_UNKNOWN, self::WITHHELD_EXPIRED,
    ];

    /** I-4: las filas que se leen por tanda para llenar una página (una foto de quejas por tanda). */
    public const SCAN_BATCH = self::MAX_PAGE;

    /** I-4: el tope de filas leídas por página (4 tandas; ≤ `BatchSnapshot::MAX_SUBJECTS`). */
    public const MAX_SCAN = 4 * self::SCAN_BATCH;

    /**
     * Estados de la inscripción con los que un trabajo asistido sigue vivo en la bandeja: abierta
     * (`active`/`waiting`) o derivada a una persona (`handed_off`, el caso `human_requested`).
     * Cerrada de otro modo (vencida, cancelada, completada, salida, opt-out) el trabajo no se
     * reclama ni admite resultado (REV9 m-7, L9-66).
     */
    public const QUEUE_ENROLLMENT_STATUSES = ['active', 'waiting', 'handed_off'];

    /** Las columnas de una fila de la bandeja (el trabajo y lo que su inscripción y su estrategia deciden). */
    private const ROW_COLUMNS = [
        'j.*', 'e.purpose', 'e.uuid as enrollment_uuid', 'e.step_index as enrollment_step', 'e.strategy_version_id as enrollment_version_id',
        'e.part as enrollment_part', 's.uuid as strategy_uuid', 's.key as strategy_key',
    ];

    public function __construct(
        private readonly OutreachStore $store = new OutreachStore,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
        private readonly OutreachStop $stop = new OutreachStop,
        private readonly SubjectSuppressions $suppressions = new SubjectSuppressions,
    ) {}

    /**
     * Los trabajos de la bandeja: `started`, asistidos, en vivo, sin resultado
     * del personal; `mine` = los que reclamó quien mira, `unclaimed` = sin
     * reclamar, `all` = los dos. Los más urgentes (menor plazo) primero.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(Admin $viewer, string $filter = 'all', int $limit = 20): array
    {
        return $this->page($viewer, $filter, $limit)['items'];
    }

    /**
     * La página de la bandeja ({@see queue()}), cuántos trabajos SIN reclamar
     * y no vencidos de esa vista no se ofrecen (`withheld`, I-4) y si la
     * lectura llegó a su tope sin llenar la página (`scanCapped`, m-3: puede
     * haber trabajo ofrecible detrás de los retenidos). `withheld` suma los
     * frenados y los de una parte que no está en marcha (un recuento exacto en
     * SQL) y los retenidos por la persona que se encontraron al llenarla (cota
     * inferior si se llenó o se llegó a {@see MAX_SCAN}). En `mine` es siempre
     * 0: allí ninguna fila se quita, solo se le retiene el contacto. Sin decir
     * de quién: un número.
     *
     * @return array{items: list<array<string, mixed>>, withheld: int, scanCapped: bool}
     */
    public function page(Admin $viewer, string $filter = 'all', int $limit = 20): array
    {
        $limite = max(1, min(self::MAX_PAGE, $limit));
        $yo = (int) $viewer->id;
        $ahora = self::now();
        // I-3: los frenos con la MISMA lectura que N2 (una consulta), lo primero. Si no se pueden leer, la excepción sube: falla cerrado, como el despachador.
        $abiertos = $this->stop->openScopes();
        // N-2: la cadena de las estrategias de la vista, una vez por página (antes de leer ninguna fila).
        $partes = $this->viewParts($yo, $filter);
        $enMarcha = $this->runningParts(array_map(static fn (object $p): int => (int) $p->strategy_id, $partes));
        $hayParadas = array_filter($partes, static fn (object $p): bool => ! isset($enMarcha[self::partKey((int) $p->strategy_version_id, (string) $p->part)])) !== [];
        $filas = [];
        $retenidos = 0;
        $leidas = 0;
        $ultima = null;
        while ($leidas < self::MAX_SCAN) {
            $tamano = min(self::SCAN_BATCH, self::MAX_SCAN - $leidas);
            $tanda = $this->queueRows($yo, $filter, $ahora, $abiertos, $enMarcha, $ultima, $tamano);
            if ($tanda === []) {
                break;
            }
            $leidas += count($tanda);
            $ultima = $tanda[count($tanda) - 1];
            // Se miran lo sin reclamar (¿se ofrece?) y lo de quien mira (¿se le retiene el contacto?); lo de otra persona, no: no es suyo.
            $motivos = $this->withholdReasons(array_values(array_filter($tanda, static fn (object $j): bool => $j->assisted_claimed_by_admin_id === null || (int) $j->assisted_claimed_by_admin_id === $yo)), $ahora, $abiertos, $enMarcha);
            foreach ($tanda as $j) {
                $motivo = $motivos[(int) $j->id] ?? null;
                if ($motivo !== null && $j->assisted_claimed_by_admin_id === null) {
                    $retenidos++;

                    continue;
                }
                $filas[] = $this->dto($j, $yo, $motivo);
                if (count($filas) >= $limite) {
                    break 2;
                }
            }
            if (count($tanda) < $tamano) {
                break;
            }
        }

        return [
            'items' => $filas,
            'withheld' => $retenidos + $this->heldCount($yo, $filter, $ahora, $abiertos, $enMarcha, $hayParadas),
            // m-3: se leyeron MAX_SCAN filas (todas las tandas llenas) y la página no se llenó.
            'scanCapped' => $leidas >= self::MAX_SCAN && count($filas) < $limite,
        ];
    }

    /** El detalle de un trabajo de la bandeja (404 si no es asistido en vivo). @return array<string, mixed> */
    public function show(string $jobUuid): array
    {
        return $this->dto($this->assistedJob($jobUuid));
    }

    /**
     * Reclamar UNA vez. 404 si no existe; 409 si no está en la bandeja
     * (`not_in_queue`, también con cualquier motivo de retención,
     * {@see WITHHELD_REASONS}), si su inscripción ya se cerró
     * (`enrollment_closed`, también en la repetición: m-1) o si ya lo reclamó
     * otra persona (`already_claimed`); idempotente para quien lo reclamó, con
     * el contacto retenido si ahora hay un motivo (I-1). Si un freno se echa
     * entre la comprobación y la escritura (m-2), la fila ya reclamada vuelve
     * sin contacto (`contactWithheld: brake`); no se deshace nada.
     *
     * @return array{job: array<string, mixed>, replayed: bool}
     */
    public function claim(Admin $by, string $jobUuid, string $clientRequestId): array
    {
        if (! Str::isUuid($clientRequestId)) {
            throw OutreachException::invalid(['clientRequestId' => ['uuid']]);
        }
        $trabajo = $this->assistedJob($jobUuid);
        if ((string) $trabajo->status !== 'started') {
            throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
        }
        // I-3: los frenos, ANTES de repetir o de reclamar. Si no se pueden leer, la excepción sube: no se entrega ni se reclama nada.
        $abiertos = $this->stop->openScopes();
        $ahora = self::now();
        if ($trabajo->assisted_claimed_by_admin_id !== null) {
            if ((int) $trabajo->assisted_claimed_by_admin_id === (int) $by->id) {
                // m-1: la repetición tampoco entrega nada de una inscripción cerrada.
                $this->requireOpenEnrollment((int) $trabajo->enrollment_id);
                $motivo = $this->withholdReasons([$trabajo], $ahora, $abiertos, $this->runningParts([(int) $trabajo->strategy_id]))[(int) $trabajo->id] ?? null;

                return ['job' => $this->dto($trabajo, (int) $by->id, $motivo), 'replayed' => true];
            }
            throw OutreachException::conflict('already_claimed', 'Otra persona ya reclamó ese trabajo.');
        }
        $this->requireOpenEnrollment((int) $trabajo->enrollment_id);
        $this->requireNotWithheld($trabajo, $ahora, $abiertos, $this->runningParts([(int) $trabajo->strategy_id]));
        $cambiadas = DB::table(OutreachTables::JOBS)->where('id', $trabajo->id)->where('status', 'started')->whereNull('assisted_claimed_by_admin_id')->update([
            'assisted_claimed_by_admin_id' => (int) $by->id,
            'assisted_claimed_by_name' => mb_substr((string) $by->name, 0, 120),
            'assisted_claimed_at' => OutreachStore::sql($ahora),
            'updated_at' => OutreachStore::sql($ahora),
        ]);
        if ($cambiadas !== 1) {
            throw OutreachException::conflict('already_claimed', 'Otra persona ya reclamó ese trabajo.');
        }
        // m-2: los frenos OTRA VEZ tras escribir (una consulta). Un freno echado entre la lectura y el UPDATE retiene el contacto ya en esta respuesta.
        $frenado = in_array(true, OutreachStop::ladderBrakes($this->stop->openScopes(), (string) $trabajo->strategy_uuid, (string) $trabajo->channel), true);

        return ['job' => $this->dto($this->assistedJob($jobUuid), (int) $by->id, $frenado ? self::WITHHELD_BRAKE : null), 'replayed' => false];
    }

    /**
     * El resultado del personal (fuente `staff`), solo de quien reclamó el
     * trabajo (403 `not_claimer`), del conjunto cerrado del asistido (422), e
     * idempotente por `clientRequestId` (la misma petición devuelve
     * `replayed`). `callbackAt` acompaña a `callback_requested` (N7: la
     * escalera lo aplaza dentro de la ley y la ventana).
     *
     * @return array{result: string, outcome: string, transition: string|null, replayed: bool}
     */
    public function recordOutcome(Admin $by, string $jobUuid, string $outcome, string $clientRequestId, ?string $callbackAt = null): array
    {
        $errores = [];
        if (! Str::isUuid($clientRequestId)) {
            $errores['clientRequestId'] = ['uuid'];
        }
        if (! in_array($outcome, self::STAFF_OUTCOMES, true)) {
            $errores['outcome'] = ['not_allowed_for_staff'];
        }
        if ($callbackAt !== null && (! RelativeTime::isInstant($callbackAt) || RelativeTime::parseInstant($callbackAt) <= now())) {
            $errores['callbackAt'] = ['future_instant'];
        }
        if ($callbackAt !== null && $outcome !== 'callback_requested') {
            $errores['callbackAt'] = ['only_with_callback_requested'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $trabajo = $this->assistedJob($jobUuid);
        if ((string) $trabajo->status !== 'started') {
            throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
        }
        if ($trabajo->assisted_claimed_by_admin_id === null || (int) $trabajo->assisted_claimed_by_admin_id !== (int) $by->id) {
            throw new OutreachException('Solo quien reclamó el trabajo registra su resultado.', 'not_claimer', 403);
        }
        $e = $this->store->enrollment((int) $trabajo->enrollment_id);
        if ($e === null) {
            throw OutreachException::notFound('enrollment');
        }
        $clave = 'staff:'.strtolower($clientRequestId);
        $previo = DB::table(OutreachTables::OUTCOMES)->where('enrollment_id', $e->id)->where('source', 'staff')->where('source_key', $clave)->first(['outcome', 'next_action']);
        if ($previo !== null) {
            return ['result' => OutcomeRecorder::RESULT_DUPLICATE, 'outcome' => (string) $previo->outcome, 'transition' => $previo->next_action, 'replayed' => true];
        }
        // Tras la repetición idempotente (la misma petición devuelve lo mismo aunque la inscripción ya se haya cerrado con ese resultado).
        if (! in_array((string) $e->status, self::QUEUE_ENROLLMENT_STATUSES, true)) {
            throw OutreachException::conflict('enrollment_closed', 'La inscripción de ese trabajo ya se cerró.');
        }
        if (DB::table(OutreachTables::OUTCOMES)->where('job_id', $trabajo->id)->where('source', 'staff')->exists()) {
            throw OutreachException::conflict('outcome_already_recorded', 'Ese trabajo ya tiene el resultado del personal.');
        }
        $r = $this->outcomes->record($e, $outcome, 'staff', $clave, RelativeTime::utc(now()), ['job' => $trabajo, 'recordedBy' => $by, 'callbackAt' => $callbackAt]);
        if ($r['result'] === OutcomeRecorder::RESULT_IGNORED) {
            throw OutreachException::invalid(['outcome' => ['not_allowed_for_staff']]);
        }

        return ['result' => $r['result'], 'outcome' => (string) $r['outcome'], 'transition' => $r['transition'], 'replayed' => false];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** m-7 (REV9, L9-66): un trabajo asistido cuya inscripción ya se cerró (vencida, cancelada) no se reclama ni admite resultado. */
    private function requireOpenEnrollment(int $enrollmentId): void
    {
        $e = $this->store->enrollment($enrollmentId);
        if ($e === null || ! in_array((string) $e->status, self::QUEUE_ENROLLMENT_STATUSES, true)) {
            throw OutreachException::conflict('enrollment_closed', 'La inscripción de ese trabajo ya se cerró.');
        }
    }

    /**
     * M-5, I-2, I-3, N-1 y N-2: un trabajo sin reclamar con un motivo de retención no se reclama. Al CRM,
     * `not_in_queue` (la bandeja ya no lo ofrece); el motivo, al registro, sin datos personales. La queja conserva
     * la forma de M-5 (`complaint_recent` y `known`: false = sin datos); los demás, solo su código.
     *
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @param  array<string, array{0: int, 1: string}>  $enMarcha  {@see runningParts()}
     */
    private function requireNotWithheld(object $trabajo, DateTimeImmutable $ahora, array $abiertos, array $enMarcha): void
    {
        $motivo = $this->withholdReasons([$trabajo], $ahora, $abiertos, $enMarcha)[(int) $trabajo->id] ?? null;
        if ($motivo === null) {
            return;
        }
        $contexto = in_array($motivo, [self::WITHHELD_COMPLAINT_RECENT, self::WITHHELD_COMPLAINT_UNKNOWN], true)
            ? ['job' => (string) $trabajo->uuid, 'reason' => self::WITHHELD_COMPLAINT_RECENT, 'known' => $motivo === self::WITHHELD_COMPLAINT_RECENT]
            : ['job' => (string) $trabajo->uuid, 'reason' => $motivo];
        Log::warning('outreach.assisted.claim_withheld', $contexto);

        throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
    }

    /**
     * El motivo de retención de cada trabajo (uno, por la prioridad de {@see WITHHELD_REASONS}), o ninguno. El
     * freno, con la traducción de la escalera ({@see OutreachStop::ladderBrakes()}); la estrategia, con las partes
     * en marcha leídas una vez ({@see runningParts()}); lo de la persona, solo de los que pasan esas dos (una foto y
     * una consulta de supresión para todos, {@see personReasons()}); el plazo, con el borde del despachador
     * (`expires_at <= ahora`).
     *
     * @param  list<object>  $trabajos  filas con `id`, `subject_key`, `purpose`, `channel`, `medium`, `strategy_uuid`,
     *                                  `enrollment_version_id`, `enrollment_part` y `expires_at`
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @param  array<string, array{0: int, 1: string}>  $enMarcha  {@see runningParts()}
     * @return array<int, string> por id de trabajo con motivo
     */
    private function withholdReasons(array $trabajos, DateTimeImmutable $ahora, array $abiertos, array $enMarcha): array
    {
        $motivos = [];
        $porPersona = [];
        foreach ($trabajos as $j) {
            if (in_array(true, OutreachStop::ladderBrakes($abiertos, (string) $j->strategy_uuid, (string) $j->channel), true)) {
                $motivos[(int) $j->id] = self::WITHHELD_BRAKE;
            } elseif (! isset($enMarcha[self::partKey((int) $j->enrollment_version_id, (string) $j->enrollment_part)])) {
                // N-2: una parte que la cadena no trae en marcha (o que apareció después de leerla): retenida.
                $motivos[(int) $j->id] = self::WITHHELD_STRATEGY_STOPPED;
            } else {
                $porPersona[] = $j;
            }
        }
        $deLaPersona = $this->personReasons($porPersona, $ahora);
        foreach ($porPersona as $j) {
            if (isset($deLaPersona[(int) $j->id])) {
                $motivos[(int) $j->id] = $deLaPersona[(int) $j->id];
            } elseif (OutreachStore::instant($j->expires_at) <= $ahora) {
                $motivos[(int) $j->id] = self::WITHHELD_EXPIRED;
            }
        }

        return $motivos;
    }

    /**
     * N-1 y M-5: lo que la escalera negaría HOY por la PERSONA de cada trabajo, con UNA foto del lote (la misma
     * para la queja y la política: {@see personSelection()}) y UNA consulta al libro de supresiones:
     *  - `opted_out` si se SABE una señal de «no contactar» ({@see optedOut()}, o la supresión global de N2 con la
     *    clave canónica, como `LadderInputBuilder`);
     *  - `complaint_recent` si se quejó dentro de la ventana ({@see LadderInputBuilder::complaintRecently()}, el
     *    lector del despachador, con la finalidad de la inscripción);
     *  - `policy_unknown` si la política no se puede comprobar (falla cerrado; decir «pidió que no la contacten»
     *    sin saberlo sería falso);
     *  - `complaint_unknown` si no hay datos de eventos para la queja (falla cerrado, como N6).
     * Una clave que ya no es de una persona (la supresión 1581 la deja en `x:<id>`) no se lee: no se sabe nada.
     *
     * @param  list<object>  $trabajos  filas con `id`, `subject_key`, `purpose` y `medium`
     * @return array<int, string> por id de trabajo retenido
     */
    private function personReasons(array $trabajos, DateTimeImmutable $ahora): array
    {
        if ($trabajos === []) {
            return [];
        }
        $claves = array_values(array_unique(array_filter(
            array_map(static fn (object $j): string => (string) $j->subject_key, $trabajos),
            static fn (string $c): bool => SubjectKey::isValid($c),
        )));
        $foto = $claves === [] ? null : $this->snapshots->build($claves, $ahora, self::personSelection());
        $politica = [];
        $preguntas = [];
        foreach ($trabajos as $j) {
            $s = $foto?->subject((string) $j->subject_key);
            $politica[(int) $j->id] = self::optedOut($s, (string) $j->purpose, (string) $j->medium);
            if ($politica[(int) $j->id] !== true && $s !== null && $s->resolved()) {
                $preguntas[(int) $j->id] = ['key' => (string) ($s->canonicalKey ?? $s->key), 'purpose' => (string) $j->purpose, 'medium' => (string) $j->medium];
            }
        }
        foreach ($this->suppressions->areSuppressed($preguntas) as $id => $suprimida) {
            if ($suprimida) {
                $politica[(int) $id] = true;
            }
        }
        $motivos = [];
        foreach ($trabajos as $j) {
            $s = $foto?->subject((string) $j->subject_key);
            $queja = LadderInputBuilder::complaintRecently($s === null ? null : CoreProjection::snapshot($s)->events, $ahora, (string) $j->purpose);
            $motivo = match (true) {
                $politica[(int) $j->id] === true => self::WITHHELD_OPTED_OUT,
                $queja === true => self::WITHHELD_COMPLAINT_RECENT,
                $politica[(int) $j->id] === null => self::WITHHELD_POLICY_UNKNOWN,
                $queja === null => self::WITHHELD_COMPLAINT_UNKNOWN,
                default => null,
            };
            if ($motivo !== null) {
                $motivos[(int) $j->id] = $motivo;
            }
        }

        return $motivos;
    }

    /**
     * La foto de la persona para la bandeja: la queja (SOLO `complaint_created`: `human_requested` no retira nada,
     * L9-66) y las entradas de N1/N2 por persona (`policy`). Los contactos, solo porque
     * {@see CoreProjection::policyInput()} los exige de una persona resuelta (no se usan). Sin los frenos ajenos
     * (`global`, D-3) ni hechos de condiciones.
     */
    private static function personSelection(): FactSelection
    {
        return new FactSelection(facts: [], events: ['complaint_created'], contacts: true, policy: true);
    }

    /**
     * N-1: ¿pidió la persona que no la contacten, con la MISMA entrada que N1/N2
     * ({@see CoreProjection::policyInput()})? `true` si se sabe alguna señal: `do_not_contact` (suyo o de un
     * prospecto con su número), el *opt-out* comercial con finalidad comercial, el consentimiento del MEDIO del
     * trabajo denegado o, por voz, la exclusión de voz (el «no volver a llamar» o el RNE). `null` si no se puede
     * comprobar: sin foto, persona sin resolver (`unknown_subject`), medio fuera del conjunto o un valor
     * desconocido. Un `true` sabido gana a un desconocido. La supresión del libro la añade {@see personReasons()}.
     * La escalera da por `denied` también un `lead.consent_denied` que no se sabe (falla cerrado); aquí ese caso es
     * desconocido (`null`): retiene igual, pero sin decir que lo pidió.
     */
    private static function optedOut(?SubjectFacts $s, string $purpose, string $medium): ?bool
    {
        if ($s === null || ! $s->resolved() || ! in_array($medium, OutreachCatalog::MEDIUMS, true)) {
            return null;
        }
        $entrada = CoreProjection::policyInput($s, $purpose, null);
        $senales = [
            $entrada['optOut']['doNotContact'],
            $entrada['optOut']['siblingDoNotContact'],
            $entrada['consent'][$medium] === 'denied' ? $s->policy('lead.consent_denied') : false,
        ];
        if ($purpose === OutreachCatalog::PURPOSE_COMMERCIAL) {
            $senales[] = $entrada['optOut']['commercial'];
        }
        if ($medium === OutreachCatalog::MEDIUM_VOICE) {
            $senales[] = $entrada['voice']['excluded'];
        }
        if (in_array(true, $senales, true)) {
            return true;
        }

        return in_array(null, $senales, true) ? null : false;
    }

    /**
     * N-2: las (versión, parte) EN MARCHA de unas estrategias, tal como está AHORA la cadena
     * ({@see OutreachPublishedStrategies::liveStrategies()}, solo de esas): la parte viva con `runnable()` (cadena
     * legible, versión en su estado vivo y contenido releído), sin `paused()` y en `active`. Son las entradas de
     * N5 (`published`, `paused`, `chainReadable`) de `LadderInputBuilder`. Lo que no está aquí (revertida,
     * retirada, archivada, apagada, ilegible o versión que no es de la estrategia) no está en marcha.
     *
     * @param  list<int>  $strategyIds
     * @return array<string, array{0: int, 1: string}> clave {@see partKey()} → [versión, parte]
     */
    private function runningParts(array $strategyIds): array
    {
        $enMarcha = [];
        foreach ($this->bridge->liveStrategies(array_values(array_unique($strategyIds))) as $l) {
            if ($l->runnable() && ! $l->paused() && $l->chainStatus === OutreachPublishedStrategies::STATUS_ACTIVE) {
                $enMarcha[self::partKey($l->versionId, $l->part)] = [$l->versionId, $l->part];
            }
        }

        return $enMarcha;
    }

    private static function partKey(int $versionId, string $part): string
    {
        return $versionId.':'.$part;
    }

    /**
     * N-2 en SQL: la inscripción es de una parte en marcha (lista blanca; sin ninguna, nada).
     *
     * @param  array<string, array{0: int, 1: string}>  $enMarcha
     */
    private static function inRunningPart(Builder $w, array $enMarcha): void
    {
        if ($enMarcha === []) {
            $w->whereIn('e.id', []);

            return;
        }
        foreach ($enMarcha as [$version, $parte]) {
            $w->orWhere(static fn (Builder $p) => $p->where('e.strategy_version_id', $version)->where('e.part', $parte));
        }
    }

    /** N-2: las (estrategia, versión, parte) distintas de la vista, para leer la cadena una vez. @return list<object> */
    private function viewParts(int $viewerId, string $filter): array
    {
        return $this->queueQuery($viewerId, $filter)->distinct()->get(['j.strategy_id', 'e.strategy_version_id', 'e.part'])->all();
    }

    /** La consulta de la bandeja: asistidos, en vivo, `started`, con la inscripción viva y sin resultado del personal, por vista. */
    private function queueQuery(int $viewerId, string $filter): Builder
    {
        $q = DB::table(OutreachTables::JOBS.' as j')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->where('j.channel', 'assisted')->where('j.mode', OutreachConfig::MODE_LIVE)->where('j.status', 'started')
            ->whereIn('e.status', self::QUEUE_ENROLLMENT_STATUSES)
            ->whereNotExists(fn ($w) => $w->from(OutreachTables::OUTCOMES.' as o')->whereColumn('o.job_id', 'j.id')->where('o.source', 'staff'));
        if ($filter === 'mine') {
            $q->where('j.assisted_claimed_by_admin_id', $viewerId);
        } elseif ($filter === 'unclaimed') {
            $q->whereNull('j.assisted_claimed_by_admin_id');
        }

        return $q;
    }

    /**
     * Una tanda de la página, después de `$after` por `(expires_at, id)`. I-2, I-3 y N-2 en SQL, como la
     * reclamación del despachador: lo SIN reclamar vencido, frenado o de una parte que no está en marcha no se
     * lee (no se ofrece y no ocupa la página). Lo reclamado por quien mira, siempre (se le retiene el contacto si
     * toca); lo reclamado por OTRA persona, solo en plazo (m-8: vencido, sigue en la vista de su reclamante y no
     * esconde trabajo ofrecible en `all`).
     *
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @param  array<string, array{0: int, 1: string}>  $enMarcha  {@see runningParts()}
     * @return list<object>
     */
    private function queueRows(int $viewerId, string $filter, DateTimeImmutable $ahora, array $abiertos, array $enMarcha, ?object $after, int $size): array
    {
        $q = $this->queueQuery($viewerId, $filter)->where(function (Builder $w) use ($viewerId, $ahora, $abiertos, $enMarcha): void {
            $w->where('j.assisted_claimed_by_admin_id', $viewerId)
                ->orWhere(static fn (Builder $ajeno) => $ajeno->whereNotNull('j.assisted_claimed_by_admin_id')
                    ->where('j.assisted_claimed_by_admin_id', '<>', $viewerId)->where('j.expires_at', '>', OutreachStore::sql($ahora)));
            if (! $abiertos['global']) {
                $w->orWhere(function (Builder $libre) use ($ahora, $abiertos, $enMarcha): void {
                    $libre->whereNull('j.assisted_claimed_by_admin_id')->where('j.expires_at', '>', OutreachStore::sql($ahora));
                    if ($abiertos['channels'] !== []) {
                        $libre->whereNotIn('j.channel', $abiertos['channels']);
                    }
                    if ($abiertos['strategies'] !== []) {
                        $libre->whereNotIn('s.uuid', $abiertos['strategies']);
                    }
                    $libre->where(static fn (Builder $p) => self::inRunningPart($p, $enMarcha));
                });
            }
        });
        if ($after !== null) {
            $q->where(fn (Builder $w) => $w->where('j.expires_at', '>', $after->expires_at)
                ->orWhere(fn (Builder $m) => $m->where('j.expires_at', '=', $after->expires_at)->where('j.id', '>', (int) $after->id)));
        }

        return $q->orderBy('j.expires_at')->orderBy('j.id')->limit($size)->get(self::ROW_COLUMNS)->all();
    }

    /**
     * I-4 y N-2: los trabajos SIN reclamar y no vencidos de la vista que retiene un freno o una parte que no
     * está en marcha (un recuento exacto, sin leerlos ni decir de quién). En `mine`, o sin ningún freno abierto
     * ni ninguna parte parada en la vista, 0 sin consultar.
     *
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @param  array<string, array{0: int, 1: string}>  $enMarcha  {@see runningParts()}
     */
    private function heldCount(int $viewerId, string $filter, DateTimeImmutable $ahora, array $abiertos, array $enMarcha, bool $hayParadas): int
    {
        $frenos = $abiertos['global'] || $abiertos['channels'] !== [] || $abiertos['strategies'] !== [];
        if ($filter === 'mine' || (! $frenos && ! $hayParadas)) {
            return 0;
        }
        $q = $this->queueQuery($viewerId, $filter)->whereNull('j.assisted_claimed_by_admin_id')->where('j.expires_at', '>', OutreachStore::sql($ahora));
        if (! $abiertos['global']) {
            $q->where(function (Builder $w) use ($abiertos, $enMarcha): void {
                if ($abiertos['channels'] !== []) {
                    $w->orWhereIn('j.channel', $abiertos['channels']);
                }
                if ($abiertos['strategies'] !== []) {
                    $w->orWhereIn('s.uuid', $abiertos['strategies']);
                }
                $w->orWhereNot(static fn (Builder $p) => self::inRunningPart($p, $enMarcha));
            });
        }

        return (int) $q->count();
    }

    private function assistedJob(string $jobUuid): object
    {
        $j = DB::table(OutreachTables::JOBS.' as j')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->where('j.uuid', strtolower($jobUuid))->where('j.channel', 'assisted')->where('j.mode', OutreachConfig::MODE_LIVE)
            ->first(self::ROW_COLUMNS);
        if ($j === null) {
            throw OutreachException::notFound('job');
        }

        return $j;
    }

    /**
     * La fila de la bandeja. `person` (seudónimo → identidad) y `script` (el guion) solo los ve
     * quien RECLAMÓ el trabajo (modelo de amenazas §11; REV9 m-2, L9-63): en las vistas `all` y
     * `unclaimed`, y para cualquier otra persona, van en null. El CRM los trata como opcionales.
     * Con un motivo de retención (I-1, I-2, I-3), tampoco el reclamante: `contactWithheld` dice
     * cuál, y solo a él (a otra persona no se le cuenta, por ejemplo, que esa persona se quejó).
     *
     * @return array<string, mixed>
     */
    private function dto(object $j, ?int $viewerId = null, ?string $withheld = null): array
    {
        $reclamante = $viewerId !== null && $j->assisted_claimed_by_admin_id !== null && (int) $j->assisted_claimed_by_admin_id === $viewerId;
        $contacto = $reclamante && $withheld === null;
        $persona = SubjectKey::isValid((string) $j->subject_key) ? SubjectKey::parse((string) $j->subject_key) : null;
        $contenido = $this->bridge->contentOfVersion((int) $j->strategy_version_id);

        return [
            'uuid' => (string) $j->uuid,
            'enrollment' => (string) $j->enrollment_uuid,
            'strategy' => ['uuid' => (string) $j->strategy_uuid, 'key' => (string) $j->strategy_key, 'goal' => $contenido['goal'] ?? null, 'purpose' => (string) $j->purpose],
            'step' => (int) $j->step_index,
            'attempt' => (int) $j->attempt,
            'medium' => (string) $j->medium,
            'status' => (string) $j->status,
            'subjectRef' => (string) $j->subject_ref,
            'person' => ($contacto && $persona !== null) ? ['kind' => $persona['kind'] === SubjectKey::MEMBER ? 'member' : 'lead', 'id' => $persona['id']] : null,
            'script' => ($contacto && $j->template_version_id !== null) ? $this->bridge->assistedScript((int) $j->template_version_id) : null,
            'contactWithheld' => $reclamante ? $withheld : null,
            'reason' => $this->reasonOf((int) $j->id),
            'startedAt' => self::iso($j->status_changed_at),
            'dueBy' => self::iso($j->expires_at),
            'claimedBy' => $j->assisted_claimed_by_admin_id === null ? null : ['id' => (int) $j->assisted_claimed_by_admin_id, 'name' => (string) $j->assisted_claimed_by_name, 'at' => self::iso($j->assisted_claimed_at)],
            'outcomes' => self::STAFF_OUTCOMES,
        ];
    }

    /** Las frases de la decisión que planificó el trabajo (sin datos personales). @return list<string> */
    private function reasonOf(int $jobId): array
    {
        $plan = DB::table(OutreachTables::DECISIONS)->where('job_id', $jobId)->where('kind', 'plan')->orderBy('id')->first(['trace_json']);
        if ($plan === null) {
            return [];
        }
        $traza = json_decode((string) $plan->trace_json, true);
        $t = is_array($traza) && array_is_list($traza) ? ($traza[0] ?? []) : (array) $traza;
        $canal = is_string($t['channel']['channel'] ?? null) ? $t['channel']['channel'] : null;
        $salida = $canal !== null && is_array($t['policy'][$canal]['output'] ?? null) ? $t['policy'][$canal]['output'] : null;

        return $salida === null ? [] : DecisionTraceText::explain($salida, is_array($t['channel'] ?? null) ? $t['channel'] : null);
    }

    private static function iso(mixed $at): ?string
    {
        return $at === null ? null : RelativeTime::format(OutreachStore::instant($at));
    }

    /** El estado de la inscripción tras un resultado, para la respuesta. */
    public static function enrollmentStatusAfter(array $r): ?string
    {
        return $r['next']['status'] ?? null;
    }

    public static function now(): DateTimeImmutable
    {
        return RelativeTime::utc(now());
    }

    /** Las transiciones que el personal verá como posibles para un resultado (la tabla, no la estrategia). @return list<string> */
    public static function transitionsFor(string $outcome): array
    {
        return OutcomeTable::TABLE[$outcome]['allowed'] ?? [];
    }
}
