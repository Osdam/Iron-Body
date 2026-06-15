<?php

namespace App\Services\Trainer;

use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\Identity\IdentityLinkService;

/**
 * Punto ÚNICO de integración del registro de entrenador con el portal
 * profesional. Lo usan ambas vías de administración (API `Api\TrainerController`
 * y CRM Blade `Crm\TrainerController`) para no duplicar lógica: vincular
 * identidad (sin duplicar por documento), sincronizar roles y cortar el acceso
 * profesional al desactivar.
 */
class TrainerProfileService
{
    public function __construct(
        private readonly IdentityLinkService $identities,
        private readonly TrainerAuditService $audit,
        private readonly TrainerSessionService $sessions,
    ) {}

    /**
     * Vincula el entrenador a su identidad (creándola o reutilizando la del
     * miembro si comparten documento; idempotente) y sincroniza roles cuando se
     * proveen. `$roles` null = no tocar roles.
     *
     * @param  list<string>|null  $roles
     */
    public function linkIdentityAndRoles(Trainer $trainer, ?array $roles, string $source): void
    {
        $identity = $this->identities->ensureIdentity($trainer->document, $trainer->phone);
        $this->identities->attachTrainer($trainer, $identity, ownershipVerified: true);

        if ($roles !== null) {
            $trainer->syncRoles($roles);
        }

        $this->audit->record(
            TrainerAuditLog::EVENT_IDENTITY_LINKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            metadata: ['identity_id' => $identity->getKey(), 'source' => $source],
        );
    }

    /**
     * Si la edición dejó al entrenador inactivo (estaba activo), revoca sus
     * sesiones profesionales y audita. Conserva miembro/membresía/historial.
     * Devuelve cuántas sesiones revocó.
     */
    public function revokeOnDeactivation(Trainer $trainer, bool $wasActive, string $source): int
    {
        if (! $wasActive || $trainer->fresh()->isActive()) {
            return 0;
        }

        $revoked = $this->sessions->revokeAll($trainer, 'trainer_deactivated');

        $this->audit->record(
            TrainerAuditLog::EVENT_DEACTIVATED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            metadata: ['revoked_sessions' => $revoked, 'source' => $source],
        );

        return $revoked;
    }
}
