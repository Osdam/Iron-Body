<?php

namespace App\Services\Marketing;

use App\Models\Admin;

/**
 * Autorización del Inbox CRM de WhatsApp. Capa senior, simple y ampliable basada
 * en `admins.role` + `admins.status` (sin un sistema de permisos tipo Spatie).
 *
 * Reglas base:
 *  - Solo admins con status='active' pueden usar el Inbox.
 *  - Un actor sin admin resuelto (p. ej. el secreto compartido de automatización)
 *    NO opera el Inbox: es una superficie de operadores humanos → 401.
 *  - Cada acción exige una capacidad concreta; si el rol no la concede → 403.
 *
 * Matriz inicial (ampliable agregando roles a los grupos):
 *  - FULL        (Super Admin, Administrador): todas las capacidades.
 *  - COMMERCIAL  (Asesor Comercial, Asesor, Ventas, Recepción): todo MENOS asignar
 *    a terceros (assign).
 *  - Cualquier otro rol activo: bloqueado (sin capacidades).
 */
class MarketingInboxAuthorizationService
{
    public const CAP_VIEW = 'view';
    public const CAP_VIEW_METRICS = 'view_metrics';
    public const CAP_REPLY = 'reply';
    public const CAP_TAKEOVER = 'takeover';
    public const CAP_RELEASE = 'release';
    public const CAP_ASSIGN = 'assign';
    public const CAP_NOTE = 'note';
    public const CAP_TAG = 'tag';
    public const CAP_RESOLVE_REVIEW = 'resolve_review';
    public const CAP_UPDATE_STATUS = 'update_status';

    public const ALL_CAPS = [
        self::CAP_VIEW,
        self::CAP_VIEW_METRICS,
        self::CAP_REPLY,
        self::CAP_TAKEOVER,
        self::CAP_RELEASE,
        self::CAP_ASSIGN,
        self::CAP_NOTE,
        self::CAP_TAG,
        self::CAP_RESOLVE_REVIEW,
        self::CAP_UPDATE_STATUS,
    ];

    /** Roles con acceso total (normalizados a minúsculas, sin acentos relevantes). */
    private const FULL_ROLES = ['super admin', 'administrador', 'admin'];

    /** Roles comerciales: operan conversaciones, pero no asignan a terceros. */
    private const COMMERCIAL_ROLES = [
        'asesor comercial', 'asesor', 'ventas', 'recepción', 'recepcion',
    ];

    /**
     * Mapa de capacidades efectivas del admin. Todas false si no hay admin, no
     * está activo, o su rol no está contemplado.
     *
     * @return array<string,bool>
     */
    public function capabilities(?Admin $admin): array
    {
        $caps = array_fill_keys(self::ALL_CAPS, false);

        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return $caps;
        }

        $role = $this->normalizeRole($admin->role);

        if (in_array($role, self::FULL_ROLES, true)) {
            return array_fill_keys(self::ALL_CAPS, true);
        }

        if (in_array($role, self::COMMERCIAL_ROLES, true)) {
            foreach (self::ALL_CAPS as $cap) {
                $caps[$cap] = $cap !== self::CAP_ASSIGN; // todo menos asignar
            }

            return $caps;
        }

        return $caps; // rol no contemplado → bloqueado
    }

    /** ¿El admin puede ejecutar la capacidad indicada? */
    public function can(?Admin $admin, string $capability): bool
    {
        return $this->capabilities($admin)[$capability] ?? false;
    }

    /**
     * Decide el rechazo de una acción. Devuelve null si está permitida, o un
     * arreglo {status, code, message} para responder 401/403.
     *
     * @return array{status:int,code:string,message:string}|null
     */
    public function deny(?Admin $admin, string $capability): ?array
    {
        if (! $admin instanceof Admin) {
            return [
                'status'  => 401,
                'code'    => 'inbox_requires_admin',
                'message' => 'El Inbox requiere una sesión de administrador.',
            ];
        }

        if (! $admin->isActive()) {
            return [
                'status'  => 403,
                'code'    => 'inbox_admin_inactive',
                'message' => 'Tu cuenta no está activa.',
            ];
        }

        if (! $this->can($admin, $capability)) {
            return [
                'status'  => 403,
                'code'    => 'inbox_forbidden',
                'message' => 'No tienes permiso para esta acción del Inbox.',
            ];
        }

        return null;
    }

    /**
     * Capacidades en formato `can_*` para el frontend (oculta/deshabilita botones).
     *
     * @return array<string,bool>
     */
    public function frontendCapabilities(?Admin $admin): array
    {
        $caps = $this->capabilities($admin);
        $out = [];
        foreach ($caps as $cap => $allowed) {
            $out['can_'.$cap] = $allowed;
        }

        return $out;
    }

    private function normalizeRole(?string $role): string
    {
        return mb_strtolower(trim((string) $role));
    }
}
