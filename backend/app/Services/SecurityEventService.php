<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSecurityEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe la bitácora de seguridad del miembro. Best-effort: si falla, registra
 * en log pero nunca rompe el flujo de autenticación.
 */
class SecurityEventService
{
    public function record(
        Member $member,
        string $type,
        array $context = [],
        array $metadata = [],
        ?string $description = null,
    ): ?MemberSecurityEvent {
        try {
            return MemberSecurityEvent::create([
                'member_id'   => $member->id,
                'type'        => $type,
                'description' => $description,
                'device_id'   => $context['device_id'] ?? null,
                'device_name' => $context['device_name'] ?? null,
                'platform'    => $context['platform'] ?? null,
                'ip_address'  => $context['ip_address'] ?? null,
                'user_agent'  => isset($context['user_agent'])
                    ? mb_substr((string) $context['user_agent'], 0, 500)
                    : null,
                'metadata'    => $metadata ?: null,
            ]);
        } catch (Throwable $e) {
            Log::warning('SecurityEventService: no se pudo registrar el evento', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
