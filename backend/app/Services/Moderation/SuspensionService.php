<?php

namespace App\Services\Moderation;

use App\Models\Member;
use App\Models\MemberSuspension;
use App\Models\MemberUgcConsent;
use App\Support\Moderation\ModerationScope;
use Illuminate\Support\Collection;

/**
 * Fuente de verdad del ESTADO DE MODERACIÓN de un miembro.
 *
 * El cliente Flutter nunca decide si está sancionado: pregunta aquí (vía
 * `/api/app/moderation/status`) y, además, cada acción sensible (publicar,
 * reaccionar, reportar) se vuelve a validar en el servidor antes de ejecutarse.
 * La UI es una cortesía; la barrera es esta clase.
 *
 * Aislamiento: ninguna consulta de esta clase toca membresías, pagos,
 * facturación ni acceso físico. Una sanción social no cancela nada del gimnasio.
 */
class SuspensionService
{
    /**
     * Sanciones EFECTIVAS ahora mismo (activas, ya iniciadas, no caducadas).
     *
     * @return Collection<int, MemberSuspension>
     */
    public function effectiveFor(int $memberId): Collection
    {
        return MemberSuspension::query()
            ->where('member_id', $memberId)
            ->effective()
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * ¿El miembro tiene retirada esta capacidad?
     *
     * Se consulta por CAPACIDAD (`story_posting`), no por sanción: una
     * suspensión de `social_features` también impide publicar, y la jerarquía
     * la resuelve {@see ModerationScope::blockedBy()} en un solo sitio.
     */
    public function isRestricted(int $memberId, string $capability): bool
    {
        $blocking = ModerationScope::blockedBy($capability);
        if ($blocking === []) {
            return false;
        }

        return MemberSuspension::query()
            ->where('member_id', $memberId)
            ->whereIn('scope', $blocking)
            ->effective()
            ->exists();
    }

    /** La sanción efectiva que retira una capacidad concreta (la más amplia). */
    public function restrictionFor(int $memberId, string $capability): ?MemberSuspension
    {
        $blocking = ModerationScope::blockedBy($capability);
        if ($blocking === []) {
            return null;
        }

        return MemberSuspension::query()
            ->where('member_id', $memberId)
            ->whereIn('scope', $blocking)
            ->effective()
            // Una permanente pesa más que una temporal a efectos de mensaje.
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('ends_at')
            ->first();
    }

    public function canPostStories(int $memberId): bool
    {
        return ! $this->isRestricted($memberId, ModerationScope::STORY_POSTING);
    }

    public function canInteract(int $memberId): bool
    {
        return ! $this->isRestricted($memberId, ModerationScope::STORY_INTERACTION);
    }

    public function hasFullAppBlock(int $memberId): bool
    {
        return $this->isRestricted($memberId, ModerationScope::FULL_APP_ACCESS);
    }

    /**
     * Estado completo para la app. Lo consume la pantalla de moderación y el
     * arranque de sesión.
     *
     * Solo información PÚBLICA: motivo público, alcance, fechas y si puede
     * apelar. Nunca notas internas, reportante ni detalles del caso.
     *
     * @return array<string, mixed>
     */
    public function statusFor(Member $member): array
    {
        $suspensions = $this->effectiveFor((int) $member->id);

        $restrictions = [];
        foreach ($suspensions as $suspension) {
            foreach ($suspension->impliedScopes() as $scope) {
                $restrictions[$scope] = true;
            }
        }

        return [
            'can_post_stories' => ! isset($restrictions[ModerationScope::STORY_POSTING]),
            'can_interact' => ! isset($restrictions[ModerationScope::STORY_INTERACTION]),
            'can_use_social' => ! isset($restrictions[ModerationScope::SOCIAL_FEATURES]),
            'app_access_blocked' => isset($restrictions[ModerationScope::FULL_APP_ACCESS]),
            'restricted_scopes' => array_keys($restrictions),
            'suspensions' => $suspensions->map(fn (MemberSuspension $s) => [
                'id' => $s->public_id,
                'scope' => $s->scope,
                'scope_label' => $s->scopeLabel(),
                'explanation' => ModerationScope::memberExplanation($s->scope),
                'public_reason' => $s->public_reason,
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'is_permanent' => $s->isPermanent(),
                'action_id' => $s->action?->public_id,
                'can_appeal' => (bool) config('ugc.appeals_enabled', true)
                    && $s->action?->isAppealable() === true,
            ])->values()->all(),
            // Requisitos previos para publicar (no son sanciones).
            'guidelines' => [
                'version' => (string) config('ugc.guidelines_version'),
                'accepted' => MemberUgcConsent::hasAcceptedCurrent((int) $member->id),
                'required_to_post' => (bool) config('ugc.guidelines_required_to_post', true),
                'url' => config('ugc.guidelines_url'),
            ],
            'features' => [
                'reports_enabled' => (bool) config('ugc.reports_enabled', true),
                'blocking_enabled' => (bool) config('ugc.blocking_enabled', true),
                'appeals_enabled' => (bool) config('ugc.appeals_enabled', true),
            ],
        ];
    }

    /**
     * Verificación de edad para PUBLICAR contenido.
     *
     * Deliberadamente conservadora y desactivable:
     *  - No modifica la edad mínima de USO de la app (`Member::MIN_REGISTRATION_AGE`).
     *  - Si `members.birth_date` es null NO se inventa una edad ni se asume
     *    mayoría de edad: se aplica `ugc.posting_age_unknown_policy`, que por
     *    defecto permite (para no bloquear a usuarios legítimos por un hueco de
     *    datos históricos).
     *  - Toda la verificación está apagada por defecto
     *    (`ugc.posting_age_enforced=false`) hasta que el dato sea fiable.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function checkPostingAge(Member $member): array
    {
        if (! config('ugc.posting_age_enforced', false)) {
            return ['allowed' => true, 'reason' => null];
        }

        $birthDate = $member->birth_date;

        if (! $birthDate) {
            $policy = (string) config('ugc.posting_age_unknown_policy', 'allow');

            return $policy === 'block'
                ? ['allowed' => false, 'reason' => 'birth_date_missing']
                : ['allowed' => true, 'reason' => null];
        }

        $minAge = (int) config('ugc.posting_min_age', 13);
        $age = $birthDate->age;

        return $age >= $minAge
            ? ['allowed' => true, 'reason' => null]
            : ['allowed' => false, 'reason' => 'below_posting_min_age'];
    }
}
