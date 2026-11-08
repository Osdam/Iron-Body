<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use App\Services\Voice\VoiceEventVerifier;

/**
 * El verificador del webhook post-llamada de ElevenLabs (contrato H8, D8-3,
 * D8-10 capa 4 y D8-16). Envuelve {@see ElevenLabsWebhookSignature} y
 * {@see ElevenLabsWebhookEnvelope} SIN CAMBIARLAS. Sin red, sin base, sin
 * reloj (la hora la pone quien recibe) y sin registro.
 *
 * Es el lector enumerado del secreto del webhook (D8-5), que lee en el momento
 * de usarlo y no guarda. Sin secreto, `not_configured`: ninguna firma vale.
 *
 * Orden:
 *  1. Sin secreto → `not_configured`.
 *  2. Un cuerpo de más de {@see MAX_BODY_BYTES} → `rejected` (`too_large`),
 *     sin mirarlo (la ruta lo corta antes; esto es la defensa de aquí).
 *  3. La firma, sobre el CUERPO CRUDO (1 800 s hacia atrás y 300 hacia
 *     delante) → `rejected` con su resultado si no es `ok`.
 *  4. El sobre: un tipo de los tres conocidos o `unknown` (un sobre firmado
 *     que no se entiende se acepta y se ignora: D8-16, nunca un 4xx en bucle).
 *  5. De cada tipo, solo lo que enumera el contrato; los ids sin forma se
 *     descartan. La estructura del cuerpo sigue la referencia oficial del
 *     03-11-2026; el `call_sid` de una transcripción ({@see callSidOf()}) y el
 *     formato de las llamadas a herramientas están [por confirmar] con la
 *     cuenta real.
 *
 * NUNCA CONSERVA la transcripción de la persona, el audio, los teléfonos (del
 * sobre de Twilio tampoco), el análisis, las variables dinámicas (salvo
 * `call_ref`, un uuid) ni `termination_reason`. Los turnos del AGENTE y los
 * destinos de transferencia que pidió salen solo en memoria, para la guarda.
 */
final class ElevenLabsEventVerifier implements VoiceEventVerifier
{
    /** D8-16: el tope de cuerpo del webhook post-llamada. */
    public const MAX_BODY_BYTES = 1048576;

    /** Un sobre tiene 5 o 6 niveles; los argumentos de una herramienta, 2 más. */
    private const JSON_MAX_DEPTH = 64;

    /** Ningún turno ni llamada a herramienta de más (una llamada de 10 min no llega a 400 turnos). */
    private const MAX_TURNS = 1000;

    private const MAX_TOOL_ARGUMENTS_BYTES = 8192;

    public function verifyPostCall(string $rawBody, ?string $signatureHeader, int $now): VoiceWebhookVerdict
    {
        $secreto = trim((string) config('voice.elevenlabs.webhook_secret'));
        if ($secreto === '') {
            return self::verdict(VoiceWebhookVerdict::OUTCOME_NOT_CONFIGURED, VoiceWebhookVerdict::REASON_NOT_CONFIGURED);
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return self::verdict(VoiceWebhookVerdict::OUTCOME_REJECTED, VoiceWebhookVerdict::REASON_TOO_LARGE);
        }

        $firma = ElevenLabsWebhookSignature::verify($rawBody, $signatureHeader, $secreto, $now);
        unset($secreto);
        if ($firma !== ElevenLabsWebhookSignature::OK) {
            return self::verdict(VoiceWebhookVerdict::OUTCOME_REJECTED, $firma);
        }

        return self::envelope($rawBody);
    }

    /**
     * El sobre de un cuerpo YA FIRMADO (puro): el evento neutro. Público para
     * los fixtures; la telefonía usa siempre {@see verifyPostCall()}.
     */
    public static function envelope(string $rawBody): VoiceWebhookVerdict
    {
        $tipo = ElevenLabsWebhookEnvelope::type($rawBody) ?? VoiceWebhookVerdict::KIND_UNKNOWN;
        $cuerpo = json_decode($rawBody, true, self::JSON_MAX_DEPTH);
        if (! is_array($cuerpo) || array_is_list($cuerpo)) {
            return self::accepted(VoiceWebhookVerdict::KIND_UNKNOWN, null, []);
        }
        $hora = self::nonNegativeInt($cuerpo['event_timestamp'] ?? null);
        $datos = is_array($cuerpo['data'] ?? null) ? $cuerpo['data'] : [];
        unset($cuerpo);

        return match ($tipo) {
            VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION => self::transcription($hora, $datos),
            VoiceWebhookVerdict::KIND_CALL_INITIATION_FAILURE => self::initiationFailure($hora, $datos),
            VoiceWebhookVerdict::KIND_POST_CALL_AUDIO => self::accepted(VoiceWebhookVerdict::KIND_POST_CALL_AUDIO, $hora, $datos),
            default => self::accepted(VoiceWebhookVerdict::KIND_UNKNOWN, $hora, []),
        };
    }

    /** @param  array<string, mixed>  $datos */
    private static function transcription(?int $hora, array $datos): VoiceWebhookVerdict
    {
        $metadatos = is_array($datos['metadata'] ?? null) ? $datos['metadata'] : [];
        $inicio = is_array($datos['conversation_initiation_client_data'] ?? null) ? $datos['conversation_initiation_client_data'] : [];
        $variables = is_array($inicio['dynamic_variables'] ?? null) ? $inicio['dynamic_variables'] : [];

        $turnos = [];
        $herramientas = [];
        $destinos = [];
        $guarda = [];
        $transcripcion = is_array($datos['transcript'] ?? null) && array_is_list($datos['transcript']) ? $datos['transcript'] : [];
        foreach (array_slice($transcripcion, 0, self::MAX_TURNS) as $indice => $turno) {
            if (! is_array($turno) || ($turno['role'] ?? null) !== 'agent') {
                continue;   // los turnos de la persona no se conservan, ni en memoria
            }
            $texto = is_string($turno['message'] ?? null) ? $turno['message'] : '';
            if ($texto !== '') {
                $turnos[] = $texto;
            }
            $delTurno = [];
            $destinosDelTurno = [];
            foreach (is_array($turno['tool_calls'] ?? null) ? $turno['tool_calls'] : [] as $llamada) {
                $nombre = is_array($llamada) ? ($llamada['tool_name'] ?? null) : null;
                if (! is_string($nombre) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $nombre) !== 1) {
                    continue;
                }
                $herramientas[] = $nombre;
                $delTurno[] = $nombre;
                if ($nombre === 'transfer_to_number') {
                    $destinos[] = $destinosDelTurno[] = self::transferTarget($llamada['params_as_json'] ?? null);
                }
            }
            $guarda[] = ['index' => $indice, 'text' => $texto, 'tools' => $delTurno, 'transfers' => $destinosDelTurno, 'planResults' => self::planResults($turno['tool_results'] ?? null)];
        }

        return new VoiceWebhookVerdict(
            outcome: VoiceWebhookVerdict::OUTCOME_ACCEPTED,
            reason: VoiceWebhookVerdict::REASON_OK,
            kind: VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION,
            eventTimestamp: $hora,
            agentId: VoiceIds::providerIdOrNull($datos['agent_id'] ?? null),
            conversationId: VoiceIds::providerIdOrNull($datos['conversation_id'] ?? null),
            versionId: VoiceIds::providerIdOrNull($datos['version_id'] ?? null),
            branchId: VoiceIds::providerIdOrNull($datos['branch_id'] ?? null),
            callSid: self::callSidOf($metadatos),
            callRef: VoiceIds::uuidOrNull($variables['call_ref'] ?? null) ?? VoiceIds::uuidOrNull($datos['user_id'] ?? null),
            durationSeconds: self::nonNegativeInt($metadatos['call_duration_secs'] ?? null),
            cost: self::nonNegativeInt($metadatos['cost'] ?? null),
            failureReason: null,
            hasAudio: is_bool($datos['has_audio'] ?? null) ? $datos['has_audio'] : null,
            toolCalls: $herramientas,
            agentTurns: $turnos,
            transferTargets: $destinos,
            guardTurns: $guarda,
        );
    }

    /** @param  array<string, mixed>  $datos */
    private static function initiationFailure(?int $hora, array $datos): VoiceWebhookVerdict
    {
        $motivo = $datos['failure_reason'] ?? null;
        $metadatos = is_array($datos['metadata'] ?? null) ? $datos['metadata'] : [];
        $sobre = is_array($metadatos['body'] ?? null) ? $metadatos['body'] : [];
        // Del sobre del operador solo el SID: lleva los números en claro.
        $sid = VoiceIds::callSidOrNull($sobre['CallSid'] ?? null) ?? VoiceIds::callSidOrNull($sobre['call_sid'] ?? null);

        return new VoiceWebhookVerdict(
            outcome: VoiceWebhookVerdict::OUTCOME_ACCEPTED,
            reason: VoiceWebhookVerdict::REASON_OK,
            kind: VoiceWebhookVerdict::KIND_CALL_INITIATION_FAILURE,
            eventTimestamp: $hora,
            agentId: VoiceIds::providerIdOrNull($datos['agent_id'] ?? null),
            conversationId: VoiceIds::providerIdOrNull($datos['conversation_id'] ?? null),
            versionId: null,
            branchId: null,
            callSid: $sid,
            // El `user_id` de la saliente es su `call_ref` (D8-12): casa la fila
            // si el fallo llega antes de que el job guarde los ids (DF-S2).
            callRef: VoiceIds::uuidOrNull($datos['user_id'] ?? null),
            durationSeconds: null,
            cost: null,
            failureReason: is_string($motivo) && in_array($motivo, VoiceWebhookVerdict::FAILURE_REASONS, true) ? $motivo : null,
            hasAudio: null,
            toolCalls: [],
        );
    }

    /**
     * Un evento aceptado con solo los ids comunes (el audio y los tipos que no
     * se entienden): ni `full_audio` ni nada más sale de aquí.
     *
     * @param  array<string, mixed>  $datos
     */
    private static function accepted(string $tipo, ?int $hora, array $datos): VoiceWebhookVerdict
    {
        return new VoiceWebhookVerdict(
            outcome: VoiceWebhookVerdict::OUTCOME_ACCEPTED,
            reason: VoiceWebhookVerdict::REASON_OK,
            kind: $tipo,
            eventTimestamp: $hora,
            agentId: VoiceIds::providerIdOrNull($datos['agent_id'] ?? null),
            conversationId: VoiceIds::providerIdOrNull($datos['conversation_id'] ?? null),
            versionId: null,
            branchId: null,
            callSid: null,
            callRef: null,
            durationSeconds: null,
            cost: null,
            failureReason: null,
            hasAudio: null,
            toolCalls: [],
        );
    }

    private static function verdict(string $resultado, string $motivo): VoiceWebhookVerdict
    {
        return new VoiceWebhookVerdict($resultado, $motivo, null, null, null, null, null, null, null, null, null, null, null, null, []);
    }

    /**
     * El SID de la llamada de una transcripción: `metadata.phone_call.call_sid`
     * [por confirmar: la página oficial no documenta `phone_call`] y, si no,
     * `metadata.call_sid`. Solo si tiene forma.
     *
     * @param  array<string, mixed>  $metadatos
     */
    private static function callSidOf(array $metadatos): ?string
    {
        $telefono = is_array($metadatos['phone_call'] ?? null) ? $metadatos['phone_call'] : [];

        return VoiceIds::callSidOrNull($telefono['call_sid'] ?? null) ?? VoiceIds::callSidOrNull($metadatos['call_sid'] ?? null);
    }

    /**
     * El número que pidió el agente a `transfer_to_number` (argumento
     * `transfer_number`), tal cual, para que la guarda lo compare con los
     * destinos aprobados; uno ilegible cuenta como el texto `?`, que nunca es
     * un destino aprobado.
     */
    private static function transferTarget(mixed $argumentos): string
    {
        if (is_string($argumentos) && strlen($argumentos) <= self::MAX_TOOL_ARGUMENTS_BYTES) {
            $argumentos = json_decode($argumentos, true, 8);
        }
        $numero = is_array($argumentos) ? ($argumentos['transfer_number'] ?? null) : null;

        return is_string($numero) && $numero !== '' && strlen($numero) <= 32 ? $numero : '?';
    }

    /**
     * Lo que devolvió `consultar_planes` en un turno (`result_value`, si no es
     * un error y no pasa del tope), para que la guarda sepa qué cifras dio la
     * herramienta en ESA llamada (P-3). Solo en memoria.
     *
     * @return list<string>
     */
    private static function planResults(mixed $resultados): array
    {
        $out = [];
        foreach (is_array($resultados) && array_is_list($resultados) ? $resultados : [] as $r) {
            if (is_array($r) && ($r['tool_name'] ?? null) === 'consultar_planes' && ($r['is_error'] ?? false) !== true
                && is_string($r['result_value'] ?? null) && strlen($r['result_value']) <= self::MAX_TOOL_ARGUMENTS_BYTES) {
                $out[] = $r['result_value'];
            }
        }

        return $out;
    }

    private static function nonNegativeInt(mixed $valor): ?int
    {
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && $valor >= 0 && $valor < PHP_INT_MAX) {
            $valor = (int) $valor;
        }

        return is_int($valor) && $valor >= 0 ? $valor : null;
    }
}
