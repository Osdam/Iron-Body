<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberDeviceToken;

/**
 * Gestión de tokens FCM por miembro. Un token es único (índice unique). Si el
 * token cambia de dueño/dispositivo, se actualiza. No expone tokens a otros.
 */
class MemberDeviceTokenService
{
    /** Registra o actualiza el token del miembro (idempotente por token). */
    public function register(Member $member, array $data): MemberDeviceToken
    {
        return MemberDeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'member_id' => $member->id,
                'platform' => $data['platform'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'notification_permission' => $data['notification_permission'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
                'last_seen_at' => now(),
            ],
        );
    }

    /** Desactiva un token (logout o token inválido). */
    public function deactivate(string $token): void
    {
        MemberDeviceToken::query()->where('token', $token)->update([
            'is_active' => false,
        ]);
    }

    /** Desactiva un token por id, validando dueño. */
    public function deactivateOwned(Member $member, int $id): bool
    {
        $affected = MemberDeviceToken::query()
            ->where('id', $id)
            ->where('member_id', $member->id)
            ->update(['is_active' => false]);
        return $affected > 0;
    }

    /** Tokens activos del miembro (para enviar push). */
    public function activeTokensFor(Member $member): array
    {
        return MemberDeviceToken::query()
            ->where('member_id', $member->id)
            ->where('is_active', true)
            ->pluck('token')
            ->all();
    }
}
