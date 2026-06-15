<?php

namespace App\Services\Trainer;

use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Punto único para registrar eventos de auditoría profesional. Sanea el
 * contexto: nunca persiste OTP, tokens, documentos o teléfonos completos. El
 * IP/User-Agent se toman del request actual cuando está disponible.
 */
class TrainerAuditService
{
    /** Claves que jamás deben quedar en la bitácora. */
    private const FORBIDDEN_KEYS = [
        'code', 'otp', 'token', 'password', 'document', 'document_number',
        'phone', 'access_hash', 'secret', 'authorization',
    ];

    public function record(
        string $event,
        ?Trainer $trainer = null,
        string $actorType = TrainerAuditLog::ACTOR_SYSTEM,
        ?int $actorId = null,
        array $metadata = [],
        ?Request $request = null,
    ): TrainerAuditLog {
        $request ??= request();

        return TrainerAuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'trainer_id' => $trainer?->getKey(),
            'identity_id' => $trainer?->identity_id,
            'event' => $event,
            'metadata' => $this->sanitize($metadata) ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Elimina claves sensibles del contexto (defensa en profundidad: el llamador
     * no debería pasarlas, pero si lo hace no quedan registradas).
     */
    private function sanitize(array $metadata): array
    {
        return Arr::except($metadata, self::FORBIDDEN_KEYS);
    }
}
