<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceLabSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El veredicto HUMANO sobre una sesión del laboratorio hablado (contrato H8,
 * D8-23, D8-19 y P-19). Es la ÚNICA vía que lo escribe, y solo desde
 * `POST voice-lab/sessions/{uuid}/verdict`: nada aprueba solo (ni el cierre
 * por plazo ni el informe del proveedor).
 *
 *  - Solo sobre sesiones TERMINADAS con un agente REAL de ElevenLabs (el
 *    simulador no admite veredicto): 409 `voice_lab_session_not_reviewable`.
 *  - CUATRO OJOS por identidad: nunca quien abrió la sesión (la base lo
 *    impone también: `usvl_four_eyes_chk` en PG, `usvl_verdict_rules` en
 *    SQLite) ni el autor de la versión (como el veredicto del H4). 403
 *    `four_eyes_required {reason}`.
 *  - Uno por sesión, y DEFINITIVO: con veredicto la base rechaza cualquier
 *    UPDATE (`usvl_closed_is_final`). El segundo es 409 `verdict_exists`.
 *  - Sobre LA MISMA HUELLA que vio la persona (`specHash`, la del agente de
 *    la sesión): si no, 409 `voice_lab_integrity_mismatch`. Es la huella que
 *    exigirá la publicación: el propósito no entra en la especificación.
 *  - `rejected` lleva nota (también el CHECK `usvl_verdict_chk`).
 *
 * {@see latestFor()} es la lectura de la puerta de publicación de S8-b: vive
 * aquí, en el único servicio que nombra el veredicto, y publicar no lo nombra.
 */
final class StudioVoiceLabVerdicts
{
    public function record(StudioAccess $access, StudioVoiceLabSession $session, string $decision, ?string $note, string $specHash): StudioVoiceLabSession
    {
        $access->require(StudioAccess::APPROVE);

        DB::transaction(function () use ($access, $session, $decision, $note, $specHash): void {
            $fila = StudioVoiceLabSession::query()->whereKey($session->id)->lockForUpdate()->first()
                ?? throw StudioVoiceLabErrors::sessionNotFound();
            $agente = StudioVoiceLabAgents::find((int) $fila->agent_id);

            $motivo = StudioVoiceLabSessions::notReviewableReason($fila, $agente);
            if ($motivo !== null) {
                throw StudioVoiceLabErrors::notReviewable($motivo, StudioVoiceLabSessions::NOT_REVIEWABLE[$motivo]);
            }
            if ((int) $fila->created_by_admin_id === $access->id()) {
                throw StudioVoiceLabErrors::fourEyesRequired('session_creator');
            }
            if ((int) StudioVersion::query()->whereKey($agente->studio_version_id)->value('created_by_admin_id') === $access->id()) {
                throw StudioVoiceLabErrors::fourEyesRequired('version_author');
            }
            if ($fila->verdict !== null) {
                throw StudioVoiceLabErrors::verdictExists();
            }
            if (! hash_equals((string) $agente->spec_hash, strtolower($specHash))) {
                throw StudioVoiceLabErrors::integrityMismatch();
            }

            $yo = $access->snapshot();
            $ahora = now();
            if (! StudioVoiceLabSession::advance((int) $fila->id, [StudioVoiceLabSession::STATUS_ENDED], [
                'verdict' => $decision,
                'verdict_note' => $note,
                'verdict_by_admin_id' => $yo['id'],
                'verdict_by_name' => $yo['name'],
                'verdict_by_role' => $yo['role'],
                'verdict_at' => $ahora,
                'updated_at' => $ahora,
            ])) {
                throw StudioVoiceLabErrors::verdictExists();
            }
        });

        return $session->fresh() ?? $session;
    }

    /**
     * SOLO LEE (la puerta del laboratorio hablado al publicar, P-19): el
     * veredicto MÁS RECIENTE sobre una sesión de un agente de laboratorio REAL
     * con esta huella, quién lo emitió y quién abrió la sesión (para que la
     * publicación aplique sus propios cuatro ojos). Sin ninguno, `latest` null.
     *
     * @return array{latest:?string,sessionUuid:?string,verdictAt:?string,reviewerId:?int,sessionCreatorId:?int}
     */
    public static function latestFor(string $specHash): array
    {
        $ultimo = DB::table('ultron_studio_voice_lab_sessions as s')
            ->join(StudioVoiceLabAgents::AGENTS.' as a', 'a.id', '=', 's.agent_id')
            ->where('a.spec_hash', strtolower($specHash))
            ->where('a.purpose', StudioVoiceLabAgents::PURPOSE_LAB)
            ->where('a.provider', StudioVoiceLabAgents::PROVIDER_ELEVENLABS)
            ->whereNotNull('s.verdict')
            ->orderByDesc('s.verdict_at')
            ->orderByDesc('s.id')
            ->first(['s.uuid', 's.verdict', 's.verdict_at', 's.verdict_by_admin_id', 's.created_by_admin_id']);

        if ($ultimo === null) {
            return ['latest' => null, 'sessionUuid' => null, 'verdictAt' => null, 'reviewerId' => null, 'sessionCreatorId' => null];
        }

        return [
            'latest' => (string) $ultimo->verdict,
            'sessionUuid' => (string) $ultimo->uuid,
            // La hora de pared UTC que escribe Eloquent.
            'verdictAt' => Carbon::parse((string) $ultimo->verdict_at, 'UTC')->toIso8601String(),
            'reviewerId' => (int) $ultimo->verdict_by_admin_id,
            'sessionCreatorId' => (int) $ultimo->created_by_admin_id,
        ];
    }
}
