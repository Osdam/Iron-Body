<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingAgentAction;
use App\Models\MarketingConversation;

/**
 * Autorización de las Acciones CRM del agente (Fase 4C). Misma filosofía que
 * Inbox/Agenda: role + status, con allow-list de tipos ejecutables por rol y
 * ownership sobre la conversación.
 */
class MarketingAgentActionAuthorizationService
{
    public const CAP_VIEW = 'view';
    public const CAP_RECOMMEND = 'recommend';
    public const CAP_APPROVE = 'approve';
    public const CAP_REJECT = 'reject';
    public const CAP_EXECUTE = 'execute';
    public const CAP_CANCEL = 'cancel';

    public const ALL_CAPS = [
        self::CAP_VIEW, self::CAP_RECOMMEND, self::CAP_APPROVE,
        self::CAP_REJECT, self::CAP_EXECUTE, self::CAP_CANCEL,
    ];

    private const FULL_ROLES = ['super admin', 'administrador', 'admin'];
    private const COMMERCIAL_ROLES = ['asesor comercial', 'asesor', 'ventas', 'recepción', 'recepcion'];

    /** Tipos que un asesor (comercial) puede EJECUTAR directamente. */
    public const COMMERCIAL_EXECUTABLE_TYPES = [
        MarketingAgentAction::TYPE_CREATE_NOTE,
        MarketingAgentAction::TYPE_ADD_TAG,
        MarketingAgentAction::TYPE_DRAFT_REPLY,
        MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT,
        MarketingAgentAction::TYPE_CREATE_FOLLOW_UP,
        MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE,
        // create_appointment se permite SOLO si además tiene permiso de Agenda.
        MarketingAgentAction::TYPE_CREATE_APPOINTMENT,
    ];

    /** Tipos críticos: solo FULL puede ejecutarlos. */
    public const FULL_ONLY_TYPES = [
        MarketingAgentAction::TYPE_ASSIGN_CONVERSATION,
        MarketingAgentAction::TYPE_PAUSE_AI,
        MarketingAgentAction::TYPE_RELEASE_AI,
        MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW,
    ];

    public function __construct(private readonly MarketingAppointmentAuthorizationService $agendaAuthz)
    {
    }

    public function isFull(?Admin $admin): bool
    {
        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return false;
        }

        return in_array($this->role($admin), self::FULL_ROLES, true);
    }

    public function isCommercial(?Admin $admin): bool
    {
        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return false;
        }

        return in_array($this->role($admin), self::COMMERCIAL_ROLES, true);
    }

    /** @return array<string,bool> */
    public function capabilities(?Admin $admin): array
    {
        $caps = array_fill_keys(self::ALL_CAPS, false);
        if ($this->isFull($admin)) {
            return array_fill_keys(self::ALL_CAPS, true);
        }
        if ($this->isCommercial($admin)) {
            $caps[self::CAP_VIEW] = true;
            $caps[self::CAP_RECOMMEND] = true;
            $caps[self::CAP_EXECUTE] = true;   // limitado por tipo (canExecuteType)
            $caps[self::CAP_REJECT] = true;    // sobre acciones propias
            $caps[self::CAP_CANCEL] = true;    // sobre acciones propias
            // approve queda para FULL.
        }

        return $caps;
    }

    public function can(?Admin $admin, string $capability): bool
    {
        return $this->capabilities($admin)[$capability] ?? false;
    }

    /**
     * @return array{status:int,code:string,message:string}|null
     */
    public function deny(?Admin $admin, string $capability): ?array
    {
        if (! $admin instanceof Admin) {
            return ['status' => 401, 'code' => 'agent_actions_requires_admin', 'message' => 'Requiere sesión de administrador.'];
        }
        if (! $admin->isActive()) {
            return ['status' => 403, 'code' => 'agent_actions_admin_inactive', 'message' => 'Tu cuenta no está activa.'];
        }
        if (! $this->can($admin, $capability)) {
            return ['status' => 403, 'code' => 'agent_actions_forbidden', 'message' => 'No tienes permiso para esta acción.'];
        }

        return null;
    }

    /** ¿Puede ejecutar este tipo concreto de acción? */
    public function canExecuteType(?Admin $admin, string $actionType): bool
    {
        if ($this->isFull($admin)) {
            return in_array($actionType, MarketingAgentAction::TYPES, true);
        }
        if ($this->isCommercial($admin)) {
            if ($actionType === MarketingAgentAction::TYPE_CREATE_APPOINTMENT) {
                return $this->agendaAuthz->can($admin, MarketingAppointmentAuthorizationService::CAP_CREATE);
            }

            return in_array($actionType, self::COMMERCIAL_EXECUTABLE_TYPES, true)
                && ! in_array($actionType, self::FULL_ONLY_TYPES, true);
        }

        return false;
    }

    /** Ownership sobre la conversación de la acción (FULL: todas). */
    public function ownsConversation(?Admin $admin, ?MarketingConversation $conversation): bool
    {
        if ($this->isFull($admin)) {
            return true;
        }
        if (! $admin instanceof Admin) {
            return false;
        }
        if ($conversation === null) {
            return true; // sin conversación vinculada, no hay restricción de ownership
        }
        $assignee = $conversation->assigned_to_admin_id;

        return $assignee === null || (int) $assignee === (int) $admin->id;
    }

    /** @return array<string,bool> */
    public function frontendCapabilities(?Admin $admin): array
    {
        $out = [];
        foreach ($this->capabilities($admin) as $cap => $allowed) {
            $out['can_'.$cap] = $allowed;
        }
        // Tipos ejecutables (para que el front muestre/oculte botones).
        $out['executable_types'] = array_values(array_filter(
            MarketingAgentAction::TYPES,
            fn ($t) => $this->canExecuteType($admin, $t),
        ));

        return $out;
    }

    private function role(Admin $admin): string
    {
        return mb_strtolower(trim((string) $admin->role));
    }
}
