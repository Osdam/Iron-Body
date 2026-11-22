<?php

namespace App\Services\Outreach\Run\Classifier;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\BudgetReservations;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI9 · EL CLASIFICADOR DE RESPUESTAS (contrato H9, D9-10 «regla asimétrica
 * de la IA», §3.3 «Laravel decide; los modelos solo clasifican», §4
 * encargados, §5 capa `intent`, R9-9, L9-18). Lo llama el normalizador de
 * WhatsApp ({@see WhatsappTemplateOutcomes}) SOLO
 * después de haber registrado una respuesta como hoy (`replied`, que deriva a
 * una persona o sale) y SOLO el proceso cuya inserción de ese `replied` ganó
 * la clave única: así cada respuesta se clasifica, como mucho, UNA vez, y un
 * reintento o una segunda pasada no vuelve a llamar ni a cobrar.
 *
 * QUÉ PUEDE HACER, POR CONSTRUCCIÓN: la inscripción ya está cerrada por
 * `replied`, así que un resultado del clasificador no planifica nada (la
 * máquina de seguimientos lo ve `enrollment_final`); solo AÑADE sus efectos
 * de freno: `opted_out` cancela lo pendiente de la persona en todas las
 * estrategias y la suprime para su finalidad; `wrong_person` la suprime en
 * ese medio; `not_interested`, `human_requested` y `callback_requested` dejan
 * constancia. Las etiquetas son las de {@see ReplyClassifierPrompt::LABELS}
 * y los umbrales los de {@see OutcomeTable} (50 para `opted_out`, 90 para el
 * resto); por debajo, se descarta.
 *
 * FALLA CERRADO, siempre como hoy: apagado, sin transporte, sin modelo o sin
 * precios, sin cupo o sin techo, presupuesto agotado, sin texto, tiempo
 * agotado, error del proveedor, negativa, salida que no cumple el esquema o
 * confianza baja → no se registra nada más que el `replied` de hoy. Nunca
 * lanza: cualquier excepción queda aquí (con su clase, nunca con el texto).
 *
 * PRESUPUESTO: antes de llamar se reserva, en el contador del día de Bogotá
 * `classifier:reply` (modo `live`), una llamada y el coste MÁXIMO de esa
 * llamada (cada byte de entrada como un token y el tope de salida); después
 * se libera lo que no se gastó según el `usage` del proveedor (o todo, si la
 * petición no llegó a salir). Un fallo que pudo cobrarse conserva la reserva.
 *
 * TRAZA: el resultado lleva `source = classifier`, su confianza y la clave
 * `job:<uuid>:ai9:<versión>:<huella del prompt>:<modelo>`; el coste del día,
 * en el contador. Los registros llevan la clase de lo ocurrido, la versión,
 * la huella, el modelo, el coste y la latencia; la etiqueta y la confianza,
 * SOLO cuando el resultado se registra (lo mismo que la fila del libro): al
 * descartar, el motivo y nada más (M5 de security). Nunca el texto.
 */
final class ReplyClassifier
{
    public const STATE_SKIPPED = 'skipped';

    public const STATE_DISCARDED = 'discarded';

    public const STATE_RECORDED = 'recorded';

    public const STATE_ERROR = 'error';

    /** Lo que se cuenta como tokens de más por el formato de la petición (mensajes, esquema, rol). */
    public const INPUT_OVERHEAD_TOKENS = 64;

    public function __construct(
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly OutreachStore $store = new OutreachStore,
        private readonly WhatsappSignals $signals = new WhatsappSignals,
        private readonly BudgetReservations $budgets = new BudgetReservations,
        private readonly ?ReplyClassifierTransport $transport = null,
        private readonly ReplyClassifierHealth $health = new ReplyClassifierHealth,
    ) {}

    /**
     * Clasifica la respuesta de un trabajo de WhatsApp YA registrada como
     * `replied`. Nunca lanza.
     *
     * @param  list<int>  $leadIds  los prospectos de la persona (para leer SOLO su entrante)
     * @return array{state: string, reason: string, outcome: string|null, calls: int, costMicros: int}
     */
    public function classifyReply(object $job, int $enrollmentId, int $replyMessageId, array $leadIds, DateTimeImmutable $replyAt, DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        try {
            return $this->run($job, $enrollmentId, $replyMessageId, $leadIds, $replyAt, $ahora);
        } catch (Throwable $e) {
            Log::warning('outreach.classifier.failed', ['job' => (string) $job->uuid, 'error_class' => $e::class]);

            return self::result(self::STATE_ERROR, 'exception');
        }
    }

    /** @param  list<int>  $leadIds @return array{state: string, reason: string, outcome: string|null, calls: int, costMicros: int} */
    private function run(object $job, int $enrollmentId, int $replyMessageId, array $leadIds, DateTimeImmutable $replyAt, DateTimeImmutable $now): array
    {
        if (! OutreachConfig::classifierEnabled() || ! OutreachConfig::isLive()) {
            return self::result(self::STATE_SKIPPED, 'disabled');
        }
        $transporte = $this->transport ?? (app()->bound(ReplyClassifierTransport::class) ? app(ReplyClassifierTransport::class) : null);
        if (! $transporte instanceof ReplyClassifierTransport) {
            return self::result(self::STATE_SKIPPED, 'transport_unbound');
        }
        $modelo = OutreachConfig::classifierModel();
        $precioEntrada = OutreachConfig::classifierPriceMicrosPerMtok('input');
        $precioSalida = OutreachConfig::classifierPriceMicrosPerMtok('output');
        if ($modelo === null || $precioEntrada <= 0 || $precioSalida <= 0 || ! $transporte->configured()) {
            return self::result(self::STATE_SKIPPED, 'not_configured');
        }
        $cupo = OutreachConfig::classifierDailyCap();
        $techo = OutreachConfig::classifierDailyBudgetMicros();
        if ($cupo <= 0 || $techo <= 0) {
            return self::result(self::STATE_SKIPPED, 'budget_zero');
        }
        $texto = ReplyTextMask::mask($this->signals->replyText($replyMessageId, $leadIds));
        if ($texto === '') {
            return self::result(self::STATE_SKIPPED, 'no_text');
        }

        $esquema = ReplyClassifierPrompt::schema();
        $salidaMax = OutreachConfig::classifierMaxOutputTokens();
        $entradaMax = strlen(ReplyClassifierPrompt::INSTRUCTIONS) + strlen(CanonicalJson::encode($esquema)) + strlen($texto) + self::INPUT_OVERHEAD_TOKENS;
        $reserva = self::costMicros($entradaMax, $salidaMax, $precioEntrada, $precioSalida);
        $dia = RelativeTime::bogotaDate($now);
        $parcela = BudgetReservations::parcel(BudgetReservations::SCOPE_CLASSIFIER, 1, $reserva, $cupo, $techo);
        // En su propia transacción (o punto de guardado): un fallo de la base aquí no deja abortada la de nadie (PostgreSQL).
        $sinPresupuesto = DB::transaction(fn (): ?string => $this->budgets->reserve($dia, OutreachConfig::MODE_LIVE, [$parcela], $now));
        if ($sinPresupuesto !== null) {
            return self::result(self::STATE_SKIPPED, 'budget_exhausted');
        }

        $r = $transporte->classify($modelo, ReplyClassifierPrompt::INSTRUCTIONS, $texto, $esquema, $salidaMax, OutreachConfig::classifierTimeoutSeconds());
        $error = is_string($r['error'] ?? null) ? $r['error'] : null;
        $salio = ! in_array($error, ReplyClassifierTransport::NOT_SENT, true);
        $uso = self::usage($r['usage'] ?? null);
        $coste = ! $salio ? 0 : ($uso === null ? $reserva : min($reserva, self::costMicros($uso['inputTokens'], $uso['outputTokens'], $precioEntrada, $precioSalida)));
        $this->settle($dia, $reserva, $coste, $salio, $now);
        $llamadas = $salio ? 1 : 0;
        $traza = ['job' => (string) $job->uuid, 'version' => ReplyClassifierPrompt::VERSION, 'prompt_ref' => ReplyClassifierPrompt::ref(), 'model' => $modelo,
            'cost_micros' => $coste, 'latency_ms' => is_int($r['latencyMs'] ?? null) ? $r['latencyMs'] : null];

        // C4: cada llamada sin salida usable suma a la racha de fallos del proveedor; una salida que se lee la vuelve a 0.
        if ($error !== null || ! array_key_exists('output', $r)) {
            $conocido = in_array($error, ReplyClassifierTransport::ERRORS, true);
            $motivo = $conocido ? 'provider_'.$error : 'provider_unknown';
            $this->health->failed($conocido ? (string) $error : ReplyClassifierHealth::UNKNOWN, $now);
            Log::info('outreach.classifier.discarded', $traza + ['reason' => $motivo]);

            return self::result(self::STATE_DISCARDED, $motivo, null, $llamadas, $coste);
        }
        $leido = self::read($r['output']);
        if ($leido === null) {
            $this->health->failed('invalid_output', $now);
            Log::info('outreach.classifier.discarded', $traza + ['reason' => 'invalid_output']);

            return self::result(self::STATE_DISCARDED, 'invalid_output', null, $llamadas, $coste);
        }
        $this->health->answered();
        [$etiqueta, $confianza] = $leido;
        // M5 de security: al descartar, el registro lleva el motivo y NO la etiqueta ni la confianza junto al trabajo.
        if ($etiqueta === ReplyClassifierPrompt::NONE) {
            Log::info('outreach.classifier.discarded', $traza + ['reason' => 'no_brake']);

            return self::result(self::STATE_DISCARDED, 'no_brake', null, $llamadas, $coste);
        }
        if ($confianza < ($etiqueta === OutcomeTable::OPTED_OUT ? OutcomeTable::CLASSIFIER_LOW : OutcomeTable::CLASSIFIER_HIGH)) {
            Log::info('outreach.classifier.discarded', $traza + ['reason' => 'low_confidence']);

            return self::result(self::STATE_DISCARDED, 'low_confidence', null, $llamadas, $coste);
        }

        $inscripcion = $this->store->enrollment($enrollmentId);
        if ($inscripcion === null) {
            return self::result(self::STATE_DISCARDED, 'enrollment_missing', null, $llamadas, $coste);
        }
        $registro = $this->outcomes->record($inscripcion, $etiqueta, OutcomeTable::SOURCE_CLASSIFIER, self::sourceKey((string) $job->uuid, $modelo), $now,
            ['job' => $job, 'confidence' => $confianza, 'occurredAt' => $replyAt]);
        Log::info('outreach.classifier.recorded', $traza + ['label' => $etiqueta, 'confidence' => $confianza, 'result' => $registro['result'], 'transition' => $registro['transition']]);

        return match ($registro['result']) {
            OutcomeRecorder::RESULT_RECORDED => self::result(self::STATE_RECORDED, 'recorded', $etiqueta, $llamadas, $coste),
            OutcomeRecorder::RESULT_DUPLICATE => self::result(self::STATE_DISCARDED, 'duplicate', null, $llamadas, $coste),
            default => self::result(self::STATE_DISCARDED, 'not_recorded', null, $llamadas, $coste),
        };
    }

    /**
     * La salida del modelo, validada ENTERA (el texto de la persona es un dato
     * no confiable y el modelo pudo obedecerlo): un objeto con exactamente
     * `label` (una de {@see ReplyClassifierPrompt::allowedLabels()}) y
     * `confidence` (entero de 0 a 100). Cualquier otra cosa: null. PURA.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function read(mixed $output): ?array
    {
        if (! is_array($output) || array_is_list($output) && $output !== [] || count($output) !== 2) {
            return null;
        }
        $etiqueta = $output['label'] ?? null;
        $confianza = $output['confidence'] ?? null;
        if (! is_string($etiqueta) || ! in_array($etiqueta, ReplyClassifierPrompt::allowedLabels(), true)) {
            return null;
        }
        if (! is_int($confianza) || $confianza < 0 || $confianza > 100) {
            return null;
        }

        return [$etiqueta, $confianza];
    }

    /** La clave del resultado: el trabajo, la versión, la huella del prompt y el modelo (≤ 96, la forma de `oout_codes_chk`). */
    public static function sourceKey(string $jobUuid, string $model): string
    {
        return substr('job:'.strtolower($jobUuid).':ai9:'.ReplyClassifierPrompt::VERSION.':'.ReplyClassifierPrompt::ref().':'.$model, 0, 96);
    }

    /** Micros de dólar de unos tokens a unos precios por millón (en micros), redondeado hacia arriba. PURA. */
    public static function costMicros(int $inputTokens, int $outputTokens, int $inputPricePerMtok, int $outputPricePerMtok): int
    {
        return intdiv(max(0, $inputTokens) * $inputPricePerMtok + 999_999, 1_000_000) + intdiv(max(0, $outputTokens) * $outputPricePerMtok + 999_999, 1_000_000);
    }

    /** Lo reservado que no se gastó vuelve al contador; si la petición no salió, también la llamada. */
    private function settle(string $day, int $reserved, int $spent, bool $sent, DateTimeImmutable $now): void
    {
        $devolver = ['scope' => BudgetReservations::SCOPE_CLASSIFIER, 'units' => $sent ? 0 : 1, 'micros' => max(0, $reserved - $spent)];
        if ($devolver['units'] === 0 && $devolver['micros'] === 0) {
            return;
        }
        try {
            DB::transaction(fn () => $this->budgets->release($day, OutreachConfig::MODE_LIVE, [$devolver], $now));
        } catch (Throwable $e) {
            // Sin devolver, el contador cuenta de MÁS (el lado seguro): la llamada ya se hizo o no salió; el registro lo dice.
            Log::warning('outreach.classifier.release_failed', ['error_class' => $e::class]);
        }
    }

    /** @return array{inputTokens: int, outputTokens: int}|null */
    private static function usage(mixed $usage): ?array
    {
        if (! is_array($usage) || ! is_int($usage['inputTokens'] ?? null) || ! is_int($usage['outputTokens'] ?? null)) {
            return null;
        }

        return ['inputTokens' => max(0, $usage['inputTokens']), 'outputTokens' => max(0, $usage['outputTokens'])];
    }

    /** @return array{state: string, reason: string, outcome: string|null, calls: int, costMicros: int} */
    private static function result(string $state, string $reason, ?string $outcome = null, int $calls = 0, int $costMicros = 0): array
    {
        return ['state' => $state, 'reason' => $reason, 'outcome' => $outcome, 'calls' => $calls, 'costMicros' => $costMicros];
    }
}
