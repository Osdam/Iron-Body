<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingAppointment;

/**
 * Autorización de la Agenda comercial (Fase 4B). Misma filosofía que el Inbox:
 * capa simple y ampliable basada en `admins.role` + `admins.status`.
 *
 * Reglas:
 *  - Solo admins activos operan la agenda.
 *  - FULL (Super Admin / Administrador): ven y operan TODAS las citas, asignan.
 *  - COMMERCIAL (Asesor / Ventas / Recepción): ven y operan citas propias o sin
 *    asignar; pueden crear; NO asignan a terceros.
 *  - Otros roles / inactivos: bloqueados (403).
 */
class MarketingAppointmentAuthorizationService
{
    public const CAP_VIEW = 'view';
    public const CAP_CREATE = 'create';
    public const CAP_UPDATE = 'update';
    public const CAP_ASSIGN = 'assign';
    public const CAP_COMPLETE = 'complete';
    public const CAP_CANCEL = 'cancel';
    public const CAP_RESCHEDULE = 'reschedule';

    public const ALL_CAPS = [
        self::CAP_VIEW, self::CAP_CREATE, self::CAP_UPDATE, self::CAP_ASSIGN,
        self::CAP_COMPLETE, self::CAP_CANCEL, self::CAP_RESCHEDULE,
    ];

    private const FULL_ROLES = ['super admin', 'administrador', 'admin'];

    private const COMMERCIAL_ROLES = [
        'asesor comercial', 'asesor', 'ventas', 'recepción', 'recepcion',
    ];

    /** @return array<string,bool> */
    public function capabilities(?Admin $admin): array
    {
        $caps = array_fill_keys(self::ALL_CAPS, false);

        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return $caps;
        }

        $role = mb_strtolower(trim((string) $admin->role));

        if (in_array($role, self::FULL_ROLES, true)) {
            return array_fill_keys(self::ALL_CAPS, true);
        }

        if (in_array($role, self::COMMERCIAL_ROLES, true)) {
            foreach (self::ALL_CAPS as $cap) {
                $caps[$cap] = $cap !== self::CAP_ASSIGN; // todo menos asignar a terceros
            }

            return $caps;
        }

        return $caps;
    }

    public function isFull(?Admin $admin): bool
    {
        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return false;
        }

        return in_array(mb_strtolower(trim((string) $admin->role)), self::FULL_ROLES, true);
    }

    public function can(?Admin $admin, string $capability): bool
    {
        return $this->capabilities($admin)[$capability] ?? false;
    }

    /**
     * Rechazo base por capacidad (sin ownership). Devuelve null si permitido.
     *
     * @return array{status:int,code:string,message:string}|null
     */
    public function deny(?Admin $admin, string $capability): ?array
    {
        if (! $admin instanceof Admin) {
            return ['status' => 401, 'code' => 'appointments_requires_admin', 'message' => 'La agenda requiere una sesión de administrador.'];
        }
        if (! $admin->isActive()) {
            return ['status' => 403, 'code' => 'appointments_admin_inactive', 'message' => 'Tu cuenta no está activa.'];
        }
        if (! $this->can($admin, $capability)) {
            return ['status' => 403, 'code' => 'appointments_forbidden', 'message' => 'No tienes permiso para esta acción de la agenda.'];
        }

        return null;
    }

    /**
     * ¿El admin puede operar (editar/completar/cancelar/reprogramar) ESTA cita?
     * FULL: cualquiera. COMMERCIAL: solo propias o sin asignar.
     */
    public function ownsOrUnassigned(?Admin $admin, MarketingAppointment $appointment): bool
    {
        if ($this->isFull($admin)) {
            return true;
        }
        if (! $admin instanceof Admin) {
            return false;
        }
        $assignee = $appointment->assigned_to_admin_id;

        return $assignee === null || (int) $assignee === (int) $admin->id;
    }

    /** @return array<string,bool> */
    public function frontendCapabilities(?Admin $admin): array
    {
        $out = [];
        foreach ($this->capabilities($admin) as $cap => $allowed) {
            $out['can_'.$cap] = $allowed;
        }

        return $out;
    }
}
