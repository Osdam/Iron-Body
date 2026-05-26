<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Plan;
use Carbon\Carbon;

/**
 * Arma el payload de miembro que consume la app Flutter al iniciar sesión. El
 * `access_hash` que se devuelve es el bearer que la app usará en todas sus
 * peticiones; con 2FA pasa a ser el `session_token` del dispositivo (no el hash
 * permanente), de modo que revocar la sesión bloquea de verdad ese equipo.
 */
class MemberPayload
{
    /**
     * @param  string  $accessToken  Token que la app guardará como bearer.
     */
    public static function build(Member $member, string $accessToken): array
    {
        $member->loadMissing('user');
        $user = $member->user;

        return [
            'id'                => $member->id,
            'member_id'         => $member->id,
            'member_uuid'       => $member->member_uuid,
            'full_name'         => $member->full_name,
            'email'             => $member->email ?: $user?->email,
            'document_number'   => $member->document_number,
            'phone'             => $member->phone ?: $user?->phone,
            'goal'              => $member->goal,
            'plan_name'         => $user?->plan,
            'membership_expiry' => $user?->membershipEndDate,
            'access_hash'       => $accessToken,
            'status'            => $member->status,
            'features'          => self::featuresFor($user),
        ];
    }

    public static function featuresFor($user): array
    {
        if (! $user) {
            return array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true]);
        }

        $plan = $user->plan ? Plan::where('name', $user->plan)->first() : null;
        $expiresAt = $user->membershipEndDate
            ? Carbon::parse($user->membershipEndDate)->endOfDay()
            : null;
        $isExpired = $expiresAt && $expiresAt->isPast();

        return ($isExpired || ! $plan)
            ? array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true])
            : $plan->resolvedFeatures();
    }
}
