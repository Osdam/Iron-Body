<?php

namespace App\Services\Meta;

use App\Models\Admin;

/**
 * Quién puede ver y quién puede tocar la conexión de WhatsApp Business.
 *
 * Dos permisos distintos a propósito:
 *
 *  - VER lo puede cualquier admin activo. Saber si el canal está conectado es
 *    contexto que necesita quien atiende: explica por qué una respuesta no sale.
 *  - CONECTAR y DESCONECTAR exige un rol pleno (Super Admin / Administrador).
 *    Conectar vincula la cuenta de WhatsApp del negocio a esta aplicación y
 *    desconectar puede dejar al equipo sin canal de salida. Eso no es una
 *    decisión de atención al cliente.
 *
 * Y en las dos hace falta una sesión de administrador REAL. El secreto
 * compartido de automatizaciones (`ADMIN_API_TOKEN`) NO sirve aquí: el
 * onboarding de Meta lo ejecuta una persona identificada delante de un
 * navegador, y la fila que se guarda registra quién fue. Un token de máquina no
 * tiene nombre que registrar.
 */
class WhatsappIntegrationAuthorizationService
{
    public const CAP_VIEW = 'view';

    public const CAP_CONNECT = 'connect';

    public const CAP_DISCONNECT = 'disconnect';

    /** Roles con potestad sobre las integraciones del negocio. */
    private const FULL_ROLES = ['super admin', 'administrador', 'admin'];

    /**
     * @return array<string,bool>
     */
    public function capabilities(?Admin $admin): array
    {
        $caps = [
            self::CAP_VIEW => false,
            self::CAP_CONNECT => false,
            self::CAP_DISCONNECT => false,
        ];

        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return $caps;
        }

        $caps[self::CAP_VIEW] = true;

        if (in_array(mb_strtolower(trim((string) $admin->role)), self::FULL_ROLES, true)) {
            $caps[self::CAP_CONNECT] = true;
            $caps[self::CAP_DISCONNECT] = true;
        }

        return $caps;
    }

    public function can(?Admin $admin, string $capability): bool
    {
        return $this->capabilities($admin)[$capability] ?? false;
    }

    /**
     * Rechazo de una acción, o null si está permitida.
     *
     * @return array{status:int,code:string,message:string}|null
     */
    public function deny(?Admin $admin, string $capability): ?array
    {
        if (! $admin instanceof Admin) {
            return [
                'status' => 401,
                'code' => 'integration_requires_admin',
                'message' => 'Esta acción requiere una sesión de administrador.',
            ];
        }

        if (! $admin->isActive()) {
            return [
                'status' => 403,
                'code' => 'integration_admin_inactive',
                'message' => 'Tu cuenta no está activa.',
            ];
        }

        if (! $this->can($admin, $capability)) {
            return [
                'status' => 403,
                'code' => 'integration_forbidden',
                'message' => 'Solo un administrador puede conectar o desconectar WhatsApp Business.',
            ];
        }

        return null;
    }

    /** Capacidades en formato `can_*` para que el CRM oculte botones. */
    public function frontendCapabilities(?Admin $admin): array
    {
        $out = [];
        foreach ($this->capabilities($admin) as $cap => $allowed) {
            $out['can_'.$cap] = $allowed;
        }

        return $out;
    }
}
