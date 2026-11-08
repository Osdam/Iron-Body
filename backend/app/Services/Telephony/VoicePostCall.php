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
 *    `unknown_provider_call` y abre el freno (DF-E5).
 *  - Duplicado (la misma conversación y el mismo tipo): 200 y nada nuevo (DF-E2).
 */
final class VoicePostCall
{
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

        // 3. La guarda posterior: en memoria; se guardan códigos y números de turno.
        $violaciones = VoiceTranscriptGuard::inspect($v->guardTurns(), VoiceTransferDirectory::approvedHashes());
        $veredicto = VoiceEvents::record('guard_verdict', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, [
            'outcome' => $violaciones === [] ? 'clean' : 'violation',
            'codes' => ['violations' => $violaciones],
        ]);
        if ($violaciones !== []) {
            VoiceBrake::open('guard_violation', 'guard:'.($clave ?? $llamada->uuid), ['codes' => array_values(array_unique(array_column($violaciones, 'code')))], $id, $veredicto?->id);
        }
        Log::channel('voice')->info('voice.post_call.transcription', ['call_uuid' => $llamada->uuid, 'seal_verified' => $verificado, 'violations' => count($violaciones), 'completed' => $cambio]);

        return ['ok' => true, 'status' => $cambio ? VoiceCall::STATUS_COMPLETED : (string) $llamada->status, 'sealVerified' => $verificado, 'violations' => count($violaciones)];
    }

    /** @return array<string, mixed> una conversación que Laravel no inició: evento y freno (DF-E5) */
    private function unknown(VoiceWebhookVerdict $v): array
    {
        $clave = $v->conversationId ?? ($v->callSid === null ? null : 'sid:'.$v->callSid);
        $evento = VoiceEvents::record('unknown_provider_call', null, VoiceCallEvent::PROVIDER_ELEVENLABS, $clave, ['outcome' => (string) $v->kind]);
        if ($evento !== null || $clave === null) {
            VoiceBrake::open('unknown_provider_call', 'unknown:'.($clave ?? $evento?->uuid ?? 'sin-clave'), ['where' => 'post_call', 'kind' => $v->kind], null, $evento?->id);
        }
        Log::channel('voice')->warning('voice.post_call.unknown_call', ['kind' => $v->kind]);

        return ['ok' => true, 'unknown' => true];
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
