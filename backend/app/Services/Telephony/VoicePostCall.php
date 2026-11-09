<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que hace Laravel con un webhook post-llamada YA VERIFICADO (contrato H8,
 * D8-8 «Después», D8-10 capa 4, D8-13, D8-16; fixture
 * `elevenlabs-webhook-bodies.json` §postCall). Devuelve siempre 200 a
 * ElevenLabs (un sobre válido nunca recibe un 4xx en bucle); un fallo propio de
 * la base sube como excepción → 5xx, para que reintente.
 *
 *  - Tipo desconocido válido: se ignora (DF-E3).
 *  - `post_call_audio`: se descarta; queda `post_call_audio_discarded`.
 *  - `call_initiation_failure`: `busy`, `no_answer` o `failed` (DF-S2: también
 *    desde `requested` si llega antes de que el job guarde `dialing`; se casa
 *    por `call_ref`).
 *  - `post_call_transcription`: el SELLO OBSERVADO (agente, versión y rama, una
 *    vez) y `seal_verified`; si el agente no es el sellado, `seal_mismatch` y
 *    freno; `completed` con su duración (una llamada final no cambia, DF-E4);
 *    y la GUARDA POSTERIOR en memoria, de la que solo se guardan códigos y
 *    números de turno (una violación abre el freno).
 *  - Una conversación sin fila (iniciada fuera de Laravel) es
 *    `unknown_provider_call` y abre el freno (DF-E5). Salvo que sea del
 *    LABORATORIO HABLADO y él la reconozca como suya (LAB8, informe-lab §2.6;
 *    {@see labReport()}): sin oyente, o si dice que no es suya, freno.
 *  - Duplicado (la misma conversación y el mismo tipo): 200 y nada nuevo (DF-E2).
 */
final class VoicePostCall
{
    /**
     * El evento CON NOMBRE con el que el laboratorio hablado (LAB8) recibe el
     * informe de una conversación suya: la telefonía no nombra el Studio
     * (TelephonyIsolationTest). Su carga: el veredicto neutro y los códigos de
     * la guarda posterior (`[{turn, code}]`).
     */
    public const LAB_EVENT = 'voice.lab.post_call';

    /**
     * Lo que responde el laboratorio cuando la conversación es SUYA
     * (`StudioVoiceLabSessions::recordProviderReport`). `ignored` solo lo es
     * porque antes de entregar se exige un informe aceptado, de transcripción y
     * con conversación (el laboratorio solo lo dice entonces de un agente suyo).
     */
    public const LAB_CLAIMED = ['recorded', 'duplicate', 'ignored'];

    /**
     * Violaciones de la guarda posterior que se guardan en el veredicto, como
     * mucho (E10-4, SEC8-b M-2; como el laboratorio,
     * `StudioVoiceLabSessions::MAX_GUARD_CODES`): las primeras, en orden de
     * turno, y `truncated` con las que no caben. Sin tope, unos 76 turnos con
     * violación pasaban de los 4 096 bytes de `vce_codes_chk` (PG): el INSERT
     * caía, se deshacía todo (también el freno) y ElevenLabs, reintentando el
     * mismo cuerpo, acababa desactivando el webhook (D8-16).
     */
    public const MAX_GUARD_CODES = 50;

    /**
     * Y como mucho estos bytes de JSON, por si el motor trae códigos más largos
     * que los de hoy (≤ 31 caracteres: 50 entradas son ~3 KB): holgura bajo los
     * 4 096 de `vce_codes_chk` para `{"violations":[…],"truncated":N}`.
     */
    public const MAX_GUARD_CODES_BYTES = 3584;

    /** @return array<string, mixed> el cuerpo de la respuesta (200) */
    public function ingest(VoiceWebhookVerdict $v): array
    {
        return match ($v->kind) {
            VoiceWebhookVerdict::KIND_POST_CALL_AUDIO => $this->audio($v),
            VoiceWebhookVerdict::KIND_CALL_INITIATION_FAILURE => DB::transaction(fn (): array => $this->failure($v)),
            VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION => DB::transaction(fn (): array => $this->transcription($v)),
            default => ['ok' => true, 'ignored' => true],
        };
    }

    /** @return array<string, mixed> */
    private function audio(VoiceWebhookVerdict $v): array
    {
        $llamada = self::find($v);
        $clave = $v->conversationId;
        $nuevo = VoiceEvents::record('post_call_audio_discarded', $llamada?->id, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, ['outcome' => 'discarded']);

        return ['ok' => true, 'discarded' => true, 'duplicate' => $clave !== null && $nuevo === null];
    }

    /** @return array<string, mixed> */
    private function failure(VoiceWebhookVerdict $v): array
    {
        $llamada = self::find($v, lock: true);
        if ($llamada === null) {
            return $this->unknown($v);
        }
        $evento = VoiceEvents::record('call_initiation_failure', (int) $llamada->id, VoiceCallEvent::PROVIDER_ELEVENLABS, $v->conversationId ?? ($v->callSid === null ? null : 'sid:'.$v->callSid), [
            'outcome' => $v->failureReason ?? 'unknown', 'occurred_at' => $v->eventTimestamp === null ? null : now()->setTimestamp($v->eventTimestamp),
        ]);
        if ($evento === null) {
            return ['ok' => true, 'duplicate' => true];
        }
        $destino = match ($v->failureReason) {
            'busy' => VoiceCall::STATUS_BUSY,
            'no-answer' => VoiceCall::STATUS_NO_ANSWER,
            default => VoiceCall::STATUS_FAILED,
        };
        $ids = self::ids($llamada, $v);
        $cambio = self::bindable($llamada, $ids) && VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED, VoiceCall::STATUS_AMBIGUOUS, VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE], $destino,
            $ids + ['failure_code' => $v->failureReason === null ? 'unknown' : str_replace('-', '_', $v->failureReason), 'ended_at' => now()]);

        return ['ok' => true, 'status' => $cambio ? $destino : (string) $llamada->status];
    }

    /** @return array<string, mixed> */
    private function transcription(VoiceWebhookVerdict $v): array
    {
        $llamada = self::find($v, lock: true);
        if ($llamada === null) {
            return $this->unknown($v);
        }
        $id = (int) $llamada->id;
        $clave = $v->conversationId ?? ($v->callSid === null ? null : 'sid:'.$v->callSid);
        $evento = VoiceEvents::record('post_call_transcription', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, [
            'outcome' => 'received',
            'occurred_at' => $v->eventTimestamp === null ? null : now()->setTimestamp($v->eventTimestamp),
            // El coste crudo de ElevenLabs: unidades [por confirmar] (D8-15); no se convierte.
            'codes' => ['durationSeconds' => $v->durationSeconds, 'costRaw' => $v->cost, 'agentTurns' => count($v->guardTurns()), 'toolCalls' => count($v->toolCalls), 'hasAudio' => $v->hasAudio],
        ]);
        if ($evento === null) {
            return ['ok' => true, 'duplicate' => true];
        }

        // 1. El sello observado (una vez).
        $verificado = null;
        if ($v->agentId !== null && $llamada->observed_agent_id === null) {
            $verificado = $llamada->provider_agent_id !== null && hash_equals((string) $llamada->provider_agent_id, $v->agentId);
            VoiceCall::bind($id, [
                'observed_agent_id' => $v->agentId,
                'observed_version_id' => $v->versionId,
                'observed_branch_id' => $v->branchId,
                'seal_verified' => $verificado,
            ], ['observed_agent_id']);
            if ($verificado === false) {
                $roto = VoiceEvents::record('seal_mismatch', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, ['outcome' => 'agent_differs', 'codes' => ['where' => 'post_call', 'seq' => $llamada->voice_publication_seq, 'part' => $llamada->part]]);
                VoiceBrake::open('seal_mismatch', 'seal:'.($clave ?? $llamada->uuid), ['where' => 'post_call', 'seq' => $llamada->voice_publication_seq], $id, $roto?->id);
            }
        }

        // 2. El estado: `completed` con su duración; una llamada final no cambia (DF-E4).
        $duracion = $v->durationSeconds;
        $ids = self::ids($llamada, $v);
        $cambio = self::bindable($llamada, $ids) && VoiceCall::transition($id, [VoiceCall::STATUS_REQUESTED, VoiceCall::STATUS_AMBIGUOUS, VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE], VoiceCall::STATUS_COMPLETED,
            $ids + ['duration_seconds' => $duracion, 'ended_at' => now()]);
        if (! $cambio && $duracion !== null) {
            VoiceCall::bind($id, ['duration_seconds' => $duracion], ['duration_seconds']);
        }

        // 3. La guarda posterior: en memoria; se guardan códigos y números de turno, acotados (E10-4).
        $violaciones = VoiceTranscriptGuard::inspect($v->guardTurns(), VoiceTransferDirectory::approvedHashesFor($llamada));
        $veredicto = VoiceEvents::record('guard_verdict', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, [
            'outcome' => $violaciones === [] ? 'clean' : 'violation',
            'codes' => self::verdictCodes($violaciones),
        ]);
        if ($violaciones !== []) {
            // Los códigos DISTINTOS (un conjunto cerrado del motor y de la voz): su tamaño no crece con los turnos.
            VoiceBrake::open('guard_violation', 'guard:'.($clave ?? $llamada->uuid), ['codes' => array_slice(array_values(array_unique(array_column($violaciones, 'code'))), 0, self::MAX_GUARD_CODES)], $id, $veredicto?->id);
        }
        Log::channel('voice')->info('voice.post_call.transcription', ['call_uuid' => $llamada->uuid, 'seal_verified' => $verificado, 'violations' => count($violaciones), 'completed' => $cambio]);

        return ['ok' => true, 'status' => $cambio ? VoiceCall::STATUS_COMPLETED : (string) $llamada->status, 'sealVerified' => $verificado, 'violations' => count($violaciones)];
    }

    /** @return array<string, mixed> una conversación que Laravel no inició: evento y freno (DF-E5) */
    private function unknown(VoiceWebhookVerdict $v): array
    {
        $laboratorio = $this->labReport($v);
        if ($laboratorio !== null) {
            return ['ok' => true, 'lab' => $laboratorio];
        }
        $clave = $v->conversationId ?? ($v->callSid === null ? null : 'sid:'.$v->callSid);
        $evento = VoiceEvents::record('unknown_provider_call', null, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, ['outcome' => (string) $v->kind]);
        if ($evento !== null || $clave === null) {
            VoiceBrake::open('unknown_provider_call', 'unknown:'.($clave ?? $evento?->uuid ?? 'sin-clave'), ['where' => 'post_call', 'kind' => $v->kind], null, $evento?->id);
        }
        Log::channel('voice')->warning('voice.post_call.unknown_call', ['kind' => $v->kind]);

        return ['ok' => true, 'unknown' => true];
    }

    /**
     * Una conversación WEB (sin llamada de Twilio), con su id y de un agente
     * que no es el de ninguna parte publicada, puede ser del laboratorio
     * hablado: se le entrega por {@see LAB_EVENT}, con los códigos de la
     * guarda posterior (el laboratorio no transfiere: ningún destino vale).
     * Devuelve lo que respondió si la reconoce como suya; si no, null y sigue
     * el camino de siempre (falla cerrado). El laboratorio no escribe en
     * `voice_*` y la telefonía, de esto, solo registra el resultado.
     */
    private function labReport(VoiceWebhookVerdict $v): ?string
    {
        if ($v->kind !== VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION || ! $v->accepted() || $v->callSid !== null
            || $v->conversationId === null || $v->agentId === null) {
            return null;
        }
        foreach (VoicePublishedConfig::read() as $parte) {
            if ($parte->ok() && hash_equals((string) $parte->providerAgentId, $v->agentId)) {
                return null;   // una conversación del agente PUBLICADO fuera de Laravel es una anomalía, no una prueba
            }
        }
        $guarda = VoiceTranscriptGuard::inspect($v->guardTurns(), []);
        foreach ((array) event(self::LAB_EVENT, [$v, $guarda]) as $respuesta) {
            if (in_array($respuesta, self::LAB_CLAIMED, true)) {
                Log::channel('voice')->info('voice.post_call.lab', ['result' => $respuesta, 'violations' => count($guarda)]);

                return $respuesta;
            }
        }

        return null;
    }

    /**
     * Lo que se guarda del veredicto (E10-4): las primeras violaciones, como
     * mucho {@see MAX_GUARD_CODES} y {@see MAX_GUARD_CODES_BYTES} de JSON, y
     * `truncated` (cuántas no caben) solo si sobra alguna; sin violaciones, la
     * forma de siempre (`{violations: []}`). El recuento completo va en la
     * respuesta y en el registro; el freno no depende de esto.
     *
     * @param  list<array{turn:int, code:string}>  $violaciones
     * @return array{violations: list<array{turn:int, code:string}>, truncated?: int}
     */
    private static function verdictCodes(array $violaciones): array
    {
        $guardadas = [];
        $bytes = strlen('{"violations":[],"truncated":'.count($violaciones).'}');
        foreach ($violaciones as $v) {
            $tam = strlen((string) json_encode($v)) + 1;
            if (count($guardadas) >= self::MAX_GUARD_CODES || $bytes + $tam > self::MAX_GUARD_CODES_BYTES) {
                break;
            }
            $guardadas[] = $v;
            $bytes += $tam;
        }
        $fuera = count($violaciones) - count($guardadas);

        return ['violations' => $guardadas] + ($fuera > 0 ? ['truncated' => $fuera] : []);
    }

    /**
     * La fila de la llamada: por conversación, por la llamada de Twilio o por
     * `call_ref` (el `user_id` de la saliente).
     */
    private static function find(VoiceWebhookVerdict $v, bool $lock = false): ?VoiceCall
    {
        foreach ([['provider_conversation_id', $v->conversationId], ['provider_call_sid', $v->callSid], ['call_ref', $v->callRef]] as [$columna, $valor]) {
            if ($valor === null) {
                continue;
            }
            $q = VoiceCall::query()->where($columna, $valor)->whereNull('transfer_of_call_id');
            $fila = ($lock ? $q->lockForUpdate() : $q)->first();
            if ($fila !== null) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * ¿Puede la fila salir de `requested` o `ambiguous` con estos ids? Una
     * saliente que ya marcó lleva al menos un id del proveedor (DB8,
     * `vc_provider_binding_chk`); sin ninguno, la transición espera a la
     * conciliación en vez de romper la sentencia.
     *
     * @param  array<string, string>  $ids
     */
    private static function bindable(VoiceCall $llamada, array $ids): bool
    {
        if (! in_array($llamada->status, [VoiceCall::STATUS_REQUESTED, VoiceCall::STATUS_AMBIGUOUS], true)) {
            return true;
        }

        return $ids !== [] || $llamada->provider_conversation_id !== null || $llamada->provider_call_sid !== null;
    }

    /**
     * Los ids del proveedor que todavía falten en la fila (se escriben UNA vez
     * y en la misma sentencia que la transición: una fila `requested` o
     * `ambiguous` no los admite, DB8).
     *
     * @return array<string, string>
     */
    private static function ids(VoiceCall $llamada, VoiceWebhookVerdict $v): array
    {
        $out = [];
        if ($llamada->provider_conversation_id === null && $v->conversationId !== null
            && ! VoiceCall::query()->where('provider_conversation_id', $v->conversationId)->exists()) {
            $out['provider_conversation_id'] = $v->conversationId;
        }
        if ($llamada->provider_call_sid === null && $v->callSid !== null
            && ! VoiceCall::query()->where('provider_call_sid', $v->callSid)->exists()) {
            $out['provider_call_sid'] = $v->callSid;
        }

        return $out;
    }
}
