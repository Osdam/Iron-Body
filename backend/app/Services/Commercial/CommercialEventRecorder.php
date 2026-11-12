<?php

namespace App\Services\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * La puerta por la que entran los hechos al motor comercial.
 *
 * Un «hecho» aquí es algo que ya ocurrió y es indiscutible: un pago se aprobó,
 * alguien vino por primera vez, una factura fue rechazada. No es una opinión ni
 * una predicción. El motor decide a partir de estos hechos; si un hecho se
 * registra dos veces, el motor decide dos veces, y la persona recibe dos
 * mensajes. De ahí que la idempotencia sea el requisito central de esta clase y
 * no un detalle de implementación.
 *
 * Tres propiedades que hay que preservar al tocar este archivo:
 *
 *  1. **Nunca lanza, y nunca deja rota la transacción de quien llama.** Se le
 *     llama desde observers que corren DENTRO de la transacción de quien
 *     escribe (un pago, una membresía, una asistencia). Que el motor comercial
 *     se pierda un hecho es un problema menor; que un pago se revierta, o se
 *     pierda, porque el motor falló es inaceptable.
 *
 *     Capturar la excepción NO basta. En PostgreSQL una sentencia que falla
 *     deja la transacción entera abortada (25P02) aunque PHP se trague la
 *     excepción: la siguiente sentencia de quien llama falla, y si no había
 *     ninguna, `COMMIT` no lanza —el servidor responde ROLLBACK— y el cambio
 *     de estado del pago se pierde en silencio (I-1, comprobado en PG 14).
 *     Por eso, con una transacción abierta, todo lo que esta clase manda a la
 *     base (la lectura previa y el INSERT) va dentro de un punto de guardado
 *     propio, y cualquier fallo vuelve a él antes de llegar a la captura. Ver
 *     {@see self::isolated()}.
 *
 *  2. **Idempotente por el hecho, no por el momento.** La clave se construye
 *     con lo que pasó («pago 4821 aprobado»), no con cuándo se observó. El
 *     webhook de Wompi, la reconciliación de cada cinco minutos y una consulta
 *     manual pueden ver la misma aprobación; solo el primero registra.
 *
 *  3. **No decide nada.** Registra y encola. La decisión vive en
 *     {@see NextBestActionEngine}, detrás de su propio flag.
 */
class CommercialEventRecorder
{
    /** Distinto de los `trans<N>` de Laravel para no pisarse con sus niveles. */
    private const SAVEPOINT = 'commercial_event_record';

    /**
     * Registra un hecho y, si el motor está encendido, encola su evaluación.
     *
     * @param  string  $event  una de CommercialVocabulary::EVENTS
     * @param  array{lead?:?MarketingLead,member?:?Member,lead_id?:?int,member_id?:?int,opportunity_id?:?int}  $subject
     * @param  string|null  $dedupeKey  identidad del HECHO. Sin ella no hay
     *                                  protección contra el doble registro.
     * @return CommercialEvent|null null si no se registró (duplicado, evento
     *                              desconocido, sin sujeto, o error contenido)
     */
    public function record(
        string $event,
        array $subject,
        array $payload = [],
        ?string $dedupeKey = null,
        ?Carbon $occurredAt = null,
    ): ?CommercialEvent {
        try {
            return $this->doRecord($event, $subject, $payload, $dedupeKey, $occurredAt);
        } catch (\Throwable $e) {
            // Contención deliberada: ver la propiedad 1 del docblock de clase.
            ChannelLog::error('commercial.event.record_failed', [
                'event' => $event,
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function doRecord(
        string $event,
        array $subject,
        array $payload,
        ?string $dedupeKey,
        ?Carbon $occurredAt,
    ): ?CommercialEvent {
        if (! in_array($event, CommercialVocabulary::EVENTS, true)) {
            // Un evento fuera del vocabulario es un error de programación, no un
            // dato del cliente. Se registra alto y se descarta.
            ChannelLog::warning('commercial.event.unknown', ['event' => $event]);

            return null;
        }

        $leadId = $subject['lead_id'] ?? ($subject['lead'] ?? null)?->id;
        $memberId = $subject['member_id'] ?? ($subject['member'] ?? null)?->id;

        // Un hecho sin sujeto no es accionable: no hay a quién escribirle.
        if ($leadId === null && $memberId === null) {
            return null;
        }

        $key = $dedupeKey !== null
            ? $this->normalizeKey($event, $dedupeKey)
            // Sin clave explícita cada llamada es un hecho nuevo. Se permite
            // porque hay hechos genuinamente irrepetibles (una queja dicha en
            // una conversación), pero quien llama debería dar una clave siempre
            // que el hecho tenga identidad propia.
            : $event.':'.Str::uuid()->toString();

        try {
            $record = $this->isolated(function () use ($key, $leadId, $memberId, $subject, $event, $payload, $occurredAt): ?CommercialEvent {
                // Camino rápido: el hecho ya estaba registrado. Es lo NORMAL —la
                // reconciliación de Wompi revisa los pagos en vuelo cada cinco
                // minutos— así que no es un error ni merece ruido en el log.
                if (CommercialEvent::query()->where('dedupe_key', $key)->exists()) {
                    return null;
                }

                return CommercialEvent::create([
                    'marketing_lead_id' => $leadId,
                    'member_id' => $memberId,
                    'commercial_opportunity_id' => $subject['opportunity_id'] ?? null,
                    'event' => $event,
                    'dedupe_key' => $key,
                    'payload' => $this->sanitize($payload),
                    'occurred_at' => $occurredAt ?? now(),
                    'correlation_id' => $this->correlationId(),
                ]);
            });
        } catch (QueryException $e) {
            // Dos procesos vieron el mismo hecho a la vez y ambos pasaron la
            // comprobación anterior. El índice único es el árbitro; el que
            // pierde se calla. Solo se traga la violación de unicidad:
            // cualquier otro fallo de BD sí es un problema real. Cuando llega
            // aquí, `isolated()` ya ha devuelto la transacción de quien llama a
            // su punto de guardado.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return null;
        }

        if ($record === null) {
            return null;
        }

        ChannelLog::info('commercial.event.recorded', [
            'event' => $event,
            'commercial_event_id' => $record->id,
            'lead_id' => $leadId,
            'member_id' => $memberId,
        ]);

        $this->queueEvaluation($record);

        return $record;
    }

    /**
     * Ejecuta el trabajo de la base de forma que un fallo NO pueda dejar
     * abortada la transacción de quien llama.
     *
     * Sin transacción abierta en la conexión del modelo, el trabajo corre tal
     * cual, como siempre: cada sentencia es su propia transacción y un fallo no
     * arrastra a nadie (un SAVEPOINT ahí, además, es un error en PostgreSQL).
     *
     * Con transacción abierta, el trabajo va dentro de un punto de guardado
     * PROPIO, con SQL directo y no con `DB::transaction()` anidado:
     *
     *  - Laravel 12, ante un interbloqueo (40P01, «deadlock detected») o un
     *    fallo de serialización (40001) en un nivel anidado, NO hace
     *    `ROLLBACK TO SAVEPOINT`: baja su contador y lanza `DeadlockException`
     *    (`ManagesTransactions::handleTransactionException`, pensado para MySQL,
     *    que revierte la transacción entera). En PostgreSQL la transacción de
     *    fuera quedaría abortada: justo el fallo que esto evita.
     *  - Si la vuelta al punto de guardado falla porque se perdió la conexión,
     *    `rollBack()` de Laravel deja su contador a 0, y las sentencias que
     *    vinieran después de quien llama se reconectarían y correrían sueltas,
     *    fuera de su transacción. Con SQL directo el contador no se toca y
     *    quien llama falla en voz alta en su siguiente sentencia o en su COMMIT.
     *
     * El punto de guardado se LIBERA al terminar, no se confirma: el hecho sigue
     * siendo parte de la transacción de quien llama y se revierte con ella. Por
     * eso la evaluación se encola fuera de aquí, después, y sigue colgada del
     * COMMIT de quien llama (`afterCommit`).
     *
     * La conexión es, por defecto, la de `CommercialEvent`, no la de por
     * defecto de la aplicación: el punto de guardado tiene que estar donde
     * corren las sentencias que protege. Quien proteja una consulta de otro
     * modelo pasa la conexión de esa consulta.
     *
     * Es público para los observadores que, como este registrador, consultan
     * algo dentro de la transacción de otro y se tragan el fallo
     * (`ElectronicInvoiceCommercialObserver::subjectFor()`).
     * No captura nada: devuelve lo que devuelva el trabajo o relanza lo que
     * lance, ya con la transacción de quien llama devuelta a su estado.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public function isolated(\Closure $work, ?Connection $db = null): mixed
    {
        $db ??= (new CommercialEvent)->getConnection();

        if ($db->transactionLevel() === 0 && ! $db->getPdo()->inTransaction()) {
            return $work();
        }

        $pdo = $db->getPdo();
        $pdo->exec('SAVEPOINT '.self::SAVEPOINT);

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT '.self::SAVEPOINT);

            throw $e;
        }

        $pdo->exec('RELEASE SAVEPOINT '.self::SAVEPOINT);

        return $result;
    }

    /**
     * Encola la evaluación DESPUÉS del commit.
     *
     * Sin `afterCommit` el job puede empezar a correr mientras la transacción
     * del pago sigue abierta, leer un estado que aún no existe y decidir sobre
     * un mundo que todavía no es real. Peor: si esa transacción termina
     * revirtiéndose, el motor habría actuado sobre un pago que nunca ocurrió.
     */
    private function queueEvaluation(CommercialEvent $record): void
    {
        if (! (bool) config('commercial.enabled')) {
            return; // el hecho queda registrado; nadie lo evalúa todavía
        }

        try {
            EvaluateCommercialSubject::dispatch($record->id)->afterCommit();
        } catch (\Throwable $e) {
            // El evento ya está en la tabla con evaluated_at null, así que la
            // corrida programada lo recogerá igual. Perder el despacho retrasa,
            // no pierde.
            ChannelLog::warning('commercial.event.dispatch_failed', [
                'commercial_event_id' => $record->id,
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * ¿Es esta excepción el índice único haciendo su trabajo?
     *
     * PostgreSQL usa SQLSTATE 23505; SQLite —el motor de las pruebas— responde
     * 23000. Se comprueban los dos para que el comportamiento probado sea el
     * mismo que el de producción.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        return in_array($sqlState, ['23505', '23000'], true);
    }

    private function normalizeKey(string $event, string $dedupeKey): string
    {
        // El evento forma parte de la clave: «pago 4821» aprobado y «pago 4821»
        // facturado son hechos distintos sobre el mismo objeto.
        return Str::limit($event.':'.$dedupeKey, 250, '');
    }

    private function correlationId(): ?string
    {
        $existing = Context::get('correlation_id');

        return is_string($existing) && $existing !== ''
            ? $existing
            : Str::uuid()->toString();
    }

    /**
     * El payload es contexto para explicar una decisión, no un almacén. Fuera
     * de aquí no debe entrar nada sensible: ni tokens, ni documentos de
     * identidad, ni datos de tarjeta.
     */
    private function sanitize(array $payload): array
    {
        $forbidden = ['token', 'secret', 'password', 'signature', 'card', 'cvc', 'authorization'];

        $clean = [];
        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($forbidden as $needle) {
                if (str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
